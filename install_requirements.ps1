# Chasha Bhai - one-time requirements installer for Windows 10/11
# Installs PHP 8.4 + Python 3.13 (via winget) if missing, configures PHP,
# and installs the satellite service's Python packages.
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
function Step($m){ Write-Host "`n==> $m" -ForegroundColor Cyan }
function Ok($m){ Write-Host "    OK  $m" -ForegroundColor Green }
function Warn($m){ Write-Host "    !!  $m" -ForegroundColor Yellow }
function Refresh-Path {
  $env:Path = [Environment]::GetEnvironmentVariable('Path','Machine') + ';' + [Environment]::GetEnvironmentVariable('Path','User') + ';' + "$env:LOCALAPPDATA\Microsoft\WinGet\Links"
}
function Has($cmd){
  $c = Get-Command $cmd -ErrorAction SilentlyContinue
  if (-not $c) { return $false }
  if ($c.Source -like '*WindowsApps*') { return $false }   # Microsoft Store stub, not a real install
  return $true
}

Step "Checking winget (Windows Package Manager)"
if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
  Warn "winget not found. Install 'App Installer' from the Microsoft Store, then run this again."
  Start-Process "ms-windows-store://pdp/?productid=9NBLGGH4NNS1"; exit 1
}
Ok "winget found"

Step "PHP"
Refresh-Path
if (-not (Has 'php')) {
  winget install --id PHP.PHP.8.4 -e --accept-source-agreements --accept-package-agreements --silent
  Refresh-Path
  if (-not (Has 'php')) { Warn "PHP installed but not on PATH yet. Close this window and run the installer again."; exit 1 }
}
Ok (php -r "echo 'PHP '.PHP_VERSION;")

Step "Configuring PHP (extensions, HTTPS certificates, time limit)"
$phpDir = Split-Path -Parent (Get-Command php).Source
# winget's php.exe may be a link; resolve the real folder
$real = php -r "echo dirname(PHP_BINARY);"
if ($real) { $phpDir = $real }
$ini = php -r "echo php_ini_loaded_file();"
if (-not $ini) {
  $ini = Join-Path $phpDir 'php.ini'
  $src = @('php.ini-production','php.ini-development') | % { Join-Path $phpDir $_ } | ? { Test-Path $_ } | Select -First 1
  if ($src) { Copy-Item $src $ini } else { Set-Content $ini "" -Encoding ASCII }
}
if (-not (Test-Path "$ini.bak_chasha")) { Copy-Item $ini "$ini.bak_chasha" }
$lines = Get-Content $ini
$lines = $lines -replace '^\s*;\s*extension_dir\s*=\s*"ext"\s*$','extension_dir = "ext"'
foreach ($ext in 'curl','openssl','mbstring','fileinfo','gd') {
  if ($lines -match "^\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$") { continue }
  if ($lines -match "^\s*;\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$") {
    $lines = $lines -replace "^\s*;\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$","extension=$ext"
  } else { $lines += "extension=$ext" }
}
if (-not ($lines -match '^\s*extension_dir\s*=')) { $lines += 'extension_dir = "ext"' }
$lines = $lines -replace '^\s*max_execution_time\s*=.*','max_execution_time = 180'
$pem = Join-Path $phpDir 'cacert.pem'
try { Invoke-WebRequest https://curl.se/ca/cacert.pem -OutFile $pem -UseBasicParsing } catch { Warn "Could not download certificates: $_" }
$lines = $lines | ? { $_ -notmatch '^\s*(curl\.cainfo|openssl\.cafile)\s*=' }
if (Test-Path $pem) { $lines += "curl.cainfo = `"$pem`""; $lines += "openssl.cafile = `"$pem`"" }
Set-Content $ini $lines -Encoding ASCII
$mods = (php -m) -join ' '
foreach ($ext in 'curl','openssl','mbstring','fileinfo','gd') {
  if ($mods -match "(?i)\b$ext\b") { Ok "php $ext" } else { Warn "php $ext NOT loaded (check $ini)" }
}

Step "Python"
$py = $null
if (Has 'py') { $py = 'py' } elseif (Has 'python') { $py = 'python' }
if (-not $py) {
  winget install --id Python.Python.3.13 -e --accept-source-agreements --accept-package-agreements --silent
  Refresh-Path
  if (Has 'py') { $py = 'py' } elseif (Has 'python') { $py = 'python' }
  if (-not $py) { Warn "Python installed but not on PATH yet. Close this window and run the installer again."; exit 1 }
}
Ok (& $py --version)

Step "Satellite service packages (first time can take 2-5 minutes)"
$sat = Join-Path $Root 'services\satellite'
if (-not (Test-Path "$sat\.venv\Scripts\python.exe")) { & $py -m venv "$sat\.venv" }
& "$sat\.venv\Scripts\python.exe" -m pip install --upgrade pip -q
& "$sat\.venv\Scripts\python.exe" -m pip install -r "$sat\requirements.txt"
Ok "satellite packages installed"

Step "App configuration"
$cfg = Join-Path $Root 'api\config.local.php'
if (-not (Test-Path $cfg)) {
  $t = [IO.File]::ReadAllText((Join-Path $Root 'api\config.sample.php'))
  [IO.File]::WriteAllText($cfg, $t, (New-Object Text.UTF8Encoding $false))
  Warn "Created api\config.local.php - open it and paste your xAI key into GROK_API_KEY."
}
$t = [IO.File]::ReadAllText($cfg)
$t2 = $t -replace "'SAT_SERVICE_URL'\s*=>\s*''", "'SAT_SERVICE_URL'       => 'http://127.0.0.1:8001'"
if ($t2 -ne $t) { [IO.File]::WriteAllText($cfg, $t2, (New-Object Text.UTF8Encoding $false)) }
if ($t2 -match "'GROK_API_KEY'\s*=>\s*''") { Warn "GROK_API_KEY is empty in api\config.local.php - AI features need it." } else { Ok "API key present" }
Ok "satellite service set to http://127.0.0.1:8001"

Write-Host "`nAll done! Double-click START_CHASHA_BHAI.bat to run the app." -ForegroundColor Green

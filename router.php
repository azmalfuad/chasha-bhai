<?php
// Local dev router for `php -S localhost:8080 router.php` — mirrors the .htaccess deny rules.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(data|api/lib|services|eval|mcp)(/|$)|/config(\.local|\.sample)?\.php$|/bootstrap\.php$|^/router\.php$|/\.|\.(md|py|jsonl|log|sh)$#i', $path)) {
    http_response_code(403); echo 'Forbidden'; return true;
}
if (preg_match('#(^|/)test\.php$#i', $path) || ($path !== '/' && !file_exists(__DIR__ . $path))) { http_response_code(404); echo 'Not found'; return true; }
if ($path === '/docs' ) { header('Location: /docs/'); return true; }
return false;

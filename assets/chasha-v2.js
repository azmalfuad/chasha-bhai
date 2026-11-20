/* চাষা ভাই v2 — satellite field health, Krishi Copilot (voice), community outbreak map, PWA/offline. */
(function () {
  const API = './api';
  const { bn, esc } = CB;
  const toast = (...a) => window.showToast && window.showToast(...a);
  const home = () => { const l = CB.getLoc(); return l ? [l.lat, l.lon] : [23.81, 90.41]; };

  // reveal for new glass cards
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } }), { threshold: 0.1 });
  document.querySelectorAll('.reveal-v2').forEach(el => io.observe(el));

  // ======================= SATELLITE =======================
  let satMap = null, pts = [], markers = [], poly = null, overlay = null;
  function initSatMap() {
    if (satMap || !window.L) return;
    satMap = L.map('satMap', { zoomControl: true, attributionControl: true }).setView(home(), 15); satMap.attributionControl.setPrefix(false);
    const esri = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '© Esri' });
    const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' });
    esri.addTo(satMap);
    L.control.layers({ 'স্যাটেলাইট (Esri)': esri, 'রাস্তা (OSM)': osm }, null, { position: 'topright' }).addTo(satMap);
    satMap.on('click', e => { pts.push([e.latlng.lat, e.latlng.lng]); drawField(); });
  }
  function drawField() {
    markers.forEach(m => m.remove()); markers = [];
    if (poly) { poly.remove(); poly = null; }
    pts.forEach(p => markers.push(L.circleMarker(p, { radius: 5, color: '#fff', weight: 2, fillColor: '#c8924a', fillOpacity: 1 }).addTo(satMap)));
    if (pts.length >= 3) poly = L.polygon(pts, { color: '#ebd232', weight: 2, fillOpacity: 0.12 }).addTo(satMap);
    else if (pts.length === 1) poly = L.circle(pts[0], { radius: 50, color: '#ebd232', weight: 2, fillOpacity: 0.12 }).addTo(satMap);
    document.getElementById('satRun').disabled = pts.length === 0 || pts.length === 2;
    document.getElementById('satHint').textContent = pts.length === 0 ? 'জমির কোণাগুলোতে একে একে ট্যাপ করুন, অথবা এক জায়গায় ট্যাপ করুন (৫০ মি বৃত্ত)।'
      : pts.length === 1 ? 'একটি বিন্দু: ৫০ মি বৃত্ত বিশ্লেষণ হবে। আরও কোণা যোগ করতে পারেন।'
      : pts.length === 2 ? 'আরও অন্তত একটি কোণা দিন।' : `${bn(pts.length)}টি কোণা — “বিশ্লেষণ করুন” চাপুন।`;
  }
  document.getElementById('satUndo').onclick = () => { pts.pop(); drawField(); };
  document.getElementById('satClear').onclick = () => { pts = []; drawField(); if (overlay) { overlay.remove(); overlay = null; } };
  document.getElementById('satGps').onclick = () => CB.locate().then(c => { satMap.setView([c.lat, c.lon], 17); }).catch(e => toast('error', 'অবস্থান পাওয়া যায়নি', e.message));
  document.addEventListener('cb:loc', e => { if (satMap && pts.length === 0 && e.detail) satMap.setView([e.detail.lat, e.detail.lon], 15); });
  document.getElementById('satRun').onclick = runSat;
  document.getElementById('satToScan').onclick = () => document.getElementById('upload').scrollIntoView({ behavior: 'smooth' });

  async function runSat() {
    const body = document.getElementById('satBody'), load = document.getElementById('satLoading');
    document.getElementById('satEmpty').hidden = true; body.hidden = true; load.hidden = false;
    document.getElementById('satRun').disabled = true;
    try {
      let res;
      if (pts.length === 1) res = await fetch(`${API}/ndvi.php?lat=${pts[0][0]}&lon=${pts[0][1]}&radius_m=50&days=120`);
      else {
        const ring = pts.map(p => [p[1], p[0]]); ring.push(ring[0]);
        res = await fetch(`${API}/ndvi.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ geometry: { type: 'Polygon', coordinates: [ring] }, days: 120 }) });
      }
      const d = await res.json();
      if (!res.ok) throw new Error(d.error || ('HTTP ' + res.status));
      CB.saveLast('ndvi', d);
      renderSat(d);
    } catch (e) {
      document.getElementById('satEmpty').hidden = false;
      document.getElementById('satEmpty').textContent = 'বিশ্লেষণ ব্যর্থ: ' + e.message;
      toast('error', 'স্যাটেলাইট বিশ্লেষণ ব্যর্থ', e.message);
    } finally { load.hidden = true; document.getElementById('satRun').disabled = pts.length === 0; }
  }

  // Plain-Bangla greenness for farmers (NDVI kept only as small print for experts/judges)
  function green(v) {
    if (v == null || isNaN(v)) return { t: '—', e: '' };
    if (v >= 0.6) return { t: 'খুব ভালো', e: '🟢' };
    if (v >= 0.45) return { t: 'ভালো', e: '🟢' };
    if (v >= 0.3) return { t: 'মাঝারি', e: '🟡' };
    if (v >= 0.15) return { t: 'দুর্বল', e: '🟠' };
    return { t: 'ফসল নেই / খালি মাটি', e: '🟤' };
  }
  const score = v => bn(Math.round(Math.max(0, Math.min(1, v)) * 100));
  function renderSat(d) {
    const it = d.interpretation || {};
    document.getElementById('satBody').hidden = false;
    if (it.status !== 'ok') {
      document.getElementById('satNdvi').textContent = '—'; document.getElementById('satScore').textContent = '';
      document.getElementById('satClass').textContent = it.explanation_bn || '';
      document.getElementById('satLevel').textContent = 'তথ্য নেই';
      CB.field = null;
    } else {
      { const g = green(it.latest_ndvi); document.getElementById('satNdvi').textContent = `${g.e} ${g.t}`; document.getElementById('satScore').textContent = `সবুজতা স্কোর ${score(it.latest_ndvi)}/১০০ · (NDVI ${it.latest_ndvi.toFixed(2)})`; }
      document.getElementById('satDate').textContent = new Date(it.latest_date).toLocaleDateString('bn-BD', { day: 'numeric', month: 'long' });
      document.getElementById('satClass').textContent = it.canopy_class_bn + (it.baseline_ndvi != null ? ` · আগে ছিল: ${green(it.baseline_ndvi).t} (${score(it.baseline_ndvi)}/১০০)` : '');
      const lv = document.getElementById('satLevel');
      lv.textContent = { HIGH: '⚠️ ফসল খারাপ হচ্ছে', MEDIUM: 'একটু খেয়াল রাখুন', LOW: '✅ ফসল ঠিক আছে' }[it.level] || it.level;
      lv.className = 'pill ' + (it.level === 'HIGH' ? 'red' : it.level === 'MEDIUM' ? 'amber' : '');
      CB.field = { level: it.level, attribution: it.attribution, latest_ndvi: it.latest_ndvi, baseline_ndvi: it.baseline_ndvi, date: it.latest_date };
    }
    document.getElementById('satAttr').textContent = it.attribution === 'field_specific' ? 'শুধু আপনার জমিতে সমস্যা' : it.attribution === 'regional' ? 'পুরো এলাকায় একই (মৌসুমি)' : `আশেপাশের জমি: ${it.neighbour_ndvi != null ? green(it.neighbour_ndvi).t : '—'}`;
    document.getElementById('satArea').textContent = `${bn(d.field_area_ha)} হেক্টর · ${bn(d.scenes_checked)} ছবি`;
    const fl = document.getElementById('satFlags'); fl.innerHTML = '';
    (it.flags_bn || []).forEach(t => { const li = document.createElement('li'); li.textContent = t; fl.appendChild(li); });
    if (!(it.flags_bn || []).length && it.status === 'ok') fl.innerHTML = '<li>সবুজতায় উল্লেখযোগ্য পতন নেই।</li>';
    document.getElementById('satCaveat').textContent = it.caveat_bn || '';
    const th = document.getElementById('satThumb');
    if (d.ndvi_thumbnail_png) { th.src = d.ndvi_thumbnail_png; th.hidden = false; } else th.hidden = true;
    // overlay the NDVI raster on the map, aligned to the field bbox (approximate: UTM grid ~ north-up)
    if (overlay) { overlay.remove(); overlay = null; }
    if (d.ndvi_thumbnail_png && poly) { overlay = L.imageOverlay(d.ndvi_thumbnail_png, poly.getBounds(), { opacity: 0.75 }).addTo(satMap); satMap.fitBounds(poly.getBounds(), { maxZoom: 18, padding: [30, 30] }); }
    spark(d.series || []);
    document.getElementById('satSource').textContent = `${d.source} · ${d.license} · ${d.resolution_m} m · তৈরি ${new Date(d.generated_at).toLocaleString('bn-BD')} · ${d.elapsed_ms} ms${d.cached || d.proxy_cache ? ' (cache)' : ''}`;
    CB.renderFusion && CB.lastDiag && null;
    toast('success', 'স্যাটেলাইট বিশ্লেষণ সম্পন্ন', 'এখন পাতার ছবি স্ক্যান করলে স্যাটেলাইট তথ্যও যাচাইয়ে যোগ হবে।');
  }

  function spark(series) {
    const svg = document.getElementById('satSpark');
    const ok = series.filter(s => s.usable).slice().reverse();
    if (ok.length < 2) { svg.innerHTML = '<text x="10" y="60" font-size="12" fill="#5b6b57">ট্রেন্ড দেখাতে অন্তত ২টি মেঘমুক্ত ছবি দরকার</text>'; return; }
    const t0 = new Date(ok[0].date).getTime(), t1 = new Date(ok[ok.length - 1].date).getTime() || t0 + 1;
    const X = s => 10 + 270 * (new Date(s.date).getTime() - t0) / Math.max(1, t1 - t0);
    const Y = v => 110 - 100 * Math.max(0, Math.min(1, v));
    const line = (key, color, dash) => { const p = ok.filter(s => s[key] != null).map(s => `${X(s).toFixed(1)},${Y(s[key]).toFixed(1)}`); return p.length > 1 ? `<polyline points="${p.join(' ')}" fill="none" stroke="${color}" stroke-width="2.4" ${dash ? 'stroke-dasharray="5 4"' : ''} vector-effect="non-scaling-stroke"/>` : ''; };
    const dots = ok.map(s => `<circle cx="${X(s).toFixed(1)}" cy="${Y(s.ndvi).toFixed(1)}" r="3" fill="#4f6b48"><title>${s.date}: সবুজতা ${score(s.ndvi)}/১০০</title></circle>`).join('');
    const grid = [0.2, 0.4, 0.6, 0.8].map(v => `<line x1="10" x2="282" y1="${Y(v)}" y2="${Y(v)}" stroke="rgba(42,53,40,.1)"/><text x="288" y="${Y(v) + 3}" font-size="8" fill="#5b6b57">${bn(Math.round(v * 100))}</text>`).join('');
    svg.innerHTML = grid + line('neighbour_ndvi', '#c8924a', true) + line('ndvi', '#4f6b48') + dots
      + `<text x="10" y="119" font-size="8" fill="#5b6b57">${ok[0].date}</text><text x="232" y="119" font-size="8" fill="#5b6b57">${ok[ok.length - 1].date}</text>`;
  }

  // lazy init maps when visible
  const mapIo = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { if (e.target.id === 'satMap') initSatMap(); else initObMap(); mapIo.unobserve(e.target); } }), { rootMargin: '200px' });
  mapIo.observe(document.getElementById('satMap')); mapIo.observe(document.getElementById('obMap'));

  // ======================= KRISHI COPILOT =======================
  const chat = document.getElementById('chat'), form = document.getElementById('chatForm'), input = document.getElementById('chatInput');
  const history = [];
  function addMsg(role, text, extra) {
    const m = document.createElement('div'); m.className = 'msg ' + (role === 'user' ? 'user' : 'bot');
    m.textContent = text.replace(/\*\*(.+?)\*\*/g, '$1');
    if (extra) m.appendChild(extra);
    chat.appendChild(m); chat.scrollTop = chat.scrollHeight; return m;
  }
  async function ask(q) {
    if (!q.trim()) return;
    addMsg('user', q); input.value = '';
    const wait = addMsg('bot', 'ভাবছি… (সূত্র খোঁজা হচ্ছে)');
    const l = CB.getLoc();
    const ctx = {};
    if (CB.lastDiag && CB.lastDiag.disease_detection) { ctx.last_diagnosis = `${CB.lastDiag.disease_detection.disease} (${CB.lastDiag.crop || ''}), confidence ${CB.lastDiag.fusion ? CB.lastDiag.fusion.final_confidence : ''}`; ctx.crop = CB.lastDiag.crop || ''; }
    if (CB.field) ctx.field_ndvi = `crop greenness score ${Math.round(CB.field.latest_ndvi * 100)}/100 (60+ = healthy dense crop); satellite problem-risk ${CB.field.level} (LOW = crop is fine, HIGH = greenness falling) ${CB.field.attribution === 'regional' ? '- neighbours changed too, likely seasonal' : CB.field.attribution === 'field_specific' ? '- only this field is falling' : ''}`;
    try {
      const r = await fetch(`${API}/copilot.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ question: q, history: history.slice(-6), lat: l && l.lat, lon: l && l.lon, context: ctx }) });
      const d = await r.json();
      wait.remove();
      if (!r.ok) throw new Error(d.error || 'HTTP ' + r.status);
      const extra = document.createElement('div');
      if ((d.citations || []).length) {
        const c = document.createElement('div'); c.className = 'cites';
        c.innerHTML = 'সূত্র: ' + d.citations.map(x => `[${x.n}] <a href="${esc(x.source.url)}" target="_blank" rel="noopener">${esc(x.title_bn)}</a>`).join(' · ');
        extra.appendChild(c);
      } else if (d.grounding_note_bn) { const c = document.createElement('div'); c.className = 'cites'; c.textContent = '⚠ ' + d.grounding_note_bn; extra.appendChild(c); }
      const meta = document.createElement('div'); meta.className = 'meta';
      meta.textContent = `${d.routing.model} · ${d.routing.reason} · tools: ${(d.agent_trace || []).map(t => t.tool).join(', ') || 'none'} · ${d.latency_ms} ms`;
      const sp = document.createElement('button'); sp.type = 'button'; sp.className = 'speak'; sp.textContent = '🔊 শুনুন';
      sp.onclick = () => { if (CB.speak(d.answer.replace(/\[\d+\]/g, '').replace(/\*\*/g, '')) === false) toast('info', 'বাংলা কণ্ঠ নেই', 'ফোনের Text-to-speech সেটিংসে বাংলা ভয়েস যোগ করুন।'); };
      extra.append(meta, sp);
      addMsg('bot', d.answer, extra);
      history.push({ role: 'user', content: q }, { role: 'assistant', content: d.answer });
      if (autoSpeak) { autoSpeak = false; sp.click(); }
    } catch (e) {
      wait.remove(); addMsg('bot', 'দুঃখিত, এখন উত্তর দিতে পারছি না (' + e.message + ')। জরুরি হলে ১৬১২৩-এ ফোন করুন।');
    }
  }
  form.addEventListener('submit', e => { e.preventDefault(); ask(input.value); });
  document.querySelectorAll('#chips .chip').forEach(c => c.addEventListener('click', () => ask(c.textContent)));

  // Voice input (Web Speech API, bn-BD). Works in Chrome/Android; hidden gracefully elsewhere.
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const mic = document.getElementById('micBtn'); let rec = null, autoSpeak = false;
  if (!SR) { mic.title = 'এই ব্রাউজারে ভয়েস ইনপুট নেই (Chrome ব্যবহার করুন)'; mic.style.opacity = .45; }
  mic.addEventListener('click', () => {
    if (!SR) { toast('info', 'ভয়েস ইনপুট নেই', 'Android-এ Chrome ব্রাউজারে ভয়েস ইনপুট কাজ করে।'); return; }
    if (rec) { rec.stop(); return; }
    rec = new SR(); rec.lang = 'bn-BD'; rec.interimResults = true; rec.maxAlternatives = 1;
    mic.classList.add('on');
    rec.onresult = e => { input.value = Array.from(e.results).map(r => r[0].transcript).join(''); if (e.results[e.results.length - 1].isFinal) { autoSpeak = true; ask(input.value); } };
    rec.onerror = e => toast('error', 'ভয়েস ত্রুটি', e.error === 'not-allowed' ? 'মাইক্রোফোনের অনুমতি দিন।' : e.error);
    rec.onend = () => { mic.classList.remove('on'); rec = null; };
    rec.start();
  });

  // ======================= OUTBREAK MAP =======================
  let obMap = null, obLayer = null;
  function initObMap() {
    if (obMap || !window.L) return;
    obMap = L.map('obMap', { zoomControl: true }).setView([23.7, 90.3], 7); obMap.attributionControl.setPrefix(false);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '© OSM' }).addTo(obMap);
    obLayer = L.layerGroup().addTo(obMap);
    CB.loadOutbreak();
  }
  CB.loadOutbreak = async function () {
    const list = document.getElementById('obList');
    try {
      const l = CB.getLoc();
      const r = await fetch(`${API}/outbreak.php?days=21${l ? `&lat=${l.lat}&lon=${l.lon}` : ''}`);
      const d = await r.json(); if (!r.ok) throw new Error(d.error);
      if (obLayer) obLayer.clearLayers();
      list.innerHTML = '';
      if (!d.cells.length) list.innerHTML = `<li class="muted">এখনো কোনো এলাকায় প্রকাশযোগ্য রিপোর্ট নেই। (মোট ${bn(d.total_reports)}টি রিপোর্ট; গোপনীয়তার জন্য ৩টির কম রিপোর্টের এলাকা লুকানো)</li>`;
      d.cells.forEach(c => {
        const color = c.alert === 'RISING' ? '#b66b4a' : c.alert === 'ACTIVE' ? '#c8924a' : '#7a9471';
        if (obLayer) L.circle([c.lat, c.lon], { radius: 5500, color, fillColor: color, fillOpacity: 0.35, weight: 1 }).bindPopup(`<b>${esc(c.top[0].disease)}</b><br>${bn(c.n)}টি রিপোর্ট (৭ দিনে ${bn(c.last7)})`).addTo(obLayer);
        const li = document.createElement('li');
        li.innerHTML = `<span><b>${esc(c.top.map(t => t.disease).join(', '))}</b><br><span class="muted">${c.lat.toFixed(1)}°, ${c.lon.toFixed(1)}° গ্রিড</span></span><span class="pill ${c.alert === 'RISING' ? 'red' : c.alert === 'ACTIVE' ? 'amber' : ''}">${{ RISING: 'বাড়ছে', ACTIVE: 'সক্রিয়', WATCH: 'নজরে' }[c.alert]} · ${bn(c.n)}</span>`;
        list.appendChild(li);
      });
      document.getElementById('obMeta').textContent = `গত ${bn(d.window_days)} দিন · মোট রিপোর্ট ${bn(d.total_reports)} · লুকানো গ্রিড ${bn(d.suppressed_cells)} · গ্রিড ${d.privacy.cell_size_deg}° · k=${d.privacy.k_anonymity}` + ((d.near_you || []).length ? ` · ⚠ আপনার ২৫ কিমির মধ্যে ${bn(d.near_you.length)}টি সক্রিয় এলাকা` : '');
    } catch (e) { list.innerHTML = '<li class="muted">সতর্কতা লোড করা যায়নি।</li>'; }
  };

  // ======================= PWA + OFFLINE =======================
  if ('serviceWorker' in navigator && location.protocol !== 'file:') navigator.serviceWorker.register('sw.js').catch(() => {});
  const banner = document.getElementById('offlineBanner');
  const setOnline = () => {
    banner.classList.toggle('show', !navigator.onLine);
    if (!navigator.onLine) { const w = CB.getLast('weather'); if (w) banner.textContent = `অফলাইন — শেষ তথ্য: ${new Date(w.t).toLocaleString('bn-BD')}`; }
  };
  addEventListener('online', setOnline); addEventListener('offline', setOnline); setOnline();
  let deferred = null; const ib = document.getElementById('installBtn');
  addEventListener('beforeinstallprompt', e => { e.preventDefault(); deferred = e; ib.classList.add('show'); });
  ib.addEventListener('click', async () => { if (!deferred) return; deferred.prompt(); await deferred.userChoice; deferred = null; ib.classList.remove('show'); });
  // restore last diagnosis (offline-first)
  const lastD = CB.getLast('diagnosis');
  if (lastD && lastD.data && lastD.data.fusion && Date.now() - lastD.t < 7 * 864e5) { try { renderDiseaseResults(lastD.data); } catch (e) {} }
  CB.updateLocChip();
})();

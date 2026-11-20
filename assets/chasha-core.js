/* চাষা ভাই v2 — core helpers shared by index.html scripts (loaded first). */
window.CB = (function () {
  const LS = 'chasha_v2_';
  const bn = s => String(s).replace(/\d/g, d => '০১২৩৪৫৬৭৮৯'[d]);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const CB = {
    field: null,     // last satellite summary (from /api/ndvi.php) for the Three-Eye check
    lastDiag: null,
    bn, esc,

    /** Downscale any image data URL to ≤ max px (JPEG). Saves image tokens + mobile data. */
    downscale(dataUrl, max = 1280, q = 0.85) {
      return new Promise(resolve => {
        const img = new Image();
        img.onload = () => {
          const s = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
          if (s === 1 && /^data:image\/jpeg/.test(dataUrl) && dataUrl.length < 2.5e6) return resolve(dataUrl);
          const c = document.createElement('canvas');
          c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
          c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
          resolve(c.toDataURL('image/jpeg', q));
        };
        img.onerror = () => resolve(dataUrl);
        img.src = dataUrl;
      });
    },

    getLoc() { try { return JSON.parse(localStorage.getItem(LS + 'loc')); } catch (e) { return null; } },
    setLoc(l) { try { localStorage.setItem(LS + 'loc', JSON.stringify(l)); } catch (e) {} CB.updateLocChip(); document.dispatchEvent(new CustomEvent('cb:loc', { detail: l })); },
    updateLocChip() { const el = document.getElementById('locChip'); const l = CB.getLoc(); if (el && l) el.textContent = l.name_bn || 'অবস্থান'; },
    saveLast(kind, data) { try { localStorage.setItem(LS + 'last_' + kind, JSON.stringify({ t: Date.now(), data })); } catch (e) {} },
    getLast(kind) { try { return JSON.parse(localStorage.getItem(LS + 'last_' + kind)); } catch (e) { return null; } },

    locate() {
      return new Promise((res, rej) => {
        if (!navigator.geolocation) return rej(new Error('এই ব্রাউজারে অবস্থান সুবিধা নেই।'));
        navigator.geolocation.getCurrentPosition(
          p => { const c = { lat: +p.coords.latitude.toFixed(5), lon: +p.coords.longitude.toFixed(5) };
            if (c.lat < 20.5 || c.lat > 26.8 || c.lon < 87.9 || c.lon > 92.8) return rej(new Error('আপনার অবস্থান বাংলাদেশের বাইরে মনে হচ্ছে।'));
            res(c); },
          e => rej(new Error(e.code === 1 ? 'অবস্থানের অনুমতি দেওয়া হয়নি।' : 'অবস্থান পাওয়া যায়নি।')),
          { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
      });
    },

    diseasePayload(image) {
      const p = { image };
      const crop = document.getElementById('cropHint'); if (crop && crop.value) p.crop_hint = crop.value;
      const useLoc = document.getElementById('useLoc'); const l = CB.getLoc();
      if (useLoc && useLoc.checked && l) { p.lat = l.lat; p.lon = l.lon; }
      if (CB.field) p.field = CB.field;
      const share = document.getElementById('shareAnon'); if (share && share.checked) p.share_anonymous = true;
      return p;
    },

    /** Speak Bangla text with the Web Speech API (bn-BD voice if the device has one). */
    speak(text) {
      if (!('speechSynthesis' in window)) { alert('এই ব্রাউজারে পড়ে শোনানোর সুবিধা নেই।'); return; }
      speechSynthesis.cancel();
      const u = new SpeechSynthesisUtterance(text);
      const voices = speechSynthesis.getVoices();
      const v = voices.find(v => /bn[-_]BD/i.test(v.lang)) || voices.find(v => /^bn/i.test(v.lang));
      u.lang = v ? v.lang : 'bn-BD'; if (v) u.voice = v; u.rate = 0.95;
      speechSynthesis.speak(u);
      return !!v;
    },

    renderFusion(data) {
      CB.lastDiag = data;
      const f = data.fusion; const card = document.getElementById('fusionCard');
      if (!f || !card) return;
      card.hidden = false; card.classList.add('visible');
      const d = data.disease_detection || {};
      const pct = Math.round((f.final_confidence || 0) * 100);
      document.getElementById('fConf').textContent = bn(pct) + '%';
      const band = document.getElementById('fBand'); band.textContent = 'নির্ভরযোগ্যতা: ' + (f.confidence_band_bn || '—');
      band.className = 'pill ' + (pct >= 75 ? '' : pct >= 50 ? 'amber' : 'red');
      const agreeTxt = { corroborated: '✓ আবহাওয়া/স্যাটেলাইট সমর্থন করে', weather_disagrees: '⚠ আবহাওয়া মেলে না', photo_only: 'শুধু ছবির ভিত্তিতে', 'n/a': 'রোগ দাবি নেই' }[f.agreement] || f.agreement;
      document.getElementById('fAgree').textContent = agreeTxt;
      document.getElementById('fCrop').textContent = 'ফসল: ' + (data.crop_bn || data.crop || '—');
      const bar = document.getElementById('fBar'); bar.style.width = pct + '%';
      bar.parentElement.className = 'bar ' + (pct >= 75 ? '' : pct >= 50 ? 'amber' : 'red');
      const setEye = (id, used, val, text) => {
        const el = document.getElementById(id); el.classList.toggle('off', !used);
        el.querySelector('.bar i').style.width = Math.round(Math.max(0, Math.min(1, val)) * 100) + '%';
        el.querySelector('p').textContent = text;
      };
      setEye('eyePhoto', true, f.photo_confidence, `ছবির আস্থা ${bn(Math.round(f.photo_confidence * 100))}% · ${data.image_quality && data.image_quality.issue_bn || ''}`);
      setEye('eyeWeather', f.weather.used, (f.weather.support + 1) / 2, f.weather.used ? `সমর্থন ${f.weather.support > 0 ? '+' : ''}${f.weather.support} · ${f.weather.why_en}` : 'অবস্থান দেওয়া হয়নি — “আবহাওয়া দিয়ে যাচাই” চালু করুন।');
      setEye('eyeSat', f.satellite.used, (f.satellite.support + 1) / 2, f.satellite.used ? `সমর্থন +${f.satellite.support} · ${f.satellite.why_en}` : 'জমি আঁকা হয়নি — নিচের স্যাটেলাইট অংশে জমি চিহ্নিত করুন।');
      const why = document.getElementById('fWhy'); why.innerHTML = '';
      (f.why_bn || []).forEach(t => { const li = document.createElement('li'); li.textContent = t; why.appendChild(li); });
      const esc_ = document.getElementById('fEscalate');
      if (f.escalate) { esc_.hidden = false; esc_.innerHTML = `<span>⚠ ${esc(f.escalate_bn)}</span><a class="btn-v2 amber" href="tel:16123">☎ ১৬১২৩</a>`; } else esc_.hidden = true;
      document.getElementById('fDiff').innerHTML = (data.differential || []).map(x => `<div class="diff-item"><b>${esc(x.disease_bn)}</b> (${esc(x.disease)}) — ${esc(x.why_less_likely_bn)}<br><span class="muted">যাচাই: ${esc(x.field_check_bn)}</span></div>`).join('') || '<p class="muted">—</p>';
      document.getElementById('fRefs').innerHTML = (data.references || []).length
        ? 'আরও জানুন: ' + data.references.map(r => `<a href="${esc(r.source.url)}" target="_blank" rel="noopener">${esc(r.title_bn)} — ${esc(r.source.title)}</a>`).join(' · ')
        : 'জ্ঞানভান্ডারে মিল পাওয়া যায়নি।';
      const s = data.safety || {};
      document.getElementById('fSafety').innerHTML = [s.ipm_bn, s.label_bn, s.ppe_bn].filter(Boolean).map(t => `<li>${esc(t)}</li>`).join('')
        + ((s.pesticide_flags || []).length ? `<li><b>⚠ নিরাপত্তা ফিল্টার:</b> ${s.pesticide_flags.map(p => esc(p.substance) + ' (' + esc(p.level) + ')').join(', ')}</li>` : '');
      document.getElementById('fFormula').textContent = f.formula;
      const m = data.meta || {};
      document.getElementById('fMeta').textContent = `model ${m.model || '—'} · ${m.latency_ms || '—'} ms · tokens in ${m.tokens?.in ?? '—'} (image ${m.tokens?.image ?? '—'}, cached ${m.tokens?.cached ?? '—'}) out ${m.tokens?.out ?? '—'} · ${m.image_px || ''} · ${m.disclaimer_bn || ''}`;
      const sp = document.getElementById('speakDiag');
      sp.onclick = () => {
        const steps = ((data.treatment_plan || {}).steps || []).slice(0, 3).map(x => x.instruction_bn).join('। ');
        const ok = CB.speak(`${d.disease_bn || d.disease}। নির্ভরযোগ্যতা ${pct} শতাংশ। ${d.symptoms || ''}। করণীয়: ${steps}`);
        if (ok === false) window.showToast && showToast('info', 'বাংলা কণ্ঠ নেই', 'আপনার ফোনে বাংলা ভয়েস ইনস্টল করুন (Settings → Text-to-speech)।');
      };
      document.getElementById('askAboutDiag').onclick = () => {
        const inp = document.getElementById('chatInput');
        inp.value = `আমার ${data.crop_bn || 'ফসলে'} ${d.disease_bn || d.disease} ধরা পড়েছে। এখন ধাপে ধাপে কী করব?`;
        document.getElementById('copilot').scrollIntoView({ behavior: 'smooth' }); inp.focus();
      };
    },
  };
  return CB;
})();

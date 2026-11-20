/**
 * চাষা ভাই — data-loop helpers (loaded AFTER assets/sat-enhance.js).
 *
 * 1. Farmer feedback on a diagnosis: "ঠিক / ভুল / জানি না" buttons under the result card,
 *    POSTed to api/feedback.php with the anonymous diagnosis_id returned by api/disease.php.
 * 2. Streaming কৃষি পরামর্শ: requests to api/copilot.php are sent to api/copilot_stream.php
 *    (Server-Sent Events over fetch). The waiting bubble shows tool steps and the answer as it
 *    is written; the final, server-validated JSON is then handed back to the existing chat code
 *    unchanged. If streaming is unavailable, the original JSON endpoint is used.
 *
 * Does not change any existing wording; only adds a small feedback row and live progress text.
 */
(function () {
  'use strict';
  var TOOL_BN = {
    search_krishi_kb: 'তথ্যভান্ডারে খুঁজছি…',
    get_agri_weather: 'আবহাওয়া দেখছি…',
    get_outbreak_alerts: 'আশেপাশের রোগের খবর দেখছি…'
  };

  // ------------------------------------------------------------ 1. feedback row
  function apiUrl(name) { return new URL('api/' + name, window.location.href).toString(); }

  function feedbackRow(diagId) {
    var box = document.createElement('div');
    box.id = 'cbFeedback';
    box.style.cssText = 'margin:14px 0 0;padding:12px 14px;border-radius:14px;border:1px dashed rgba(42,53,40,.22);background:rgba(255,253,248,.7);font-size:14px;';
    var q = document.createElement('div');
    q.textContent = 'এই ফলাফল কি ঠিক মনে হচ্ছে?';
    q.style.cssText = 'font-weight:600;margin-bottom:8px;';
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;';
    var note = document.createElement('div');
    note.style.cssText = 'margin-top:8px;font-size:12px;opacity:.75;';
    note.textContent = 'আপনার মতামতে চাষা ভাই আরও ভালো হবে। ছবি, নাম বা ফোন নম্বর রাখা হয় না।';

    function btn(label, verdict) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'pill'; b.textContent = label;
      b.style.cursor = 'pointer';
      b.addEventListener('click', function () {
        if (verdict === 'wrong') { askLabel(); return; }
        send(verdict, '');
      });
      return b;
    }
    function askLabel() {
      row.innerHTML = '';
      var inp = document.createElement('input');
      inp.type = 'text'; inp.maxLength = 80;
      inp.placeholder = 'আসলে কী রোগ ছিল? (জানা থাকলে লিখুন)';
      inp.style.cssText = 'flex:1;min-width:180px;padding:8px 12px;border-radius:999px;border:1px solid rgba(42,53,40,.2);font:inherit;';
      var ok = document.createElement('button');
      ok.type = 'button'; ok.className = 'pill amber'; ok.textContent = 'পাঠান'; ok.style.cursor = 'pointer';
      ok.addEventListener('click', function () { send('wrong', inp.value.trim()); });
      row.append(inp, ok);
      inp.focus();
    }
    function send(verdict, label) {
      row.innerHTML = '…';
      fetch(apiUrl('feedback.php'), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ diagnosis_id: diagId, verdict: verdict, correct_label: label })
      }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (x) { row.textContent = x.ok ? (x.d.thanks_bn || 'ধন্যবাদ!') : 'মতামত পাঠানো যায়নি, পরে আবার চেষ্টা করুন।'; })
        .catch(function () { row.textContent = 'মতামত পাঠানো যায়নি, পরে আবার চেষ্টা করুন।'; });
    }
    row.append(btn('👍 ঠিক', 'correct'), btn('👎 ভুল', 'wrong'), btn('জানি না', 'unsure'));
    box.append(q, row, note);
    return box;
  }

  function attachFeedback(data) {
    var card = document.getElementById('fusionCard');
    var old = document.getElementById('cbFeedback');
    if (old) old.remove();
    var id = data && data.feedback && data.feedback.diagnosis_id;
    if (!card || !id) return;
    card.appendChild(feedbackRow(id));
  }

  function wrapRender() {
    if (!window.CB || typeof window.CB.renderFusion !== 'function' || window.CB.renderFusion.__fb) return;
    var orig = window.CB.renderFusion;
    var wrapped = function (data) {
      var r = orig.apply(this, arguments);
      try { attachFeedback(data); } catch (e) { /* never break the result card */ }
      return r;
    };
    wrapped.__fb = true;
    window.CB.renderFusion = wrapped;
  }

  // ------------------------------------------------------------ 2. streaming chat
  function jsonResponse(obj, status) {
    return new Response(JSON.stringify(obj), { status: status || 200, headers: { 'Content-Type': 'application/json' } });
  }

  function streamCopilot(origFetch, url, init, bubble) {
    var sUrl = url.replace(/copilot\.php(\?|$)/, 'copilot_stream.php$1');
    return origFetch(sUrl, init).then(function (res) {
      var ct = res.headers.get('content-type') || '';
      if (!res.ok || ct.indexOf('text/event-stream') === -1 || !res.body || !window.TextDecoder) throw new Error('no-stream');
      var reader = res.body.getReader();
      var dec = new TextDecoder();
      var buf = '';
      var result = null;
      function show(text) { if (bubble && bubble.isConnected) bubble.textContent = text; }
      function handle(block) {
        var ev = 'message', data = '';
        block.split('\n').forEach(function (l) {
          if (l.indexOf('event:') === 0) ev = l.slice(6).trim();
          else if (l.indexOf('data:') === 0) data += l.slice(5).trim();
        });
        if (!data) return;
        var d; try { d = JSON.parse(data); } catch (e) { return; }
        if (ev === 'tool' && d.state === 'start' && TOOL_BN[d.tool]) show(TOOL_BN[d.tool]);
        else if (ev === 'partial' && d.text) show(d.text.replace(/\*\*(.+?)\*\*/g, '$1') + ' ▍');
        else if (ev === 'final') result = jsonResponse(d, 200);
        else if (ev === 'error') result = jsonResponse({ error: d.error || 'error' }, d.http || 502);
      }
      function pump() {
        return reader.read().then(function (step) {
          if (step.value) {
            buf += dec.decode(step.value, { stream: true }).replace(/\r/g, '');
            var i;
            while ((i = buf.indexOf('\n\n')) !== -1) { handle(buf.slice(0, i)); buf = buf.slice(i + 2); }
          }
          if (step.done) {
            if (buf.trim()) handle(buf);
            if (!result) throw new Error('stream-ended');
            return result;
          }
          return pump();
        });
      }
      return pump();
    });
  }

  if (window.fetch && window.ReadableStream) {
    var prevFetch = window.fetch;
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var isChat = /\/copilot\.php(\?|$)/.test(url) && init && String(init.method || '').toUpperCase() === 'POST';
      if (!isChat) return prevFetch.apply(this, arguments);
      var bubble = document.querySelector('#chat .msg.bot:last-child');
      var self = this, args = arguments;
      return streamCopilot(prevFetch, url, init, bubble).catch(function () {
        return prevFetch.apply(self, args); // fall back to the JSON endpoint
      });
    };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wrapRender);
  else wrapRender();
})();

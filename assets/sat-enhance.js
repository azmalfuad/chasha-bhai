/**
 * চাষা ভাই — satellite result helpers (loaded AFTER assets/chasha-v2.js).
 *
 * 1. Chart.js line chart of field greenness vs the neighbour ring
 *    (the official Chart.js library; the existing SVG sparkline stays as fallback).
 * 2. A "Download CSV" button that calls /api/ndvi_export.php for the last scan.
 *
 * Does not change diagnosis, chat or map wording. Safe to add on a PC copy
 * that already has later Bangla edits: only append two script tags in index.html.
 */
(function () {
  'use strict';
  var lastQuery = null; // { mode:'get', lat, lon, radius_m, days } | { mode:'post', geometry, days }
  var lastSeries = [];
  var chart = null;

  function loadChartJs(cb) {
    if (window.Chart) { cb(); return; }
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js';
    s.async = true;
    s.onload = function () { cb(); };
    s.onerror = function () { /* keep the SVG sparkline */ };
    document.head.appendChild(s);
  }

  function ensureCanvas() {
    var spark = document.getElementById('satSpark');
    if (!spark) return null;
    var canvas = document.getElementById('satChart');
    if (!canvas) {
      canvas = document.createElement('canvas');
      canvas.id = 'satChart';
      canvas.width = 640;
      canvas.height = 240;
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-label', 'Field greenness trend');
      canvas.style.cssText = 'width:100%;max-width:640px;height:240px;display:none;background:#fffdf8;border-radius:12px;';
      spark.insertAdjacentElement('afterend', canvas);
    }
    return canvas;
  }

  function ensureButton() {
    if (document.getElementById('satCsvBtn')) return;
    var spark = document.getElementById('satSpark');
    if (!spark) return;
    var btn = document.createElement('button');
    btn.id = 'satCsvBtn';
    btn.type = 'button';
    btn.textContent = 'সবুজতা ইতিহাস CSV নামান';
    btn.style.cssText = 'margin:8px 0 0;padding:8px 14px;border-radius:999px;border:1px solid rgba(42,53,40,.18);background:#fffdf8;font:inherit;cursor:pointer;';
    btn.hidden = true;
    btn.addEventListener('click', downloadCsv);
    spark.parentNode.insertBefore(btn, spark.nextSibling);
  }

  function csvUrl() {
    if (!lastQuery || lastQuery.mode !== 'get') return null;
    var u = new URL('api/ndvi_export.php', window.location.href);
    u.searchParams.set('lat', lastQuery.lat);
    u.searchParams.set('lon', lastQuery.lon);
    u.searchParams.set('radius_m', lastQuery.radius_m || 50);
    u.searchParams.set('days', lastQuery.days || 120);
    return u.toString();
  }

  function downloadCsv() {
    if (!lastQuery) return;
    if (lastQuery.mode === 'get') {
      window.location.href = csvUrl();
      return;
    }
    fetch('api/ndvi_export.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ geometry: lastQuery.geometry, days: lastQuery.days || 120 })
    }).then(function (r) {
      if (!r.ok) throw new Error('export failed');
      return r.blob();
    }).then(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'chasha-bhai-greenness-field.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
    }).catch(function () {
      alert('CSV নামানো যায়নি। স্যাটেলাইট সেবা চালু আছে কি না দেখুন।');
    });
  }

  function renderChart(series) {
    lastSeries = series || [];
    ensureButton();
    var btn = document.getElementById('satCsvBtn');
    if (btn) btn.hidden = lastSeries.length === 0;
    var ok = lastSeries.filter(function (s) { return s && s.usable; }).slice().reverse();
    if (ok.length < 2 || !window.Chart) return;
    var canvas = ensureCanvas();
    if (!canvas) return;
    var spark = document.getElementById('satSpark');
    if (spark) spark.style.display = 'none';
    canvas.style.display = 'block';
    if (chart) { chart.destroy(); chart = null; }
    chart = new window.Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: ok.map(function (s) { return s.date; }),
        datasets: [
          {
            label: 'জমির সবুজতা (NDVI)',
            data: ok.map(function (s) { return s.ndvi; }),
            borderColor: '#4f6b48',
            backgroundColor: 'rgba(79,107,72,.15)',
            tension: 0.2,
            pointRadius: 3
          },
          {
            label: 'আশেপাশের জমি',
            data: ok.map(function (s) { return s.neighbour_ndvi; }),
            borderColor: '#c8924a',
            borderDash: [5, 4],
            tension: 0.2,
            pointRadius: 2,
            spanGaps: true
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { labels: { font: { family: 'Hind Siliguri, sans-serif', size: 12 } } },
          tooltip: { mode: 'index', intersect: false }
        },
        scales: {
          y: { min: 0, max: 1, title: { display: true, text: 'NDVI (০–১), কৃষক দেখেন ০–১০০ সবুজতা' } }
        }
      }
    });
  }

  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var p = origFetch.apply(this, arguments);
    if (url.indexOf('ndvi.php') === -1 || url.indexOf('ndvi_export.php') !== -1) return p;
    try {
      var method = (init && init.method) || 'GET';
      if (method.toUpperCase() === 'POST' && init && init.body) {
        var body = typeof init.body === 'string' ? JSON.parse(init.body) : null;
        if (body && body.geometry) lastQuery = { mode: 'post', geometry: body.geometry, days: body.days || 120 };
      } else {
        var u = new URL(url, window.location.href);
        if (u.searchParams.get('lat')) {
          lastQuery = {
            mode: 'get',
            lat: u.searchParams.get('lat'),
            lon: u.searchParams.get('lon'),
            radius_m: u.searchParams.get('radius_m') || 50,
            days: u.searchParams.get('days') || 120
          };
        }
      }
    } catch (e) { /* ignore parse errors */ }
    p.then(function (res) {
      if (!res.ok) return;
      res.clone().json().then(function (d) {
        if (d && Array.isArray(d.series)) loadChartJs(function () { renderChart(d.series); });
        else { ensureButton(); var b = document.getElementById('satCsvBtn'); if (b) b.hidden = false; }
      }).catch(function () {});
    });
    return p;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ensureButton);
  } else {
    ensureButton();
  }
})();

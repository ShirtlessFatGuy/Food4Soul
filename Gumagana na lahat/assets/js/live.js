/**
 * Food4Soul live sync.
 *
 * Polls /api/live.php every 2 s. When the data fingerprint changes it re-fetches the
 * current page in the background and swaps every element marked  data-live="<id>"
 * with its fresh copy. Elements marked data-key="<id>" that changed get a brief highlight.
 * Charts (which can't be swapped) register with  Live.on(fn).
 * A region is left alone while someone is typing in it, and caught up on the next tick.
 */
(function () {
  var INTERVAL = 2000;
  var version = null, busy = false, listeners = [];
  var pill = null;   // looked up once the DOM is ready (this script loads in <head> so Live.on() exists for inline page scripts)

  function setPill(state, text) {
    if (!pill) return;
    pill.className = 'live-pill ' + state;
    pill.querySelector('.txt').textContent = text;
  }

  function dirty(region) {
    var a = document.activeElement;
    if (a && region.contains(a) && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName)) return true;
    var fields = region.querySelectorAll('input:not([type=hidden]), textarea, select');
    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      if (f.tagName === 'SELECT') {
        for (var j = 0; j < f.options.length; j++) if (f.options[j].selected !== f.options[j].defaultSelected) return true;
      } else if (f.type === 'checkbox' || f.type === 'radio') {
        if (f.checked !== f.defaultChecked) return true;
      } else if (f.value !== f.defaultValue) return true;
    }
    return false;
  }

  function keyMap(root) {
    var m = {};
    root.querySelectorAll('[data-key]').forEach(function (el) { m[el.dataset.key] = el.textContent.trim(); });
    return m;
  }

  /** Returns true when every region was brought up to date. */
  function swapRegions(doc) {
    var ok = true, changed = 0;
    document.querySelectorAll('[data-live]').forEach(function (cur) {
      var next = doc.querySelector('[data-live="' + cur.dataset.live + '"]');
      if (!next) return;
      if (dirty(cur)) { ok = false; return; }
      var before = keyMap(cur);
      cur.replaceWith(next);
      next.querySelectorAll('[data-key]').forEach(function (el) {
        var k = el.dataset.key;
        if (k in before && before[k] !== el.textContent.trim()) { el.classList.add('flash'); changed++; }
      });
    });
    if (changed) { setPill('flash', 'Updated'); setTimeout(function () { setPill('on', 'Live'); }, 1600); }
    return ok;
  }

  function refresh(v) {
    return fetch(location.pathname + location.search, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        if (swapRegions(doc)) version = v;      // else retry next tick
        listeners.forEach(function (fn) { try { fn(); } catch (e) { console.error(e); } });
      });
  }

  function tick() {
    if (busy || document.hidden) return;
    busy = true;
    fetch('/api/live.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        setPill('on', 'Live');
        if (version === null) { version = d.v; return; }
        if (d.v !== version) return refresh(d.v);
      })
      .catch(function () { setPill('off', 'Offline'); })
      .then(function () { busy = false; });
  }

  window.Live = { on: function (fn) { listeners.push(fn); } };
  function start() {
    pill = document.getElementById('livePill');
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
    tick();
    setInterval(tick, INTERVAL);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

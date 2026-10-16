(function () {
  'use strict';
  var config = window.EISMessengerPresenceConfig;
  if (!config || window.EISMessengerPresence) return;
  var socket = null, retry, renewal, watchdog, request = null;
  var stopped = false, suspended = false, connecting = false, authenticated = false;
  var generation = 0, retryDelay = 3000, replacementShown = false;
  var listeners = new Set(), filters = null, currentIdentity = null;
  function notify(type, data) {
    listeners.forEach(function (fn) { try { fn(type, data); } catch (_) { /* A view cannot break connection cleanup. */ } });
  }
  function cancelRequest() { if (request) { request.controller.abort(); request = null; } }
  function clearTimers() { clearTimeout(retry); clearTimeout(renewal); clearTimeout(watchdog); }
  function retire() {
    generation++; connecting = false; authenticated = false;
    clearTimers(); cancelRequest();
    var previous = socket; socket = null;
    if (previous) previous.close();
  }
  function end() { stopped = true; retire(); notify('unauthorized'); }
  function replaced() {
    if (replacementShown) return;
    replacementShown = true; end();
    var banner = document.createElement('div'); banner.setAttribute('role', 'alert');
    banner.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#ffffffee;display:grid;place-content:center;text-align:center;padding:24px;font:18px Tahoma,sans-serif;color:#17324d';
    var message = document.createElement('p'); message.textContent = 'เซสชันนี้ถูกแทนที่จากการเข้าสู่ระบบใหม่';
    var link = document.createElement('a'); link.href = config.loginUrl || '/NMU_permission/'; link.textContent = 'กลับหน้าเข้าสู่ระบบ';
    banner.append(message, link); (document.body || document.documentElement).appendChild(banner);
    setTimeout(function () { window.location.assign(link.href); }, 1500);
  }
  function token() {
    if (request) return request.promise;
    var controller = new AbortController(), pending = { controller: controller };
    var timeout = setTimeout(function () { controller.abort(); }, 8000);
    pending.promise = (async function () {
      try {
        var response = await fetch(config.tokenUrl, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        if (controller.signal.aborted) throw new Error('Request cancelled');
        if (response.status === 401) {
          var denial = await response.json().catch(function () { return {}; });
          if (controller.signal.aborted) throw new Error('Request cancelled');
          if (denial.code === 'SESSION_REPLACED') replaced(); else end();
          throw new Error('Session ended');
        }
        if (response.status === 409) {
          var prompt = await response.json();
          if (controller.signal.aborted) throw new Error('Request cancelled');
          if (prompt.code === 'ADMIN_NAME_REQUIRED') {
            var target = new URL(prompt.url || '/NMU_EIS/access/admin-identity.php', location.href);
            if (target.origin === location.origin && /^\/(?:procure|NMU_EIS)\/access\/admin-identity(?:\.php)?$/.test(target.pathname)) { end(); window.location.assign(target.href); }
          }
          throw new Error('Admin name required');
        }
        if (!response.ok) throw new Error('Token unavailable');
        return await response.json();
      } finally { clearTimeout(timeout); if (request === pending) request = null; }
    }());
    request = pending;
    return pending.promise;
  }
  function scheduleRetry() {
    clearTimeout(retry);
    if (stopped || suspended || (typeof navigator !== 'undefined' && navigator.onLine === false)) return;
    var delay = retryDelay + Math.floor(Math.random() * retryDelay * 0.2);
    retryDelay = Math.min(retryDelay * 2, 30000);
    retry = setTimeout(connect, delay);
  }
  function send(value) {
    if (!authenticated || stopped || suspended || !socket || socket.readyState !== WebSocket.OPEN || socket.bufferedAmount > 1024 * 1024) return false;
    try { socket.send(JSON.stringify(value)); return true; } catch (_) { return false; }
  }
  function renew(ws, run) {
    clearTimeout(renewal);
    renewal = setTimeout(async function () {
      if (socket !== ws || generation !== run || !authenticated) return;
      try {
        var access = await token();
        if (socket !== ws || generation !== run || stopped || suspended) return;
        if (!send({ type: 'renew', token: access.token })) { ws.close(); return; }
        renew(ws, run);
      } catch (_) { if (socket === ws && generation === run) ws.close(); }
    }, 20000);
  }
  async function connect() {
    if (stopped || suspended || connecting || (socket && socket.readyState < WebSocket.CLOSING)) return;
    clearTimeout(retry); connecting = true;
    var run = ++generation;
    notify('connecting');
    try {
      var access = await token();
      if (run !== generation || stopped || suspended) return;
      var url = new URL(access.url, location.href);
      if (!['ws:', 'wss:'].includes(url.protocol) || (location.protocol === 'https:' && url.protocol !== 'wss:')) throw new Error('Invalid WebSocket URL');
      var ws = new WebSocket(url.href); socket = ws;
      watchdog = setTimeout(function () { if (socket === ws && generation === run) ws.close(); }, 10000);
      ws.onopen = function () {
        if (socket !== ws || generation !== run || stopped || suspended) { ws.close(); return; }
        ws.send(JSON.stringify({ type: 'authenticate', token: access.token, observer: !!config.observer }));
      };
      ws.onmessage = function (event) {
        if (socket !== ws || generation !== run || stopped || suspended) return;
        var message; try { message = JSON.parse(event.data); } catch (_) { return; }
        if (!message || typeof message !== 'object') return;
        if (message.type === 'session-ended' && message.reason === 'login_replaced') { replaced(); return; }
        if (message.type === 'authenticated') {
          if (authenticated) return;
          clearTimeout(watchdog); connecting = false; authenticated = true;
          retryDelay = 3000; currentIdentity = message.identity;
          notify('connected', message);
          if (filters) send({ type: 'subscribe', filters: filters });
          renew(ws, run);
        } else if (authenticated) notify(message.type, message);
      };
      ws.onclose = function () {
        if (socket !== ws || generation !== run) return;
        socket = null; connecting = false; authenticated = false;
        clearTimeout(renewal); clearTimeout(watchdog); cancelRequest();
        notify(stopped ? 'unauthorized' : 'disconnected'); scheduleRetry();
      };
      ws.onerror = function () { if (socket === ws && generation === run) ws.close(); };
    } catch (_) {
      if (run !== generation || stopped || suspended) return;
      connecting = false; notify('disconnected'); scheduleRetry();
    }
  }
  window.EISMessengerPresence = {
    send: send,
    on: function (fn) { listeners.add(fn); if (authenticated) { try { fn('connected', { identity: currentIdentity, replay: true }); } catch (_) {} } return function () { listeners.delete(fn); }; },
    subscribe: function (value) { filters = value; if (authenticated) send({ type: 'subscribe', filters: value }); },
    logout: function () { send({ type: 'logout' }); end(); }
  };
  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a');
    if (link && /\/(?:access\/)?logout(?:\.php)?(?:[?#]|$)/.test(link.href)) window.EISMessengerPresence.logout();
  });
  window.addEventListener('pagehide', function () { suspended = true; retire(); });
  window.addEventListener('pageshow', function (event) { if (event.persisted && !stopped) { suspended = false; connect(); } });
  window.addEventListener('offline', function () { retire(); notify('disconnected'); });
  window.addEventListener('online', function () { if (!stopped && !suspended) connect(); });
  connect();
}());

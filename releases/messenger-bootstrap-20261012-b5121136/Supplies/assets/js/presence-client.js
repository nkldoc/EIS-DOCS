(function () {
  'use strict';
  var config = window.EISPresenceConfig;
  if (!config || window.EISPresence) return;
  var socket, renewal, retry, deadline, tokenController, connecting = false, attempt = 0, generation = 0;
  var stopped = false, authenticated = false, replacementShown = false;
  var listeners = [], filters = null, currentIdentity = null;
  function notify(type, data) { listeners.slice().forEach(function (fn) { try { fn(type, data); } catch (error) { console.error('Presence listener failed', error); } }); }
  function stopConnection() {
    generation++; connecting = false; authenticated = false;
    clearTimeout(retry); clearTimeout(deadline); clearTimeout(renewal);
    if (tokenController) tokenController.abort();
    if (socket) { var previous = socket; socket = null; previous.close(); }
  }
  function schedule() {
    clearTimeout(retry);
    if (stopped || navigator.onLine === false) return;
    var delay = Math.min(15000, 500 * Math.pow(2, Math.min(attempt++, 5)));
    retry = setTimeout(connect, delay + Math.floor(Math.random() * delay * .2));
  }
  function replaced() {
    if (replacementShown) return; replacementShown = true;
    stopped = true; stopConnection();
    notify('unauthorized');
    var banner = document.createElement('div'); banner.setAttribute('role', 'alert');
    banner.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#ffffffee;display:grid;place-content:center;text-align:center;padding:24px;font:18px Tahoma,sans-serif;color:#17324d';
    var message = document.createElement('p'); message.textContent = 'เซสชันนี้ถูกแทนที่จากการเข้าสู่ระบบใหม่';
    var link = document.createElement('a'); link.href = config.loginUrl; link.textContent = 'กลับหน้าเข้าสู่ระบบ';
    banner.append(message, link); (document.body || document.documentElement).appendChild(banner);
    setTimeout(function () { window.location.assign(config.loginUrl); }, 1500);
  }
  async function token() {
    var controller = new AbortController(); tokenController = controller;
    var timeout = setTimeout(function () { controller.abort(); }, 8000);
    var response;
    try {
      response = await fetch(config.tokenUrl, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      // Include reading the response body in the timeout.
      var body = await response.json().catch(function (error) { if (response.status === 401) return {}; throw error; });
    } finally { clearTimeout(timeout); if (tokenController === controller) tokenController = null; }
    if (response.status === 401) { if (body.code === 'SESSION_REPLACED') replaced(); else { stopped = true; stopConnection(); notify('unauthorized'); } throw new Error('Session ended'); }
    if (!response.ok) throw new Error('Token unavailable');
    return body;
  }
  function renew(active, run) {
    renewal = setTimeout(async function () {
      if (socket !== active || run !== generation || !authenticated || stopped) return;
      try {
        var access = await token();
        if (socket !== active || run !== generation || stopped) return;
        if (!send({ type: 'renew', token: access.token })) { active.close(); return; }
        renew(active, run);
      } catch (_) { if (socket === active) active.close(); }
    }, 20000);
  }
  async function connect() {
    if (stopped || connecting || socket || navigator.onLine === false) return;
    connecting = true; clearTimeout(retry); var run = ++generation;
    notify('connecting');
    try {
      var access = await token();
      if (run !== generation || stopped) return;
      var active = socket = new WebSocket(access.url);
      deadline = setTimeout(function () { if (socket === active && !authenticated) active.close(); }, 10000);
      active.onopen = function () { if (socket === active) send({ type: 'authenticate', token: access.token, observer: !!config.observer }); };
      active.onmessage = function (event) {
        if (socket !== active || run !== generation || stopped) return;
        var message; try { message = JSON.parse(event.data); } catch (_) { return; }
        if (message.type === 'session-ended' && message.reason === 'login_replaced') { replaced(); return; }
        if (message.type === 'authenticated') {
          if (authenticated) return;
          clearTimeout(deadline); connecting = false; attempt = 0;
          authenticated = true; currentIdentity = message.identity; notify('connected', message);
          if (filters) send({ type: 'subscribe', filters: filters });
          renew(active, run);
        } else notify(message.type, message);
      };
      active.onclose = function () {
        if (socket !== active || run !== generation) return;
        socket = null; connecting = false; authenticated = false; clearTimeout(deadline); clearTimeout(renewal);
        if (tokenController) tokenController.abort();
        notify(stopped ? 'unauthorized' : 'disconnected'); schedule();
      };
      active.onerror = function () { if (socket === active) active.close(); };
    } catch (_) { if (run === generation) { connecting = false; if (!stopped) { notify('disconnected'); schedule(); } } }
  }
  function send(value) {
    if (!socket || socket.readyState !== WebSocket.OPEN) return false;
    try { socket.send(JSON.stringify(value)); return true; } catch (_) { socket.close(); return false; }
  }
  window.EISPresence = {
    send: function (value) { if (!authenticated || stopped) return false; return send(value); },
    on: function (fn) { listeners.push(fn); if (authenticated) fn('connected', {identity:currentIdentity, replay:true}); },
    subscribe: function (value) { filters = value; if (authenticated) send({ type: 'subscribe', filters: value }); },
    logout: function () { stopped = true; send({ type: 'logout' }); stopConnection(); notify('unauthorized'); }
  };
  window.addEventListener('offline', function () { if (!stopped) { stopConnection(); notify('disconnected'); } });
  window.addEventListener('online', function () { if (!stopped) { attempt = 0; clearTimeout(retry); connect(); } });
  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a');
    if (link && /\/(?:access\/)?logout(?:\.php)?(?:[?#]|$)/.test(link.href)) window.EISPresence.logout();
  });
  connect();
}());

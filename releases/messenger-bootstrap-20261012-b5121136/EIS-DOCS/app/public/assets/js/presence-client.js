(function () {
  'use strict';
  var config = window.EISPresenceConfig;
  if (!config || window.EISPresence) return;
  var socket, renewal, retry, stopped = false, authenticated = false, replacementShown = false;
  var listeners = [], filters = null, currentIdentity = null;
  function notify(type, data) { listeners.forEach(function (fn) { fn(type, data); }); }
  function replaced() {
    if (replacementShown) return; replacementShown = true;
    stopped = true; clearTimeout(retry); clearInterval(renewal); if (socket) socket.close();
    notify('unauthorized');
    var banner = document.createElement('div'); banner.setAttribute('role', 'alert');
    banner.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#ffffffee;display:grid;place-content:center;text-align:center;padding:24px;font:18px Tahoma,sans-serif;color:#17324d';
    var message = document.createElement('p'); message.textContent = 'เซสชันนี้ถูกแทนที่จากการเข้าสู่ระบบใหม่';
    var link = document.createElement('a'); link.href = config.loginUrl; link.textContent = 'กลับหน้าเข้าสู่ระบบ';
    banner.append(message, link); (document.body || document.documentElement).appendChild(banner);
    setTimeout(function () { window.location.assign(config.loginUrl); }, 1500);
  }
  async function token() {
    var response = await fetch(config.tokenUrl, { credentials: 'same-origin', cache: 'no-store' });
    if (response.status === 401) { var denial = await response.json().catch(function () { return {}; }); if (denial.code === 'SESSION_REPLACED') replaced(); else { stopped = true; if (socket) socket.close(); notify('unauthorized'); } throw new Error('Session ended'); }
    if (!response.ok) throw new Error('Token unavailable');
    return response.json();
  }
  async function connect() {
    if (stopped) return;
    notify('connecting');
    try {
      var access = await token();
      socket = new WebSocket(access.url);
      socket.onopen = function () { socket.send(JSON.stringify({ type: 'authenticate', token: access.token, observer: !!config.observer })); };
      socket.onmessage = function (event) {
        var message; try { message = JSON.parse(event.data); } catch (_) { return; }
        if (message.type === 'session-ended' && message.reason === 'login_replaced') { replaced(); return; }
        if (message.type === 'authenticated') {
          authenticated = true; currentIdentity = message.identity; notify('connected', message);
          if (filters) send({ type: 'subscribe', filters: filters });
          renewal = setInterval(async function () {
            try { var access = await token(); send({ type: 'renew', token: access.token }); }
            catch (_) { if (socket) socket.close(); }
          }, 20000);
        } else notify(message.type, message);
      };
      socket.onclose = function () { authenticated = false; clearInterval(renewal); notify(stopped ? 'unauthorized' : 'disconnected'); if (!stopped) retry = setTimeout(connect, 3000); };
      socket.onerror = function () { notify('disconnected'); };
    } catch (_) { if (!stopped) { notify('disconnected'); retry = setTimeout(connect, 3000); } }
  }
  function send(value) { if (socket && socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify(value)); }
  window.EISPresence = {
    send: function (value) { if (!authenticated || stopped) return false; send(value); return true; },
    on: function (fn) { listeners.push(fn); if (authenticated) fn('connected', {identity:currentIdentity, replay:true}); },
    subscribe: function (value) { filters = value; if (authenticated) send({ type: 'subscribe', filters: value }); },
    logout: function () { stopped = true; clearTimeout(retry); clearInterval(renewal); send({ type: 'logout' }); if (socket) socket.close(); }
  };
  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a');
    if (link && /\/(?:access\/)?logout(?:\.php)?(?:[?#]|$)/.test(link.href)) window.EISPresence.logout();
  });
  connect();
}());

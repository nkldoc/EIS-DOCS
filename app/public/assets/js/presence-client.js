(function () {
  'use strict';
  var config = window.EISPresenceConfig;
  if (!config || window.EISPresence) return;
  var socket = null, retry, renewal, watchdog, request = null;
  var stopped = false, suspended = false, connecting = false, authenticated = false;
  var generation = 0, retryDelay = 3000, replacementShown = false;
  var listeners = new Set(), filters = null, currentIdentity = null;
  var logs = [], state = 'connecting', lastIssue = null, endpoint = '';
  function safeUrl(value) {
    try { var url = new URL(value, location.href); return url.origin + url.pathname; } catch (_) { return '(invalid URL)'; }
  }
  function record(stage, code, detail) {
    logs.push(Object.assign({ at: new Date().toISOString(), stage: stage, code: code }, detail || {}));
    if (logs.length > 100) logs.shift();
    notify('diagnostic');
  }
  function issue(stage, code, message, detail) {
    lastIssue = { stage: stage, code: code, message: message };
    record(stage, code, detail);
  }
  function notify(type, data) {
    if (['connecting', 'connected', 'disconnected', 'unauthorized'].includes(type)) state = type;
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
        record('token', 'request', { url: safeUrl(config.tokenUrl) });
        var response = await fetch(config.tokenUrl, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        if (controller.signal.aborted) throw new Error('Request cancelled');
        record('token', 'response', { status: response.status, url: safeUrl(response.url || config.tokenUrl), redirected: !!response.redirected });
        if (response.status === 401) {
          issue('token', 'HTTP_401', 'เซสชันสิ้นสุด กรุณาเข้าสู่ระบบใหม่');
          var denial = await response.json().catch(function () { return {}; });
          if (controller.signal.aborted) throw new Error('Request cancelled');
          if (denial.code === 'SESSION_REPLACED') replaced(); else end();
          throw new Error('Session ended');
        }
        if (response.status === 409) {
          issue('token', 'HTTP_409', 'ต้องระบุชื่อผู้ดูแลก่อนเชื่อมต่อ');
          var prompt = await response.json();
          if (controller.signal.aborted) throw new Error('Request cancelled');
          if (prompt.code === 'ADMIN_NAME_REQUIRED') {
            var target = new URL(prompt.url || '/NMU_EIS/access/admin-identity.php', location.href);
            if (target.origin === location.origin && /^\/(?:procure|NMU_EIS)\/access\/admin-identity(?:\.php)?$/.test(target.pathname)) { end(); window.location.assign(target.href); }
          }
          throw new Error('Admin name required');
        }
        if (!response.ok) {
          issue('token', 'HTTP_' + response.status, response.status === 503 ? 'บริการออก token ยังไม่พร้อม ตรวจสอบ config และสิทธิ์อ่านไฟล์ secret บนเซิร์ฟเวอร์' : 'ขอ token ไม่สำเร็จ ตรวจสอบเส้นทางและสถานะ HTTP');
          throw new Error('Token unavailable');
        }
        var access;
        try { access = await response.json(); } catch (_) {
          issue('token', 'INVALID_JSON', 'ปลายทาง token ไม่ได้ส่ง JSON อาจถูก redirect ไปหน้า login');
          throw new Error('Invalid token response');
        }
        if (!access || typeof access.token !== 'string' || !access.token || typeof access.url !== 'string' || !access.url) {
          issue('token', 'INVALID_RESPONSE', 'ข้อมูลตอบกลับ token ไม่ครบ'); throw new Error('Invalid token response');
        }
        return access;
      } catch (error) {
        if (!controller.signal.aborted && !(lastIssue && lastIssue.stage === 'token')) issue('token', 'NETWORK_ERROR', 'เรียกปลายทาง token ไม่ได้ ตรวจสอบเครือข่ายหรือ TLS');
        if (controller.signal.aborted && !stopped && !suspended && request === pending) issue('token', 'TIMEOUT', 'ขอ token เกินเวลา 8 วินาที');
        throw error;
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
    record('retry', 'scheduled', { delayMs: delay });
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
    clearTimeout(retry); connecting = true; lastIssue = null;
    var run = ++generation;
    notify('connecting');
    try {
      var access = await token();
      if (run !== generation || stopped || suspended) return;
      var url;
      try { url = new URL(access.url, location.href); } catch (_) { issue('websocket', 'INVALID_URL', 'URL WebSocket ไม่ถูกต้อง'); throw new Error('Invalid URL'); }
      if (!['ws:', 'wss:'].includes(url.protocol) || (location.protocol === 'https:' && url.protocol !== 'wss:')) { issue('websocket', 'INVALID_PROTOCOL', 'หน้า HTTPS ต้องใช้ WebSocket แบบ wss://'); throw new Error('Invalid WebSocket URL'); }
      endpoint = safeUrl(url.href); record('websocket', 'connecting', { url: endpoint });
      var ws = new WebSocket(url.href); socket = ws;
      watchdog = setTimeout(function () { if (socket === ws && generation === run) { issue('authentication', 'TIMEOUT', 'เปิด WebSocket หรือยืนยันตัวตนเกินเวลา 10 วินาที ตรวจสอบ IIS proxy และบริการ Messenger'); ws.close(); } }, 10000);
      ws.onopen = function () {
        if (socket !== ws || generation !== run || stopped || suspended) { ws.close(); return; }
        record('websocket', 'open');
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
          retryDelay = 3000; currentIdentity = message.identity; lastIssue = null; record('authentication', 'authenticated');
          notify('connected', message);
          if (filters) send({ type: 'subscribe', filters: filters });
          renew(ws, run);
        } else if (authenticated) notify(message.type, message);
      };
      ws.onclose = function (event) {
        if (socket !== ws || generation !== run) return;
        record('websocket', 'closed', { closeCode: event && event.code, clean: !!(event && event.wasClean), authenticated: authenticated });
        if (!lastIssue) issue('websocket', event && event.code === 1008 ? 'AUTH_REJECTED' : 'CLOSED', event && event.code === 1008 ? 'เซิร์ฟเวอร์ปฏิเสธการเชื่อมต่อ ตรวจสอบ token, secret, Origin และนโยบายบนเซิร์ฟเวอร์' : 'WebSocket ถูกปิด ตรวจสอบเครือข่ายและ IIS proxy');
        socket = null; connecting = false; authenticated = false;
        clearTimeout(renewal); clearTimeout(watchdog); cancelRequest();
        notify(stopped ? 'unauthorized' : 'disconnected'); scheduleRetry();
      };
      ws.onerror = function () { if (socket === ws && generation === run) { issue('websocket', 'CONNECT_ERROR', 'เชื่อมต่อ WebSocket ไม่สำเร็จ ตรวจสอบ IIS proxy, TLS, Origin และบริการ Messenger (เบราว์เซอร์ไม่เปิดเผย HTTP status ของ handshake)'); ws.close(); } };
    } catch (_) {
      if (run !== generation || stopped || suspended) return;
      if (!lastIssue) issue('connection', 'FAILED', 'เริ่มการเชื่อมต่อไม่สำเร็จ');
      connecting = false; notify('disconnected'); scheduleRetry();
    }
  }
  window.EISPresence = {
    send: send,
    diagnostics: function () { return { state: state, online: navigator.onLine !== false, tokenUrl: safeUrl(config.tokenUrl), websocketUrl: endpoint, issue: lastIssue && Object.assign({}, lastIssue), logs: logs.map(function (entry) { return Object.assign({}, entry); }) }; },
    reconnect: function () { if (stopped || suspended) return false; record('retry', 'manual'); retire(); retryDelay = 3000; connect(); return true; },
    on: function (fn) { listeners.add(fn); try { fn(authenticated ? 'connected' : state, authenticated ? { identity: currentIdentity, replay: true } : { replay: true }); } catch (_) {} return function () { listeners.delete(fn); }; },
    subscribe: function (value) { filters = value; if (authenticated) send({ type: 'subscribe', filters: value }); },
    logout: function () { send({ type: 'logout' }); end(); }
  };
  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a');
    if (link && /\/(?:access\/)?logout(?:\.php)?(?:[?#]|$)/.test(link.href)) window.EISPresence.logout();
  });
  window.addEventListener('pagehide', function () { suspended = true; retire(); });
  window.addEventListener('pageshow', function (event) { if (event.persisted && !stopped) { suspended = false; connect(); } });
  window.addEventListener('offline', function () { retire(); issue('network', 'OFFLINE', 'อุปกรณ์ออฟไลน์ กรุณาตรวจสอบเครือข่าย'); notify('disconnected'); });
  window.addEventListener('online', function () { if (!stopped && !suspended) connect(); });
  connect();
}());

'use strict';
const fs = require('fs');
const path = require('path');
const https = require('https');
const crypto = require('crypto');
const { WebSocketServer, WebSocket } = require('ws');
const { PresenceStore } = require('./store');
const directory = process.env.EIS_PRESENCE_DIR || 'C:/xampp/var/nmu-eis-presence';
const secret = fs.readFileSync(path.join(directory, 'secret.txt'), 'utf8').trim();
let store;
const allowedOrigins = (process.env.EIS_PRESENCE_ORIGINS || 'https://dev-eis.nmu.ac.th,http://dev-eis.nmu.ac.th').split(',').map(value => value.trim()).filter(Boolean);
const host = process.env.EIS_PRESENCE_HOST || '127.0.0.1';
const port = Number(process.env.EIS_PRESENCE_PORT || 4001);
const server = https.createServer({
  key: fs.readFileSync(process.env.EIS_PRESENCE_TLS_KEY || path.join(directory, 'local.key')),
  cert: fs.readFileSync(process.env.EIS_PRESENCE_TLS_CERT || path.join(directory, 'local.crt'))
}, (req, res) => {
  if (req.url !== '/health') { res.writeHead(404); return res.end(); }
  res.setHeader('Content-Type', 'application/json');
  res.statusCode = !store || store.lastError ? 503 : 200;
  res.end(JSON.stringify({ status: !store ? 'starting' : store.lastError ? 'storage_error' : 'ok', service: 'eis-presence' }));
});
const wss = new WebSocketServer({ noServer: true, maxPayload: 16384, perMessageDeflate: false });
function verify(token) {
  if (typeof token !== 'string' || token.length > 8192) throw new Error('Invalid token');
  const parts = token.split('.');
  const digest = crypto.createHmac('sha256', secret).update(parts[0]).digest('hex');
  if (parts.length !== 2 || !/^[a-f0-9]{64}$/.test(parts[1]) || !crypto.timingSafeEqual(Buffer.from(digest), Buffer.from(parts[1]))) throw new Error('Invalid signature');
  const identity = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'));
  if (!identity.uid || !/^[a-f0-9]{64}$/.test(identity.session) || !Number.isFinite(identity.exp) || identity.exp <= Date.now() / 1000 || identity.exp > Date.now() / 1000 + 65 || fs.existsSync(path.join(directory, 'revoked', identity.session))) throw new Error('Expired session');
  identity.admin = identity.admin === true;
  return identity;
}
function send(ws, message) {
  if (ws.readyState !== WebSocket.OPEN) return;
  const text = typeof message === 'string' ? message : JSON.stringify(message);
  const kind = typeof message === 'string' ? (message.startsWith('{"type":"snapshot"') ? 'snapshot' : message.startsWith('{"type":"chat-inbox"') ? 'chat-inbox' : '') : message.type;
  ws.output ||= []; ws.outputBytes ||= 0;
  // Replace stale queued summaries, while preserving every chat message and acknowledgement.
  if (kind === 'snapshot' || kind === 'chat-inbox') {
    const index = ws.output.findIndex(item => item.kind === kind);
    if (index >= 0) { ws.outputBytes -= ws.output[index].bytes; ws.output.splice(index, 1); }
  }
  const bytes = Buffer.byteLength(text);
  if (ws.outputBytes + bytes > 16 * 1024 * 1024) { ws.close(1013, 'Slow connection'); return; }
  ws.output.push({text,bytes,kind}); ws.outputBytes += bytes;
  pump(ws);
}
function pump(ws) {
  if (ws.outputSending) return;
  if (ws.readyState !== WebSocket.OPEN) { ws.output = []; ws.outputBytes = 0; return; }
  const item = ws.output.shift(); if (!item) return;
  ws.outputSending = true;
  ws.send(item.text, error => {
    ws.outputSending = false; ws.outputBytes = Math.max(0, ws.outputBytes - item.bytes);
    if (error) { ws.output = []; ws.outputBytes = 0; ws.close(); return; }
    pump(ws);
  });
}

function live(ws) { return ws.identity && ws.identity.exp > Date.now()/1000 && !fs.existsSync(path.join(directory,'revoked',ws.identity.session)); }
function broadcast() { const snapshots=new Map();for (const ws of wss.clients) if (live(ws) && ws.identity.admin && ws.filters) { const key=JSON.stringify(ws.filters);if(!snapshots.has(key))snapshots.set(key,JSON.stringify({type:'snapshot',...store.snapshot(ws.filters)}));send(ws,snapshots.get(key)); } }
server.on('upgrade', (req, socket, head) => {
  if (!store) { socket.end('HTTP/1.1 503 Service Unavailable\r\n\r\n'); return; }
  if (req.url !== '/presence' || !allowedOrigins.includes(req.headers.origin)) { socket.end('HTTP/1.1 403 Forbidden\r\n\r\n'); return; }
  wss.handleUpgrade(req, socket, head, ws => wss.emit('connection', ws, req));
});
wss.on('connection', (ws, req) => {
  ws.alive = true;
  const deadline = setTimeout(() => { if (!ws.identity) ws.close(1008, 'Authentication required'); }, 5000);
  ws.on('pong', () => { ws.alive = true; });
  ws.on('error', () => {});
  ws.on('message', raw => {
    try {
      const now=Date.now();if(!ws.rate||now-ws.rate.start>=10000)ws.rate={start:now,count:0,heavy:0};if(++ws.rate.count>120)throw new Error('Rate exceeded');
      const message = JSON.parse(raw.toString());
      if (!message || typeof message !== 'object' || Array.isArray(message)) throw new Error('Invalid request');
      if(message.type==='subscribe'&&++ws.rate.heavy>10)throw new Error('Query rate exceeded');
      if (message.type === 'authenticate' && !ws.identity) {
        const identity=verify(message.token), observer=message.observer===true;
        if(observer&&!identity.admin)throw new Error('Admin only');
        ws.identity=identity;ws.observer=observer;
        clearTimeout(deadline);
        if (!ws.observer) store.connect(ws, ws.identity, req.socket.remoteAddress);
        send(ws, { type: 'authenticated' }); broadcast();
      } else {
        if (!live(ws)) throw new Error('Authentication required');
        if (message.type === 'renew') {
          const identity = verify(message.token);
          if (identity.session !== ws.identity.session || identity.uid !== ws.identity.uid) throw new Error('Session mismatch');
          ws.identity = identity;
          if (!identity.admin) ws.filters = null;
        } else if (message.type === 'subscribe') {
          if (!ws.identity.admin) throw new Error('Admin only');
          ws.filters = store.validateFilters(message.filters);
          send(ws, { type: 'snapshot', ...store.snapshot(ws.filters) });
        } else if (message.type === 'logout') {
          fs.writeFileSync(path.join(directory, 'revoked', ws.identity.session), String(Date.now()));
          for (const client of wss.clients) if (client.identity && client.identity.session === ws.identity.session) { store.disconnect(client, 'logout', true); client.close(1008, 'Logged out'); }
          broadcast();
        }
      }
    } catch (_) { ws.close(1008, 'Invalid request or unauthorized'); }
  });
  ws.on('close', () => { ws.output = []; ws.outputBytes = 0; clearTimeout(deadline); store.disconnect(ws); broadcast(); });
});
const heartbeat = setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.identity) continue;
    if (ws.identity.exp <= Date.now() / 1000 || fs.existsSync(path.join(directory, 'revoked', ws.identity.session))) {
      store.disconnect(ws, 'session_expired', true); ws.close(1008, 'Session expired'); continue;
    }
    if (!ws.alive) { ws.terminate(); continue; }
    ws.alive = false; ws.ping();
  }
  if (store) { store.tick(); broadcast(); }
}, 10000);
server.listen(port, host, () => { try { store=new PresenceStore(directory);console.log('EIS presence listening on https://' + host + ':' + port); } catch(error) { console.error('Presence initialization failed:',error.code||error.name);process.exitCode=1;shutdown(); } });
server.on('error', error=>{clearInterval(heartbeat);console.error('Presence listener failed:',error.code);process.exitCode=1;});
function shutdown() { clearInterval(heartbeat); if (store) store.shutdown(); for (const ws of wss.clients) ws.terminate(); server.close(() => process.exit(process.exitCode || 0)); }
process.on('SIGINT', shutdown); process.on('SIGTERM', shutdown);

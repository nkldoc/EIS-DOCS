const {monthlyReport}=require('./report');
'use strict';
const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');
const crypto = require('crypto');
const { WebSocketServer, WebSocket } = require('ws');
const { PresenceStore } = require('./store');
const { ChatStore } = require('./chat');
const configFile = process.env.EIS_PRESENCE_SERVER_CONFIG || path.join(__dirname, 'server.config.json');
const config = fs.existsSync(configFile) ? JSON.parse(fs.readFileSync(configFile, 'utf8')) : {};
const directory = process.env.EIS_DOCS_PRESENCE_DIR || config.directory || 'D:/EIS-DOCS/storage/presence';
const transport = process.env.EIS_PRESENCE_TRANSPORT || config.transport || 'https';
if (!['http', 'https'].includes(transport)) throw new Error('Invalid presence transport');
const origins = new Set(config.origins || ['https://dev-eis.nmu.ac.th', 'http://dev-eis.nmu.ac.th', 'https://doc-eis.nmu.ac.th', 'https://eis.nmu.ac.th']);
const secrets = Object.fromEntries(['EIS-DOCS', 'SUPPLIES'].map(system => [system, fs.readFileSync(path.join(directory, system.toLowerCase() + '.secret'), 'utf8').trim()]));
// Procure can be deployed separately without breaking existing installations.
const procureSecret = path.join(directory, 'procure.secret');
if (fs.existsSync(procureSecret)) secrets.PROCURE = fs.readFileSync(procureSecret, 'utf8').trim();
const nmuEisSecret = path.join(directory, 'nmu-eis.secret');
if (fs.existsSync(nmuEisSecret)) secrets['NMU-EIS'] = fs.readFileSync(nmuEisSecret, 'utf8').trim();
let store, chat;
const capabilities = { conversations: false };
const handler = (req, res) => {
  if (req.url !== '/health') { res.writeHead(404); return res.end(); }
  res.setHeader('Content-Type', 'application/json');
  res.statusCode = !store || store.lastError ? 503 : 200;
  res.end(JSON.stringify({ status: !store ? 'starting' : store.lastError ? 'storage_error' : 'ok', service: 'docs-supplies-presence', build: '20261013-ws-safe', protocolVersion: 2, capabilities }));
};
const server = transport === 'http' ? http.createServer(handler) : https.createServer({
  key: fs.readFileSync(config.tlsKey || path.join(directory, 'local.key')),
  cert: fs.readFileSync(config.tlsCert || path.join(directory, 'local.crt'))
}, handler);
const wss = new WebSocketServer({ noServer: true, maxPayload: 400000, perMessageDeflate: false });
function verify(token) {
  if (typeof token !== 'string' || token.length > 8192) throw new Error('Invalid token');
  const parts = token.split('.');
  const identity = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'));
  const secret = secrets[identity.system];
  if (typeof secret !== 'string') throw new Error('Unsupported system');
  const digest = crypto.createHmac('sha256', secret).update(parts[0]).digest('hex');
  if (parts.length !== 2 || !/^[a-f0-9]{64}$/.test(parts[1]) || !crypto.timingSafeEqual(Buffer.from(digest), Buffer.from(parts[1]))) throw new Error('Invalid signature');
  if (!/^\d{1,20}(?:@admin-[a-z0-9][a-z0-9_-]{1,31})?$/.test(String(identity.uid)) || !/^[a-f0-9]{64}$/.test(identity.session) || !Number.isFinite(identity.exp) || identity.exp <= Date.now() / 1000 || identity.exp > Date.now() / 1000 + 65 || fs.existsSync(path.join(directory, 'revoked', identity.session))) throw new Error('Expired session');
  identity.admin = identity.system === 'EIS-DOCS' && identity.admin === true;
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

function broadcast() {
  const snapshots = new Map();
  for (const ws of wss.clients) if (live(ws) && ws.identity.admin && ws.filters) {
    const key = JSON.stringify(ws.filters);
    if (!snapshots.has(key)) snapshots.set(key, JSON.stringify({type:'snapshot', ...store.snapshot(ws.filters)}));
    send(ws, snapshots.get(key));
  }
}
function live(ws) { return ws.identity && ws.identity.exp>Date.now()/1000 && !fs.existsSync(path.join(directory,'revoked',ws.identity.session)); }
function roster() {
  const users=new Map();
  for(const ws of wss.clients) if(live(ws)&&!ws.observer) {
    const key=chat.key(ws.identity), user=users.get(key)||{system:ws.identity.system,uid:String(ws.identity.uid),name:ws.identity.name,pages:[]};
    if(ws.page&&!user.pages.includes(ws.page))user.pages.push(ws.page);users.set(key,user);
  }
  for(const ws of wss.clients)if(live(ws)&&ws.identity.admin)send(ws,{type:'chat-roster',users:[...users.values()]});
}
function inboxes(){const results=new Map();for(const ws of wss.clients)if(live(ws)&&ws.identity.admin){const key=chat.key(ws.identity);if(!results.has(key))results.set(key,JSON.stringify({type:'chat-inbox',jobs:chat.inbox(ws.identity)}));send(ws,results.get(key));}}
function jobUpdate(room){room=chat.state.rooms[room.id]||room;if(!chat.isJob(room))return;const metadata=chat.publicRoom(room);for(const ws of wss.clients)if(live(ws)&&chat.allowed(room,ws.identity))send(ws,{type:'chat-job-updated',room:metadata});inboxes();}
server.on('upgrade', (req, socket, head) => {
  if (!store || !chat) { socket.end('HTTP/1.1 503 Service Unavailable\r\n\r\n'); return; }
  if (req.url !== '/presence' || !origins.has(req.headers.origin)) { socket.end('HTTP/1.1 403 Forbidden\r\n\r\n'); return; }
  wss.handleUpgrade(req, socket, head, ws => wss.emit('connection', ws, req));
});
wss.on('connection', (ws, req) => {
  ws.alive = true;
  const deadline = setTimeout(() => { if (!ws.identity) ws.close(1008, 'Authentication required'); }, 5000);
  ws.on('pong', () => { ws.alive = true; });
  ws.on('error', () => {});
  ws.on('message', raw => {
    try {
      const now = Date.now();
      if (!ws.rate || now - ws.rate.start >= 10000) ws.rate = {start:now,count:0,heavy:0};
      if (++ws.rate.count > 120) throw new Error('Request rate exceeded');
      const message = JSON.parse(raw.toString());
      if (!message || typeof message !== 'object' || Array.isArray(message)) throw new Error('Invalid request');
      if (['subscribe','chat-sync','chat-report'].includes(message.type) && ++ws.rate.heavy > 10) throw new Error('Query rate exceeded');
      if (message.type === 'authenticate' && !ws.identity) {
        const identity = verify(message.token);
        const observer = message.observer === true;
        if (observer && !identity.admin) throw new Error('Admin only');
        ws.identity = identity; ws.observer = observer;
        clearTimeout(deadline);
        // Remove displaced connections before opening the new login's round.
        for (const old of wss.clients) {
          if (old === ws || !old.identity || old.identity.system !== ws.identity.system || old.identity.uid !== ws.identity.uid || old.identity.session === ws.identity.session) continue;
          const revoked = path.join(directory, 'revoked', old.identity.session);
          if (!fs.existsSync(revoked)) continue;
          let reason = 'session_expired', endedAt = Date.now(); try { const record = JSON.parse(fs.readFileSync(revoked, 'utf8')); if (record.reason === 'login_replaced') { reason = 'login_replaced'; if (Number.isFinite(record.at_ms)) endedAt = record.at_ms; } } catch (_) {}
          store.disconnect(old, reason, true, endedAt);
          if (reason === 'login_replaced') send(old, { type: 'session-ended', reason });
          old.close(1008, 'Session ended');
        }
        if (!ws.observer) store.connect(ws, ws.identity, req.socket.remoteAddress);
        send(ws, { type: 'authenticated', identity:{system:ws.identity.system,uid:String(ws.identity.uid),name:ws.identity.name,admin:ws.identity.admin} }); broadcast();
        send(ws,{type:'chat-ready',capabilities,rooms:chat.rooms(ws.identity)});
        for(const item of chat.history(ws.identity)) send(ws,{type:'chat-message',message:item,room:chat.publicRoom(chat.state.rooms[item.room]),replay:true,unread:chat.unread(ws.identity,item)});
        if(ws.identity.admin)send(ws,{type:'chat-inbox',jobs:chat.inbox(ws.identity)});
        roster();
      } else {
        if (!ws.identity) throw new Error('Authentication required');
        if (!live(ws)) throw new Error('Session ended');
        if (message.type === 'renew') {
          const identity = verify(message.token);
          if (identity.session !== ws.identity.session || identity.uid !== ws.identity.uid || identity.system !== ws.identity.system) throw new Error('Session mismatch');
          ws.identity = identity;
          if (!identity.admin) ws.filters = null;
        } else if (message.type === 'subscribe') {
          if (!ws.identity.admin) throw new Error('Admin only');
          ws.filters = store.validateFilters(message.filters);
          send(ws, { type: 'snapshot', ...store.snapshot(ws.filters) });
        } else if(message.type==='chat-sync') {
          send(ws,{type:'chat-ready',capabilities,rooms:chat.rooms(ws.identity)});
          for(const item of chat.history(ws.identity))send(ws,{type:'chat-message',message:item,room:chat.publicRoom(chat.state.rooms[item.room]),replay:true,unread:chat.unread(ws.identity,item)});
          if(ws.identity.admin)send(ws,{type:'chat-inbox',jobs:chat.inbox(ws.identity)});
        } else if(message.type==='page') {
          const prefix={'SUPPLIES':'/supplies','EIS-DOCS':'/eis-docs','PROCURE':'/procure','NMU-EIS':'/NMU_EIS'}[ws.identity.system];
          if(typeof message.path==='string'&&(message.path===prefix||message.path.startsWith(prefix+'/'))&&message.path.length<300&&!/[?#\x00-\x1f]/.test(message.path)){if(ws.page!==message.path){ws.page=message.path;roster();}}
        } else if(message.type==='chat-send'||message.type==='chat-retract'||message.type==='chat-read'||message.type==='chat-job'||message.type==='chat-job-detail'||message.type==='chat-inbox'||message.type==='chat-report') {
          try {
            if(message.type==='chat-report'){send(ws,{type:'chat-report',...monthlyReport(chat,ws.identity,message.month)});
            } else if(message.type==='chat-inbox'){send(ws,{type:'chat-inbox',jobs:chat.inbox(ws.identity)});
            } else if(message.type==='chat-retract') {
              ws.retractTimes=(ws.retractTimes||[]).filter(t=>t>Date.now()-10000);
              if(ws.retractTimes.length>=15)throw new Error('ยกเลิกข้อความถี่เกินไป กรุณารอสักครู่');ws.retractTimes.push(Date.now());
              const result=chat.retract(ws.identity,message);
              for(const client of wss.clients)if(live(client)&&chat.allowed(chat.state.rooms[message.room],client.identity))send(client,{type:'chat-retracted',message:result.message,room:result.room});
              jobUpdate(chat.state.rooms[message.room]);
            } else if(message.type==='chat-job-detail') {
              if(message.before!==undefined&&(!Number.isInteger(message.before)||message.before<=0))throw new Error('ช่วงประวัติไม่ถูกต้อง');
              send(ws,{type:'chat-job-detail',...chat.detail(ws.identity,message.room,message.before)});
            } else if(message.type==='chat-job') {
              const room=chat.job(ws.identity,message);
              if(message.action==='mark_read'&&room.requesterRead?.mode==='admin')for(const client of wss.clients)if(live(client)&&chat.allowed(chat.state.rooms[room.id],client.identity))send(client,{type:'chat-read-cleared',room:room.id,seq:room.requesterRead.seq,reader:{key:chat.key(room.requester),name:room.requester.name},markedBy:room.requesterRead.override.by});
              jobUpdate(room);
            } else if(message.type==='chat-read') {
              chat.read(ws.identity,message.room,message.seq);
              for(const client of wss.clients)if(live(client)&&chat.allowed(chat.state.rooms[message.room],client.identity))send(client,{type:'chat-read',room:message.room,seq:message.seq,reader:{name:ws.identity.name,key:chat.key(ws.identity)}});
              if(chat.isJob(chat.state.rooms[message.room]))jobUpdate(chat.state.rooms[message.room]);
            } else {
              ws.chatTimes=(ws.chatTimes||[]).filter(t=>t>Date.now()-10000);
              if(ws.chatTimes.length>=(ws.identity.admin?30:8))throw new Error('ส่งข้อความถี่เกินไป กรุณารอสักครู่');ws.chatTimes.push(Date.now());
              if(message.target) {
                const target=[...wss.clients].find(c=>live(c)&&c.identity.system===message.target.system&&String(c.identity.uid)===String(message.target.uid));
                if(!target)throw new Error('ผู้ใช้ไม่ได้ออนไลน์ กรุณาใช้บทสนทนาเดิมเพื่อติดต่อ');
                message.target={system:target.identity.system,uid:String(target.identity.uid),name:target.identity.name};
              }
              const result=chat.send(ws.identity,message);const room=chat.state.rooms[result.message.room];const delivered=new Set();
              if(!result.duplicate)for(const client of wss.clients)if(live(client)&&chat.allowed(room,client.identity)){send(client,{type:'chat-message',message:result.message,room:chat.publicRoom(room),unread:chat.unread(client.identity,result.message)});if(chat.key(client.identity)!==chat.key(ws.identity))delivered.add(chat.key(client.identity));}
              send(ws,{type:'chat-sent',clientId:message.clientId,room:room.id,delivered:delivered.size});
              if(!result.duplicate)jobUpdate(room);
            }
          } catch(error) {send(ws,{type:'chat-error',clientId:message.clientId,error:['EACCES','EPERM','ENOSPC'].includes(error.code)?'บันทึกข้อความไม่ได้ กรุณาติดต่อผู้ดูแลระบบ':error.message});}
        } else if (message.type === 'logout') {
          fs.writeFileSync(path.join(directory, 'revoked', ws.identity.session), String(Date.now()));
          for (const client of wss.clients) if (client.identity && client.identity.session === ws.identity.session) { store.disconnect(client, 'logout', true); client.close(1008, 'Logged out'); }
          broadcast();
        }
      }
    } catch (_) { ws.close(1008, 'Invalid request or unauthorized'); }
  });
  ws.on('close', () => { ws.output = []; ws.outputBytes = 0; clearTimeout(deadline); store.disconnect(ws); broadcast(); roster(); });
});
const heartbeat = setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.identity) continue;
    if (ws.identity.exp <= Date.now() / 1000 || fs.existsSync(path.join(directory, 'revoked', ws.identity.session))) {
      let reason = 'session_expired', endedAt = Date.now();
      try { const record = JSON.parse(fs.readFileSync(path.join(directory, 'revoked', ws.identity.session), 'utf8')); if (record.reason === 'login_replaced') { reason = 'login_replaced'; if (Number.isFinite(record.at_ms)) endedAt = record.at_ms; } } catch (_) {}
      if (reason === 'login_replaced') send(ws, { type: 'session-ended', reason });
      store.disconnect(ws, reason, true, endedAt); ws.close(1008, 'Session ended'); continue;
    }
    if (!ws.alive) { ws.terminate(); continue; }
    ws.alive = false; ws.ping();
  }
  if (store) { store.tick(); broadcast(); }
}, 10000);
const port = Number(process.env.EIS_DOCS_PRESENCE_PORT || config.port || 4003);
// Bind before reading/recovering history: a duplicate process must not close live rounds.
server.listen(port, '127.0.0.1', () => {
  try {
    store = new PresenceStore(directory); chat = new ChatStore(directory);
    capabilities.conversations = typeof chat.storedRoom === 'function';
    console.log('Messenger presence: ' + transport + '://127.0.0.1:' + port);
  } catch (error) { console.error('Presence initialization failed:', error.code || error.name); shutdown(); process.exitCode = 1; }
});
server.on('error', error => { clearInterval(heartbeat); console.error('Presence listener failed:', error.code); process.exitCode = 1; });
function shutdown() { clearInterval(heartbeat); if (store) store.shutdown(); for (const ws of wss.clients) ws.terminate(); server.close(() => process.exit(process.exitCode || 0)); }
process.on('SIGINT', shutdown); process.on('SIGTERM', shutdown);

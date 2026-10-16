'use strict';
const fs = require('fs'), os = require('os'), path = require('path'), crypto = require('crypto');
const { spawn } = require('child_process');
const assert = require('node:assert/strict');
const { WebSocket } = require('ws');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'presence-production-'));
const secrets = {};
for (const name of ['eis-docs', 'supplies']) { secrets[name] = crypto.randomBytes(32).toString('hex'); fs.writeFileSync(path.join(directory, name + '.secret'), secrets[name]); }
fs.mkdirSync(path.join(directory, 'revoked')); fs.mkdirSync(path.join(directory, 'logins'));
const config = path.join(directory, 'server.config.json');
fs.writeFileSync(config, JSON.stringify({directory, transport:'http', port:4005, origins:['https://doc-eis.nmu.ac.th','https://eis.nmu.ac.th']}));
const env = {...process.env, EIS_PRESENCE_SERVER_CONFIG:config};
for (const key of ['EIS_DOCS_PRESENCE_DIR','EIS_DOCS_PRESENCE_PORT','EIS_PRESENCE_TRANSPORT']) delete env[key];
const child = spawn(process.execPath, [path.join(__dirname,'server.js')], {env, stdio:['ignore','pipe','pipe']});
let errors = ''; child.stderr.on('data', b => {errors += b;});
function connect(origin, system) {
  return new Promise((resolve,reject) => {
    const ws = new WebSocket('ws://127.0.0.1:4005/presence', {origin});
    const timer = setTimeout(() => {ws.terminate(); reject(new Error('WebSocket timeout'));}, 4000);
    ws.on('error', e => {clearTimeout(timer); reject(e);});
    ws.on('open', () => {
      const encoded = Buffer.from(JSON.stringify({system,uid:'123',name:'Fixture',department:'Fixture',session:crypto.randomBytes(32).toString('hex'),exp:Math.floor(Date.now()/1000)+60,admin:system==='EIS-DOCS'})).toString('base64url');
      const token = encoded + '.' + crypto.createHmac('sha256',secrets[system.toLowerCase()]).update(encoded).digest('hex');
      ws.send(JSON.stringify({type:'authenticate',token}));
    });
    ws.on('message', raw => {const msg = JSON.parse(raw); if (msg.type==='authenticated') {clearTimeout(timer); ws.close(); resolve();}});
  });
}
(async () => {
  try {
    let ready = false;
    for (let i=0;i<40;i++) {
      try { const response = await fetch('http://127.0.0.1:4005/health'); assert.equal((await response.json()).status,'ok'); ready=true; break; } catch (_) {await new Promise(r=>setTimeout(r,100));}
    }
    assert.ok(ready, errors || 'HTTP backend not ready');
    await connect('https://doc-eis.nmu.ac.th','EIS-DOCS');
    await connect('https://eis.nmu.ac.th','SUPPLIES');
    await assert.rejects(connect('https://untrusted.example','SUPPLIES'), /403/);
    await assert.rejects(connect('https://dev-eis.nmu.ac.th','SUPPLIES'), /403/);
    console.log('PASS: production HTTP backend, both production origins and authentication; untrusted/dev origins rejected');
  } finally {
    child.kill(); await new Promise(resolve => {if (child.exitCode !== null) resolve(); else child.once('exit',resolve);});
    fs.rmSync(directory,{recursive:true,force:true});
  }
})().catch(e=>{console.error(e.message);process.exitCode=1;});

'use strict';
// Temporary PHP sessions and an isolated presence service: no real accounts,
// document changes, or test users in the live presence history.
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const https = require('https');
const crypto = require('crypto');
const { spawn, spawnSync } = require('child_process');
const { WebSocket } = require('ws');
const data = 'D:/EIS-DOCS/storage/presence';
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'docs-supplies-integration-'));
const sockets = [], sessions = [], liveMarkers = [];
const fixtureUid = crypto.randomInt(1000000000, 2000000000);
fs.mkdirSync(path.join(directory, 'revoked'));
for (const name of ['local.key', 'local.crt', 'eis-docs.secret', 'supplies.secret']) fs.copyFileSync(path.join(data, name), path.join(directory, name));
const service = spawn(process.execPath, ['server.js'], { cwd: __dirname, env: { ...process.env, EIS_DOCS_PRESENCE_DIR: directory, EIS_DOCS_PRESENCE_PORT: '4004' }, stdio: ['ignore', 'pipe', 'pipe'] });
function php(code) {
  const result = spawnSync('C:/php/php.exe', ['-r', code], { encoding: 'utf8' });
  if (/session_(?:start|write_close|save_path|id|name)\(\)/.test(result.stderr)) throw new Error(result.stderr);
  assert.equal(result.status, 0, result.stderr); return result.stdout;
}
function seed(system, admin = false, impersonating = false) {
  const uid = fixtureUid + (system === 'EIS-DOCS' && admin ? (impersonating ? 2 : 1) : 0);
  const sid = crypto.randomBytes(24).toString('hex'), cookie = system === 'SUPPLIES' ? 'NMU_SUPPLIES_SESSID' : 'EIS_DOCS_SESSION';
  const sessionDirectory = system === 'SUPPLIES' ? 'D:/EIS-ERP/storage/sessions' : php('echo ini_get("session.save_path") ?: sys_get_temp_dir();').trim().replaceAll('\\', '/');
  const user = system === 'SUPPLIES'
    ? `['user_id'=>'${uid}','user_name'=>'Presence integration test','cost_name'=>'Test only','last_login'=>'${sid}','last_activity'=>time()-300]`
    : `['auth'=>['user_id'=>'${uid}','full_name'=>'Presence integration test','cost_name'=>'Test only','user_type'=>${admin ? 2 : 1},'login_at'=>'${sid}']${impersonating ? ", 'impersonation_admin'=>['user_type'=>2]" : ''}]`;
  php(`session_save_path('${sessionDirectory}'); session_name('${cookie}'); session_id('${sid}'); session_start(); $_SESSION=${user}; session_write_close();`);
  const files = [path.join(sessionDirectory, 'sess_' + sid)];
  // IIS runs with a different TEMP environment than this CLI user.
  if (system === 'EIS-DOCS' && sessionDirectory.toLowerCase() !== 'c:/windows/temp') {
    const iisFile = path.join('C:/Windows/Temp', 'sess_' + sid);
    fs.copyFileSync(files[0], iisFile, fs.constants.COPYFILE_EXCL); files.push(iisFile);
    const acl = spawnSync('icacls.exe', [iisFile, '/grant', 'IIS_IUSRS:M', os.userInfo().username + ':M'], { encoding: 'utf8' });
    assert.equal(acl.status, 0, acl.stderr);
  }
  const value = { sid, cookie, directory: sessionDirectory, files, system, uid }; sessions.push(value); return value;
}
function request(route, session, options = {}) {
  return new Promise((resolve, reject) => {
    const payload = options.form ? new URLSearchParams(options.form).toString() : null;
    const req = https.request({ hostname: 'dev-eis.nmu.ac.th', port: 443, path: route, rejectUnauthorized: false, method: payload ? 'POST' : 'GET', headers: { ...(session ? { Cookie: session.cookie + '=' + session.sid } : {}), ...(payload ? { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(payload) } : {}) } }, res => {
      let body = ''; res.on('data', chunk => { body += chunk; }); res.on('end', () => {
        if (res.statusCode === 301 && res.headers.location) return request(new URL(res.headers.location, 'https://dev-eis.nmu.ac.th').pathname, session, options).then(resolve, reject);
        resolve({ status: res.statusCode, body: body.replace(/^\uFEFF/, ''), headers: res.headers });
      });
    }); req.on('error', reject); req.setTimeout(10000, () => req.destroy(new Error('HTTP timeout: ' + route)));
    if (payload) req.write(payload); req.end();
  });
}
function connect(token, observer = false) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket('wss://127.0.0.1:4004/presence', { ca: fs.readFileSync(path.join(data, 'local.crt')), origin: 'https://dev-eis.nmu.ac.th' });
    sockets.push(ws); let authenticated = false;
    const timer = setTimeout(() => reject(new Error('Authentication timeout')), 5000);
    ws.on('open', () => ws.send(JSON.stringify({ type: 'authenticate', token, observer })));
    ws.on('message', raw => { if (JSON.parse(raw).type === 'authenticated') { authenticated = true; clearTimeout(timer); resolve(ws); } });
    ws.on('close', code => { clearTimeout(timer); if (!authenticated) reject(new Error('Rejected ' + code)); });
    ws.on('error', error => { clearTimeout(timer); reject(error); });
  });
}
function snapshot(ws, system = '') {
  const day = new Date(Date.now() + 7 * 3600000).toISOString().slice(0, 10);
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { ws.off('message', listener); reject(new Error('Snapshot timeout')); }, 5000);
    const listener = raw => { const value = JSON.parse(raw); if (value.type === 'snapshot') { clearTimeout(timer); ws.off('message', listener); resolve(value); } };
    ws.on('message', listener); ws.send(JSON.stringify({ type: 'subscribe', filters: { start: day, end: day, system } }));
  });
}
function decode(token) { return JSON.parse(Buffer.from(token.split('.')[0], 'base64url')); }
function sign(payload, secret) { const value = Buffer.from(JSON.stringify(payload)).toString('base64url'); return value + '.' + crypto.createHmac('sha256', secret).update(value).digest('hex'); }
function editSession(session, code) {
  const sessionDirectory = session.system === 'EIS-DOCS' ? 'C:/Windows/Temp' : session.directory;
  php(`session_save_path('${sessionDirectory}'); session_name('${session.cookie}'); session_id('${session.sid}'); session_start(); ${code}; session_write_close();`);
}
function confirmedReplacement(session) {
  const file = crypto.createHash('sha256').update(session.system + '|' + session.uid).digest('hex') + '.json';
  const record = JSON.parse(fs.readFileSync(path.join(data, 'logins', file), 'utf8'));
  const instance = php(`$_SERVER['HTTP_HOST']='dev-eis.nmu.ac.th'; require 'D:/EIS-DOCS/app/src/Presence.php'; require 'D:/EIS-DOCS/app/src/SingleLogin.php'; echo SingleLogin::start('${session.system}','${session.uid}',null,'${record.instance}');`).trim();
  assert.match(instance, /^[a-f0-9]{64}$/);
  liveMarkers.push(record.presence_key);
  fs.copyFileSync(path.join(data, 'revoked', record.presence_key), path.join(directory, 'revoked', record.presence_key));
  const replacement = seed(session.system);
  editSession(replacement, session.system === 'EIS-DOCS' ? `$_SESSION['auth']['login_instance']='${instance}'` : `$_SESSION['login_instance']='${instance}'`);
  return replacement;
}
async function token(session) {
  const route = session.system === 'SUPPLIES' ? '/supplies/access/presence-token.php' : '/eis-docs/docMain/presence-token.php';
  const response = await request(route, session); assert.equal(response.status, 200, response.body); return JSON.parse(response.body).token;
}
async function main() {
  await new Promise((resolve, reject) => { service.stdout.once('data', resolve); service.once('exit', code => reject(new Error('Service failed ' + code))); service.stderr.once('data', chunk => reject(new Error(String(chunk)))); });
  const docsAdmin = seed('EIS-DOCS', true), docsUser = seed('EIS-DOCS'), suppliesUser = seed('SUPPLIES'), impersonated = seed('EIS-DOCS', true, true);
  assert.equal((await request('/eis-docs/docMain/presence-token.php')).status, 401);
  assert.equal((await request('/supplies/access/presence-token.php')).status, 401);
  assert.equal((await request('/eis-docs/docMain/user-online.php', docsUser)).status, 403);
  assert.equal((await request('/eis-docs/docMain/user-online.php', impersonated)).status, 403);
  assert.equal((await request('/eis-docs/docMain/message-inbox.php', docsUser)).status, 403);
  assert.equal((await request('/eis-docs/docMain/message-inbox.php', impersonated)).status, 403);
  const inboxPage=await request('/eis-docs/docMain/message-inbox.php', docsAdmin);assert.equal(inboxPage.status,200);assert.ok(inboxPage.body.includes('id="support-inbox"'));assert.ok(inboxPage.body.includes('id="bulk-form"'));
  assert.ok(inboxPage.body.includes('message-report.php'));assert.ok(inboxPage.body.includes('id="reply-question"'));
  assert.equal((await request('/eis-docs/docMain/message-report.php',docsUser)).status,403);
  assert.equal((await request('/eis-docs/docMain/message-report.php',impersonated)).status,403);
  const reportPage=await request('/eis-docs/docMain/message-report.php',docsAdmin);assert.equal(reportPage.status,200);assert.ok(reportPage.body.includes('id="report-export"'));
  const page = await request('/eis-docs/docMain/user-online.php', docsAdmin); assert.equal(page.status, 200); assert.ok(page.body.includes('id="system"')); assert.ok(!page.body.includes('/NMU_EIS/'));
  const adminToken = await token(docsAdmin), docsToken = await token(docsUser), suppliesToken = await token(suppliesUser);
  assert.equal(decode(suppliesToken).system, 'SUPPLIES'); assert.equal(decode(suppliesToken).admin, false);
  assert.equal(decode(await token(impersonated)).admin, false);
  const activity = Number(php(`session_save_path('${suppliesUser.directory}'); session_name('${suppliesUser.cookie}'); session_id('${suppliesUser.sid}'); session_start(); echo $_SESSION['last_activity']; session_write_close();`));
  assert.ok(activity < Date.now() / 1000 - 290, 'Passive heartbeat must not extend Supplies idle timeout');
  const mainPage = await request('/eis-docs/docMain/', docsAdmin);
  assert.equal(mainPage.status, 200); assert.ok(mainPage.body.includes('href="user-online.php"')); assert.ok(mainPage.body.includes('/eis-docs/assets/js/presence-client.js')); assert.ok(!mainPage.body.includes('/NMU_EIS/access/user_online.php'));
  const suppliesPage = await request('/supplies/', suppliesUser);
  assert.equal(suppliesPage.status, 200); assert.ok(suppliesPage.body.includes('/supplies/access/presence-token.php')); assert.ok(suppliesPage.body.includes('/supplies/assets/js/presence-client.js'));
  // Render both confirmation screens and check CSRF/cancel without real credentials.
  const docsPending = seed('EIS-DOCS'), suppliesPending = seed('SUPPLIES');
  const csrf = crypto.randomBytes(32).toString('hex'), nonce = crypto.randomBytes(32).toString('hex');
  editSession(docsPending, `$_SESSION=['login_csrf'=>'${csrf}','docs_login_pending'=>['username'=>'Test only','nonce'=>'${nonce}','expires'=>time()+120]]`);
  let confirmationPage = await request('/eis-docs/login', docsPending); assert.equal(confirmationPage.status, 200); assert.ok(confirmationPage.body.includes('ยืนยันเข้าสู่ระบบแทนที่เซสชันเดิม'), 'EIS-DOCS confirmation form missing: ' + (confirmationPage.body.match(/<title>(.*?)<\/title>/)?.[1] || 'No title') + ', pending field=' + confirmationPage.body.includes('name="confirmation"'));
  const badDocs = await request('/eis-docs/login', docsPending, { form: { action: 'replace', csrf: 'bad', confirmation: nonce } }); assert.ok(badDocs.body.includes('คำขอหมดอายุ'));
  await request('/eis-docs/login', docsPending, { form: { action: 'cancel', csrf } }); assert.equal((await request('/eis-docs/docMain/presence-token.php', docsUser)).status, 200);
  editSession(suppliesPending, `$_SESSION=['supplies_confirmation_csrf'=>'${csrf}','supplies_login_pending'=>['row'=>['dc_user_id'=>${suppliesUser.uid},'c_full_name'=>'Test only'],'nonce'=>'${nonce}','expires'=>time()+120]]`);
  confirmationPage = await request('/supplies/access/confirm-login.php', suppliesPending); assert.equal(confirmationPage.status, 200); assert.ok(confirmationPage.body.includes('ยืนยันเข้าสู่ระบบแทนที่เซสชันเดิม'), 'Supplies confirmation form missing');
  editSession(suppliesPending, `$_SESSION['supplies_login_pending']['admin']=true`);
  confirmationPage = await request('/supplies/access/confirm-login.php', suppliesPending); assert.ok(confirmationPage.body.includes('name="admin_operator"') && confirmationPage.body.includes('เข้าสู่ระบบด้วยชื่อผู้ดูแล'));
  fs.writeFileSync('C:/xampp/var/supplies-admin-preview.html', confirmationPage.body.replace('/supplies/assets/css/admin-login.css','https://dev-eis.nmu.ac.th/supplies/assets/css/admin-login.css'));
  editSession(docsPending, `$_SESSION=['login_csrf'=>'${csrf}','docs_login_pending'=>['admin'=>true,'username'=>'Test only','owner'=>'','nonce'=>'${nonce}','expires'=>time()+120]]`);
  confirmationPage = await request('/eis-docs/login', docsPending); assert.ok(confirmationPage.body.includes('name="admin_operator"') && confirmationPage.body.includes('เข้าสู่ระบบด้วยชื่อผู้ดูแล'));
  assert.equal((await request('/supplies/access/confirm-login.php', suppliesPending, { form: { action: 'replace', csrf: 'bad', confirmation: nonce } })).status, 403);
  assert.equal((await request('/supplies/access/confirm-login.php', suppliesPending, { form: { action: 'cancel', csrf } })).status, 302);
  assert.equal((await request('/supplies/access/presence-token.php', suppliesUser)).status, 200);
  const admin = await connect(adminToken, true), docs = await connect(docsToken), suppliesA = await connect(suppliesToken), suppliesB = await connect(suppliesToken);
  let state = await snapshot(admin); assert.equal(state.summary.online, 2); assert.equal(state.summary.rounds, 2); assert.deepEqual(state.summary.onlineBySystem, { 'EIS-DOCS': 1, SUPPLIES: 1 });
  assert.equal(state.users.find(u => u.system === 'SUPPLIES').tabs, 2);
  state = await snapshot(admin, 'SUPPLIES'); assert.equal(state.users.length, 1); assert.ok(state.sessions.every(s => s.system === 'SUPPLIES'));
  await assert.rejects(connect(suppliesToken, true)); await assert.rejects(connect(docsToken, true));
  const forgedAdmin = sign({ ...decode(suppliesToken), admin: true }, fs.readFileSync(path.join(data, 'supplies.secret'), 'utf8').trim());
  await assert.rejects(connect(forgedAdmin, true));
  const oldEisSecret = fs.readFileSync('C:/xampp/var/nmu-eis-presence/secret.txt', 'utf8').trim();
  await assert.rejects(connect(sign({ ...decode(adminToken), system: 'EIS' }, oldEisSecret)));
  await assert.rejects(connect(sign(decode(adminToken), oldEisSecret), true));
  // A logged-in user cannot subscribe to the administrative stream.
  const unauthorized = await connect(suppliesToken);
  await new Promise(resolve => { unauthorized.once('close', resolve); unauthorized.send(JSON.stringify({ type: 'subscribe', filters: {} })); });
  await new Promise(resolve => { suppliesA.once('close', resolve); suppliesA.close(); });
  state = await snapshot(admin); assert.equal(state.summary.onlineBySystem.SUPPLIES, 1);
  await new Promise(resolve => { suppliesB.once('close', resolve); suppliesB.close(); });
  let reconnected = await connect(suppliesToken); state = await snapshot(admin); assert.equal(state.summary.rounds, 2);
  const oldDocsClosed = new Promise(resolve => docs.once('close', resolve));
  const incomingDocs = confirmedReplacement(docsUser), incomingDocsToken = await token(incomingDocs);
  await connect(incomingDocsToken); await oldDocsClosed;
  const oldDocsDenied = await request('/eis-docs/docMain/presence-token.php', docsUser); assert.equal(oldDocsDenied.status, 401, 'Old EIS-DOCS PHP session must be denied'); assert.equal(JSON.parse(oldDocsDenied.body).code, 'SESSION_REPLACED');
  assert.equal((await request('/supplies/access/presence-token.php', suppliesUser)).status, 200);
  state = await snapshot(admin); assert.ok(state.sessions.some(s => s.system === 'EIS-DOCS' && s.reason === 'login_replaced'));
  const oldSuppliesClosed = new Promise(resolve => reconnected.once('close', resolve));
  const incomingSupplies = confirmedReplacement(suppliesUser), incomingSuppliesToken = await token(incomingSupplies);
  reconnected = await connect(incomingSuppliesToken); await oldSuppliesClosed;
  const oldSuppliesDenied = await request('/supplies/access/presence-token.php', suppliesUser); assert.equal(oldSuppliesDenied.status, 401, 'Old Supplies PHP session must be denied'); assert.equal(JSON.parse(oldSuppliesDenied.body).code, 'SESSION_REPLACED');
  assert.equal((await request('/supplies/access/keep_alive.php', suppliesUser)).status, 401, 'Subsequent old Supplies API requests must remain denied');
  assert.equal((await request('/eis-docs/docMain/presence-token.php', incomingDocs)).status, 200);
  state = await snapshot(admin); assert.ok(state.sessions.some(s => s.system === 'SUPPLIES' && s.reason === 'login_replaced'));
  await new Promise(resolve => { reconnected.once('close', resolve); reconnected.send(JSON.stringify({ type: 'logout' })); });
  state = await snapshot(admin); assert.equal(state.summary.onlineBySystem.SUPPLIES, 0); assert.equal(state.summary.onlineBySystem['EIS-DOCS'], 1);
  await assert.rejects(connect(suppliesToken));
  await request('/eis-docs/logout.php', docsUser);
  const key = decode(docsToken).session; liveMarkers.push(key);
  assert.ok(fs.existsSync(path.join(data, 'revoked', key))); assert.equal((await request('/eis-docs/docMain/presence-token.php', docsUser)).status, 401);
  console.log('PASS: actual IIS/PHP session authentication, admin rights, source isolation, confirmation screens, CSRF/cancel, duplicate replacement, old PHP/API/WebSocket denial, independent systems, idle timeout, reconnect and logout');
}
main().catch(error => { console.error(error.message); process.exitCode = 1; }).finally(async () => {
  for (const ws of sockets) ws.terminate();
  await new Promise(resolve => { service.once('exit', resolve); service.kill(); });
  for (const session of sessions) for (const file of session.files) if (fs.existsSync(file)) fs.unlinkSync(file);
  for (const key of liveMarkers) { const file = path.join(data, 'revoked', key); if (fs.existsSync(file)) fs.unlinkSync(file); }
  for (const session of sessions) { const key = crypto.createHash('sha256').update(session.system + '|' + session.uid).digest('hex'); const file = path.join(data, 'logins', key + '.json'); if (fs.existsSync(file)) fs.unlinkSync(file); }
  fs.rmSync(directory, { recursive: true, force: true });
});

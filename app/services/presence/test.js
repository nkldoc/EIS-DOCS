'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { PresenceStore } = require('./store');
function fixture(t, start = Date.parse('2026-10-09T08:00:00+07:00')) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'eis-presence-test-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  let time = start;
  const store = new PresenceStore(directory, () => time);
  return { store, directory, advance: ms => { time += ms; }, identity: { uid: 'test-user', name: 'Test', department: 'Test' }, filters: { start: '2026-10-09', end: '2026-10-09' } };
}
test('multiple tabs and devices count one interval; duplicate disconnect is harmless', t => {
  const f = fixture(t), a = {}, b = {};
  f.store.connect(a, f.identity, '127.0.0.1'); f.advance(10000); f.store.connect(b, f.identity, '127.0.0.1'); f.advance(10000);
  f.store.disconnect(a); f.store.disconnect(a);
  let s = f.store.snapshot(f.filters); assert.equal(s.summary.online, 1); assert.equal(s.users[0].tabs, 1); assert.equal(s.summary.seconds, 20);
  f.store.disconnect(b); f.advance(31000); f.store.tick();
  s = f.store.snapshot(f.filters); assert.equal(s.summary.online, 0); assert.equal(s.summary.seconds, 20); assert.equal(s.summary.rounds, 1);
});
test('reconnect inside grace keeps a round; later reconnect creates another', t => {
  const f = fixture(t), a = {}, b = {}, c = {};
  f.store.connect(a, f.identity); f.advance(1000); f.store.disconnect(a); f.advance(10000); f.store.connect(b, f.identity);
  assert.equal(f.store.snapshot(f.filters).summary.rounds, 1);
  f.store.disconnect(b); f.advance(31000); f.store.connect(c, f.identity);
  assert.equal(f.store.snapshot(f.filters).summary.rounds, 2);
});
test('logout closes final connection immediately, preserving another device', t => {
  const f = fixture(t), a = {}, b = {};
  f.store.connect(a, f.identity); f.store.connect(b, f.identity); f.advance(2000); f.store.disconnect(a, 'logout', true);
  assert.equal(f.store.snapshot(f.filters).summary.online, 1);
  f.store.disconnect(b, 'logout', true);
  assert.equal(f.store.sessions[0].reason, 'logout'); assert.equal(f.store.snapshot(f.filters).summary.seconds, 2);
});
test('cross-midnight intervals clip to Bangkok dates', t => {
  const f = fixture(t, Date.parse('2026-10-08T23:59:50+07:00')), a = {};
  f.store.connect(a, f.identity); f.advance(20000); f.store.disconnect(a, 'logout', true);
  assert.equal(f.store.snapshot(f.filters).summary.seconds, 10);
  assert.equal(f.store.snapshot({ start: '2026-10-08', end: '2026-10-08' }).summary.seconds, 10);
});
test('restart closes history at saved heartbeat, excluding downtime', t => {
  const f = fixture(t), a = {};
  f.store.connect(a, f.identity); f.advance(10000); f.store.tick(); f.advance(60000);
  const restored = new PresenceStore(f.directory, () => Date.parse('2026-10-09T09:00:00+07:00'));
  assert.equal(restored.snapshot(f.filters).summary.seconds, 10); assert.equal(restored.sessions[0].reason, 'server_restart');
});
test('a completed round ending exactly at midnight is excluded from the next day', t => {
  const f = fixture(t, Date.parse('2026-10-08T23:59:50+07:00')), a = {};
  f.store.connect(a, f.identity); f.advance(10000); f.store.disconnect(a, 'logout', true);
  assert.equal(f.store.snapshot(f.filters).summary.rounds, 0);
});
test('date validation rejects impossible dates and excessive ranges', t => {
  const f = fixture(t);
  assert.throws(() => f.store.validateFilters({ start: '2026-02-30', end: '2026-03-01' }));
  assert.throws(() => f.store.validateFilters({ start: '2026-10-09', end: '2026-10-08' }));
  assert.throws(() => f.store.validateFilters({ start: '2025-01-01', end: '2026-10-09' }));
});
test('corrupted history fails instead of silently deleting audit records', t => {
  const f = fixture(t); fs.writeFileSync(path.join(f.directory, 'history.json'), '{broken');
  assert.throws(() => new PresenceStore(f.directory));
});
test('transient Windows file lock is retried without losing history', t => {
  const f = fixture(t), rename = fs.renameSync; let failures = 0;
  fs.renameSync = (...args) => { if (failures++ < 2) { const e = new Error('Locked'); e.code = 'EPERM'; throw e; } return rename(...args); };
  try { f.store.connect({}, f.identity); assert.equal(f.store.lastError, null); assert.equal(JSON.parse(fs.readFileSync(f.store.file, 'utf8')).length, 1); }
  finally { fs.renameSync = rename; }
});
test('identical IDs across systems keep independent rounds, tabs and totals', t => {
  const f = fixture(t), docs = {}, supplies = {}, second = {};
  f.store.connect(docs, { ...f.identity, system: 'EIS-DOCS' }); f.advance(10000);
  f.store.connect(supplies, { ...f.identity, system: 'SUPPLIES' });
  f.store.connect(second, { ...f.identity, system: 'SUPPLIES' }); f.advance(10000);
  const s = f.store.snapshot(f.filters);
  assert.equal(s.summary.online, 2); assert.equal(s.summary.rounds, 2);
  assert.deepEqual(s.summary.onlineBySystem, { 'EIS-DOCS': 1, SUPPLIES: 1, PROCURE: 0, 'NMU-EIS': 0 });
  assert.equal(s.users.find(u => u.system === 'EIS-DOCS').seconds, 20);
  assert.equal(s.users.find(u => u.system === 'SUPPLIES').seconds, 10);
  f.store.disconnect(supplies, 'logout', true);
  assert.equal(f.store.snapshot(f.filters).users.find(u => u.system === 'SUPPLIES').tabs, 1);
  assert.equal(f.store.snapshot({ ...f.filters, system: 'SUPPLIES' }).users.length, 1);
});
test('EIS identities and filters are rejected and EIS records are excluded', t => {
  const f = fixture(t);
  assert.throws(() => f.store.connect({}, { ...f.identity, system: 'EIS' }));
  assert.throws(() => f.store.validateFilters({ ...f.filters, system: 'EIS' }));
  f.store.sessions.push({ ...f.identity, system: 'EIS', start: Date.parse('2026-10-09T08:00:00+07:00'), end: Date.parse('2026-10-09T09:00:00+07:00') });
  assert.equal(f.store.snapshot(f.filters).users.length, 0);
});
test('a confirmed replacement opens a new round even during reconnect grace', t => {
  const f = fixture(t), a = {}, b = {};
  f.store.connect(a, { ...f.identity, session: 'old-login' }); f.advance(10000); f.store.disconnect(a);
  f.advance(5000); f.store.connect(b, { ...f.identity, session: 'new-login' });
  const snapshot = f.store.snapshot(f.filters);
  assert.equal(snapshot.summary.rounds, 2); assert.equal(snapshot.sessions[1].reason, 'login_replaced');
  assert.equal(snapshot.sessions[1].seconds, 10); assert.equal(snapshot.summary.online, 1);
});

test('Procure identities remain separate from Supplies and filter independently', t => {
  const f = fixture(t);
  f.store.connect({}, { ...f.identity, system: 'SUPPLIES' });
  f.store.connect({}, { ...f.identity, system: 'PROCURE' });
  const snapshot = f.store.snapshot({ ...f.filters, system: 'PROCURE' });
  assert.equal(snapshot.users.length, 1);
  assert.equal(snapshot.users[0].system, 'PROCURE');
  assert.equal(snapshot.summary.onlineBySystem.PROCURE, 1);
});

test('NMU-EIS identities and filters are independent of other applications', t => {
  const f = fixture(t);
  for (const system of ['NMU-EIS','SUPPLIES','PROCURE']) f.store.connect({}, { ...f.identity, system });
  const snapshot = f.store.snapshot({ ...f.filters, system: 'NMU-EIS' });
  assert.equal(snapshot.users.length, 1);assert.equal(snapshot.users[0].system, 'NMU-EIS');
  assert.equal(snapshot.summary.onlineBySystem['NMU-EIS'], 1);
});

test('snapshot tab counts do not scan connections once per history row', t => {
  const f = fixture(t);f.store.connect({}, f.identity);
  for(let i=0;i<1000;i++)f.store.sessions.push({...f.store.sessions[0],end:f.store.now(),reason:'fixture'});
  f.store.count=()=>{throw new Error('Repeated connection scan');};
  const snapshot=f.store.snapshot(f.filters);assert.equal(snapshot.users.length,1);assert.equal(snapshot.users[0].tabs,1);
});

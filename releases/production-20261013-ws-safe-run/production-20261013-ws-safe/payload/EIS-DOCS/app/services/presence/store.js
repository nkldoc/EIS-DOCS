'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
class PresenceStore {
  constructor(directory, now = () => Date.now(), grace = 30000) {
    this.file = path.join(directory, 'history.json'); this.now = now; this.grace = grace; this.connections = new Map(); this.pending = new Map(); this.revision = 0;
    fs.mkdirSync(directory, { recursive: true });
    // Fail on invalid history instead of silently discarding audit records.
    this.sessions = fs.existsSync(this.file) ? JSON.parse(fs.readFileSync(this.file, 'utf8')) : [];
    for (const session of this.sessions) if (session.end === null) { session.end = session.lastSeen; session.reason = 'server_restart'; }
    if (!this.save()) throw new Error('Unable to persist presence history');
  }
  save() {
    try {
      fs.writeFileSync(this.file + '.new', JSON.stringify(this.sessions));
      // Windows scanners/readers can briefly hold a file without delete sharing.
      for (let attempt = 0; ; attempt++) {
        try { fs.renameSync(this.file + '.new', this.file); break; }
        catch (error) {
          if (!['EPERM', 'EACCES', 'EBUSY'].includes(error.code) || attempt >= 10) throw error;
          Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 50);
        }
      }
      this.lastError = null; this.revision++; return true;
    } catch (error) {
      if (!this.lastError) console.error('Presence history could not be saved:', error.code);
      this.lastError = error.code || 'storage_error'; return false;
    }
  }
  key(identity) { return JSON.stringify([identity.system || 'EIS-DOCS', identity.uid]); }
  active(key) { return this.sessions.findLast(s => this.key(s) === key && s.end === null); }
  count(key) { return [...this.connections.values()].filter(i => this.key(i) === key).length; }
  connect(connection, identity, ip) {
    const now = this.now(); this.expirePending();
    identity = { ...identity, system: identity.system || 'EIS-DOCS' };
    if (!['EIS-DOCS', 'SUPPLIES', 'PROCURE', 'NMU-EIS'].includes(identity.system)) throw new Error('Unsupported system');
    const key = this.key(identity);
    this.connections.set(connection, identity); this.pending.delete(key);
    let session = this.active(key);
    if (session && session.loginKey && identity.session && session.loginKey !== identity.session) {
      session.end = session.lastSeen; session.reason = 'login_replaced'; session = null;
    }
    if (!session) { session = { id: crypto.randomUUID(), loginKey: identity.session || null, system: identity.system, uid: identity.uid, name: identity.name, department: identity.department, start: now, end: null, lastSeen: now, reason: null, ip }; this.sessions.push(session); }
    else if (!session.loginKey) session.loginKey = identity.session || null;
    session.lastSeen = now; this.save();
  }
  disconnect(connection, reason = 'connection_closed', immediate = false, endedAt = this.now()) {
    const identity = this.connections.get(connection); if (!identity) return;
    this.connections.delete(connection);
    const key = this.key(identity);
    if (this.count(key)) return;
    const session = this.active(key); if (!session) return;
    const now = Math.max(session.start, Math.min(this.now(), endedAt));
    session.lastSeen = now;
    if (immediate) { session.end = now; session.reason = reason; this.pending.delete(key); }
    else this.pending.set(key, { end: now, reason });
    this.save();
  }
  expirePending() {
    let changed = false;
    for (const [uid, pending] of this.pending) if (this.now() - pending.end >= this.grace) {
      const session = this.active(uid); if (session) { session.end = pending.end; session.reason = pending.reason; changed = true; }
      this.pending.delete(uid);
    }
    return changed;
  }
  tick() { this.expirePending(); for (const s of this.sessions) if (s.end === null && this.count(this.key(s))) s.lastSeen = this.now(); this.save(); }
  shutdown() { for (const s of this.sessions) if (s.end === null) { s.end = this.pending.get(this.key(s))?.end ?? this.now(); s.reason = 'server_stop'; } this.save(); }
  validateFilters(value = {}) {
    const today = new Date(this.now() + 7 * 3600000).toISOString().slice(0, 10);
    const start = value.start || today, end = value.end || today;
    function validDate(date) { return typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date) && !Number.isNaN(Date.parse(date)) && new Date(date).toISOString().slice(0, 10) === date; }
    if (!validDate(start) || !validDate(end) || start > end) throw new Error('Invalid dates');
    if (Date.parse(end) - Date.parse(start) > 366 * 86400000) throw new Error('Maximum 367 days');
    const system = value.system || '';
    if (!['', 'EIS-DOCS', 'SUPPLIES', 'PROCURE', 'NMU-EIS'].includes(system)) throw new Error('Unsupported system');
    return { start, end, system };
  }
  snapshot(filters) {
    const counts = new Map();
    for (const identity of this.connections.values()) { const key = this.key(identity); counts.set(key, (counts.get(key) || 0) + 1); }
    const count = key => counts.get(key) || 0;
    const now = this.now(), from = Date.parse(filters.start + 'T00:00:00+07:00'), to = Date.parse(filters.end + 'T00:00:00+07:00') + 86400000;
    const all = this.sessions.filter(s => ['EIS-DOCS', 'SUPPLIES', 'PROCURE', 'NMU-EIS'].includes(s.system) && (!filters.system || s.system === filters.system)).map(({ loginKey, ...s }) => ({ ...s, effectiveEnd: s.end ?? this.pending.get(this.key(s))?.end ?? now }));
    const history = all.filter(s => s.start < to && (s.effectiveEnd > from || (s.end === null && s.start >= from))).map(s => ({ ...s, seconds: Math.max(0, Math.min(s.effectiveEnd, to) - Math.max(s.start, from)) / 1000, online: s.end === null && count(this.key(s)) > 0 }));
    const users = new Map();
    for (const s of history) {
      const key = this.key(s); let u = users.get(key);
      if (!u) { u = { uid: s.uid, system: s.system, name: s.name, department: s.department, seconds: 0, rounds: 0, tabs: count(key), latest: s, online: count(key) > 0 }; users.set(key, u); }
      u.seconds += s.seconds; u.rounds++; if (s.start >= u.latest.start) { u.latest = s; u.name = s.name; u.department = s.department; }
    }
    const list = [...users.values()].sort((a, b) => Number(b.online) - Number(a.online) || b.latest.start - a.latest.start);
    const connected = [...this.connections.values()];
    const onlineBySystem = Object.fromEntries(['EIS-DOCS', 'SUPPLIES', 'PROCURE', 'NMU-EIS'].map(system => [system, new Set(connected.filter(i => i.system === system).map(i => i.uid)).size]));
    return { revision: this.revision, serverTime: now, storageWarning: !!this.lastError, filters, users: list, sessions: history.reverse(), summary: { onlineBySystem, online: new Set(connected.filter(i => !filters.system || i.system === filters.system).map(i => this.key(i))).size, users: list.length, rounds: history.length, seconds: list.reduce((n, u) => n + u.seconds, 0) } };
  }
}
module.exports = { PresenceStore };

(function () {
  'use strict';
  var snapshot, receivedAt = 0, connected = false, currentTab = 'users', statusNode = document.getElementById('connection');
  var today = new Date(Date.now() + 7 * 3600000).toISOString().slice(0, 10);
  document.getElementById('start').value = document.getElementById('end').value = today;
  function duration(seconds) { seconds = Math.max(0, Math.floor(seconds)); return [Math.floor(seconds / 3600), Math.floor(seconds % 3600 / 60), seconds % 60].map(n => String(n).padStart(2, '0')).join(':'); }
  function date(value) { return value == null ? '—' : new Date(value).toLocaleString('th-TH', { timeZone: 'Asia/Bangkok', hour12: false }); }
  function increment(session) {
    if (!connected || !session.online) return 0;
    var end = Date.parse(snapshot.filters.end + 'T00:00:00+07:00') + 86400000;
    return Math.max(0, Math.min(snapshot.serverTime + Date.now() - receivedAt, end) - Math.min(snapshot.serverTime, end)) / 1000;
  }
  function render() {
    if (!snapshot) return;
    var summary = snapshot.summary;
    document.getElementById('online').textContent = summary.online + ' คน';
    document.getElementById('users').textContent = summary.users + ' คน';
    document.getElementById('rounds').textContent = summary.rounds + ' รอบ';
    document.getElementById('total').textContent = duration(summary.seconds + snapshot.sessions.reduce((n, s) => n + increment(s), 0));
    var labels = currentTab === 'users' ? ['ผู้ใช้ / รหัส', 'หน่วยงาน', 'สถานะปัจจุบัน', 'เริ่มรอบล่าสุดในช่วง', 'ออกรอบล่าสุดในช่วง', 'เวลารอบล่าสุดในช่วง', 'เวลารวมในช่วง', 'แท็บ'] : ['ผู้ใช้ / รหัส', 'หน่วยงาน', 'สถานะรอบ', 'เวลาเริ่ม', 'เวลาออก', 'เวลาในช่วงที่เลือก', 'เหตุผล', 'IP'];
    var head = document.getElementById('head'), tr = document.createElement('tr'); head.replaceChildren();
    labels.forEach(label => { var th = document.createElement('th'); th.textContent = label; tr.appendChild(th); }); head.appendChild(tr);
    var query = document.getElementById('search').value.trim().toLowerCase(), department = document.getElementById('department').value, status = document.getElementById('status').value;
    var rows = (currentTab === 'users' ? snapshot.users : snapshot.sessions).filter(u => (currentTab !== 'users' || u.online) && (!query || (u.name + ' ' + u.uid).toLowerCase().includes(query)) && (!department || department === u.department) && (currentTab === 'users' || status === '' || String(Number(u.online)) === status));
    var body = document.getElementById('rows'); body.replaceChildren();
    var reasons = { logout: 'ออกจากระบบ', connection_closed: 'ปิดแท็บ / การเชื่อมต่อหลุด', session_expired: 'เซสชันสิ้นสุด', server_restart: 'Service เริ่มใหม่', server_stop: 'Service หยุด' };
    rows.forEach(u => {
      var s = currentTab === 'users' ? u.latest : u;
      var values = [u.name + ' (' + u.uid + ')', u.department, u.online ? 'ออนไลน์' : 'ออฟไลน์', date(s.start), s.end == null ? (s.online ? 'ยังอยู่ในระบบ' : 'รอเชื่อมต่อใหม่') : date(s.end), duration(s.seconds + increment(s))];
      if (currentTab === 'users') values.push(duration(u.seconds + increment(s)), u.tabs);
      else values.push(reasons[s.reason] || (s.online ? 'กำลังเชื่อมต่อ' : 'รอเชื่อมต่อใหม่'), s.ip || '—');
      var row = document.createElement('tr');
      values.forEach((value, i) => { var td = document.createElement('td'); if (i === 2) { var badge = document.createElement('span'); badge.className = 'badge' + (u.online ? '' : ' off'); badge.textContent = value; td.appendChild(badge); } else td.textContent = value; row.appendChild(td); }); body.appendChild(row);
    });
    document.getElementById('empty').hidden = rows.length > 0;
  }
  function subscribe() {
    var start = document.getElementById('start').value, end = document.getElementById('end').value;
    var invalid = !start || !end || start > end || Date.parse(end) - Date.parse(start) > 366 * 86400000;
    document.getElementById('filter-error').textContent = invalid ? 'กรุณาเลือกวันที่เริ่มก่อนวันที่สิ้นสุด และช่วงไม่เกิน 367 วัน' : '';
    if (!invalid) window.EISPresence.subscribe({ start: start, end: end });
  }
  window.EISPresence.on(function (type, data) {
    if (type === 'snapshot') {
      snapshot = data; receivedAt = Date.now(); connected = true;
      statusNode.className = data.storageWarning ? 'notice' : 'notice connected';
      statusNode.textContent = data.storageWarning ? 'เชื่อมต่อแล้ว แต่บันทึกประวัติไม่ได้ชั่วคราว กรุณาตรวจ service log' : '● เชื่อมต่อ WebSocket แล้ว — อัปเดตอัตโนมัติ';
      document.getElementById('updated').textContent = date(data.serverTime);
      document.getElementById('range').textContent = 'ช่วงวันที่ ' + data.filters.start + ' ถึง ' + data.filters.end;
      var select = document.getElementById('department'), old = select.value;
      select.replaceChildren(new Option('ทุกหน่วยงาน', ''));
      [...new Set(data.users.map(u => u.department))].filter(Boolean).sort().forEach(d => select.add(new Option(d, d)));
      select.value = [...select.options].some(o => o.value === old) ? old : '';
      render();
    } else if (type === 'connected') { statusNode.className = 'notice connected'; statusNode.textContent = '● เชื่อมต่อ WebSocket แล้ว — อัปเดตอัตโนมัติ'; }
    else { connected = false; statusNode.className = 'notice'; statusNode.textContent = type === 'unauthorized' ? 'เซสชันสิ้นสุด กรุณาเข้าสู่ระบบใหม่' : type === 'connecting' ? 'กำลังเชื่อมต่อ WebSocket…' : 'การเชื่อมต่อขาด — ข้อมูลอาจไม่เป็นปัจจุบัน กำลังเชื่อมต่อใหม่'; render(); }
  });
  document.getElementById('filters').onsubmit = function (event) { event.preventDefault(); subscribe(); };
  ['search', 'department', 'status'].forEach(id => document.getElementById(id).addEventListener('input', render));
  document.querySelectorAll('[data-tab]').forEach(button => button.onclick = function () { currentTab = button.dataset.tab; document.getElementById('status').disabled = currentTab === 'users'; document.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('active', b === button)); render(); });
  document.getElementById('status').disabled = true;
  subscribe(); setInterval(render, 1000);
}());

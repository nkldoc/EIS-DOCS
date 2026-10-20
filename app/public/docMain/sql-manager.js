(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const config = window.SQL_MANAGER;
  const editor = window.SQL_EDITOR;
  let suggestion = null, suggestionIndex = 0;
  function hideSuggestions() {
    suggestion = null; $('field-suggestions').hidden = true;
    $('query').setAttribute('aria-expanded', 'false'); $('query').removeAttribute('aria-activedescendant');
  }
  function insertSuggestion(index) {
    if (!suggestion?.matches[index]) return;
    const field = suggestion.matches[index].name, query = $('query');
    const replacement = '[' + field.replace(/]/g, ']]') + ']';
    query.setRangeText(replacement, suggestion.start, suggestion.end, 'end');
    hideSuggestions(); invalidate(); query.focus();
  }
  function renderSuggestions() {
    const popup = $('field-suggestions'); popup.replaceChildren();
    if (!suggestion) return;
    suggestion.matches.forEach((column, index) => {
      const option = document.createElement('button'); option.type = 'button'; option.id = 'field-option-' + index;
      option.setAttribute('role', 'option'); option.setAttribute('aria-selected', index === suggestionIndex ? 'true' : 'false');
      option.className = index === suggestionIndex ? 'active' : '';
      const name = document.createElement('strong'), type = document.createElement('small'); name.textContent = column.name; type.textContent = column.type;
      option.append(name, type); option.onmousedown = event => event.preventDefault(); option.onclick = () => insertSuggestion(index); popup.append(option);
    });
    popup.hidden = false; $('query').setAttribute('aria-expanded', 'true'); $('query').setAttribute('aria-activedescendant', 'field-option-' + suggestionIndex);
    popup.children[suggestionIndex]?.scrollIntoView?.({ block: 'nearest' });
  }
  function suggest() {
    const query = $('query');
    if (query.selectionStart !== query.selectionEnd) { hideSuggestions(); return; }
    suggestion = editor.completion(query.value, query.selectionStart, columns); suggestionIndex = 0;
    if (!suggestion) { hideSuggestions(); return; } renderSuggestions();
  }
  function safetyDialog({ title, message, query, phrase = '' }) {
    hideSuggestions(); const dialog = $('sql-safety');
    $('safety-title').textContent = title; $('safety-message').textContent = message; $('safety-query').textContent = query;
    $('safety-phrase-label').hidden = !phrase; $('safety-phrase').textContent = phrase; $('safety-input').value = '';
    $('safety-accept').hidden = !phrase; $('safety-accept').disabled = true;
    return new Promise(resolve => {
      let accepted = false;
      $('safety-input').oninput = () => { $('safety-accept').disabled = $('safety-input').value.trim() !== phrase; };
      $('safety-cancel').onclick = () => dialog.close();
      $('safety-accept').onclick = () => { if (phrase && $('safety-input').value.trim() === phrase) { accepted = true; dialog.close(); } };
      dialog.onclose = () => resolve(accepted); dialog.showModal();
    });
  }
  $('connection').value = config.connection || 'local';
  let databases = [], tables = [], columns = [], preview = null, busy = false;
  let connectionFailed = false;
  let backupDatabase = null, automaticBackupName = '';
  function backupDefaults(database) {
    let folder = database.replace(/[^A-Za-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'database';
    if (/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i.test(folder)) folder = 'DB-' + folder;
    const prefix = folder.toUpperCase() === 'EIS-DOCS' ? 'EIS-DOC' : folder;
    const parts = new Intl.DateTimeFormat('en-GB', {timeZone:'Asia/Bangkok',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date());
    const value = type => parts.find(part => part.type === type).value;
    return {folder:'backup-' + folder, name:prefix + '-' + value('year') + value('month') + value('day') + '-' + value('hour') + value('minute') + value('second')};
  }
  const openDatabases = new Set();
  const quote = name => '[' + name.replace(/]/g, ']]') + ']';
  const selected = () => tables.find(t => JSON.stringify([t.schema, t.table]) === $('table').value);
  function invalidate() { preview = null; $('confirmation').hidden = true; }
  function status(message, error = false) { $('status').textContent = message; $('status').className = error ? 'error' : ''; }
  function syncContext() {
    const db = $('database').value, table = selected();
    $('restore-panel').hidden = $('connection').value !== 'local';
    if (typeof drawTabs === 'function' && $('query-tabs').children.length) drawTabs();
    $('backup-database').textContent = db || 'ยังไม่ได้เลือก';
    if (db !== backupDatabase) {
      backupDatabase = db;
      const defaults = db ? backupDefaults(db) : {folder:'',name:''};
      $('backup-folder').value = defaults.folder;
      $('backup-name').value = automaticBackupName = defaults.name;
    }
    $('query-context').textContent = db ? db + (table ? ' / ' + table.schema + '.' + table.table : '') : 'เลือกฐานข้อมูลและตาราง';
    $('column-table').textContent = table ? table.schema + '.' + table.table : '';
  }
  function renderTree() {
    const root = $('database-tree'); root.replaceChildren();
    if (connectionFailed) { const message = document.createElement('p'); message.className = 'tree-empty error'; message.textContent = 'โหลดรายชื่อไม่ได้: การเชื่อมต่อ SQL ไม่สำเร็จ กรุณาตรวจข้อความด้านบนแล้วกด ↻ เพื่อลองใหม่'; root.append(message); return; }
    const search = $('database-search').value.trim().split('.')[0].replace(/^\[|\]$/g, '').toLowerCase();
    const visible = databases.filter(db => db.name.toLowerCase().includes(search));
    const folder = document.createElement('details'); folder.open = true;
    const title = document.createElement('summary'); title.textContent = '📁 Databases (' + visible.length + ')'; folder.append(title);
    const system = document.createElement('details'); system.className = 'system-folder'; system.open = !!search || visible.some(db => Number(db.isSystem) && db.key === $('database').value);
    const systemTitle = document.createElement('summary'); systemTitle.textContent = '📁 System Databases'; system.append(systemTitle);
    if (visible.some(db => Number(db.isSystem))) folder.append(system);
    visible.forEach(db => {
      const node = document.createElement('details'); node.className = 'database-node'; node.open = openDatabases.has(db.key);
      const summary = document.createElement('summary'); summary.className = 'database-label' + (db.key === $('database').value ? ' selected' : '');
      if (db.key === $('database').value) summary.setAttribute('aria-current', 'true');
      const name = document.createElement('span'); name.textContent = '▰ ' + db.name; summary.append(name); summary.title = db.name;
      if (db.accessible === 0 || db.accessible === '0') {
        const state = document.createElement('small'); state.className = 'database-access'; state.textContent = db.state && db.state !== 'ONLINE' ? db.state : 'ไม่มีสิทธิ์เข้าถึง'; summary.append(state);
      }
      summary.onclick = event => {
        event.preventDefault(); if (busy) return;
        if (db.key === $('database').value) {
          if (openDatabases.has(db.key)) openDatabases.delete(db.key); else openDatabases.add(db.key);
          renderTree(); return;
        }
        work(async () => { $('database').value = db.key; openDatabases.add(db.key); syncContext(); await loadTables(); });
      };
      node.append(summary);
      if (db.key === $('database').value) {
        const tableFolder = document.createElement('details'); tableFolder.open = true; tableFolder.className = 'tables-folder';
        const tableTitle = document.createElement('summary'); tableTitle.textContent = '📁 Tables (' + $('table').options.length + ')'; tableFolder.append(tableTitle);
        Array.from($('table').options).forEach(option => {
          const button = document.createElement('button'); button.type = 'button'; button.className = 'table-node' + (option.value === $('table').value ? ' selected' : ''); button.textContent = '▦ ' + option.textContent; button.title = option.textContent;
          if (option.value === $('table').value) button.setAttribute('aria-current', 'true');
          button.onclick = () => work(async () => { $('table').value = option.value; await loadColumns(); });
          tableFolder.append(button);
        });
        if (!$('table').options.length) { const empty = document.createElement('p'); empty.className = 'tree-empty'; empty.textContent = 'ไม่พบตาราง'; tableFolder.append(empty); }
        node.append(tableFolder);
      } else {
        const hint = document.createElement('p'); hint.className = 'tree-empty'; hint.textContent = 'เลือกฐานข้อมูลเพื่อโหลดตาราง'; node.append(hint);
      }
      (Number(db.isSystem) ? system : folder).append(node);
    });
    root.append(folder);
    if (!visible.length) { const empty = document.createElement('p'); empty.textContent = 'ไม่พบฐานข้อมูล'; root.append(empty); }
  }
  async function request(action, extra = {}) {
    const table = selected() || {};
    const response = await fetch('sql-manager-api.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: config.csrf, case: config.case, connection: $('connection').value, database: $('database').value, ...table, action, ...extra })
    });
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('เซิร์ฟเวอร์ตอบกลับไม่สมบูรณ์ กรุณาตรวจการเชื่อมต่อและ server log'); }
    if (!response.ok || data.error) throw new Error(data.error || 'คำขอไม่สำเร็จ');
    return data;
  }
  async function work(fn) {
    if (busy) return;
    busy = true;
    document.querySelectorAll('button,select,input,textarea').forEach(el => el.disabled = true);
    try { await fn(); } catch (error) { invalidate(); status(error.message, true); }
    finally { busy = false; document.querySelectorAll('button,select,input,textarea').forEach(el => el.disabled = false); syncContext(); renderTree(); const db = databases.find(d => d.key === $('database').value); $('backup').disabled = !db || Number(db.accessible ?? 1) === 0; $('run').disabled = connectionFailed || !db; $('restore').disabled = connectionFailed || !db || $('connection').value !== 'local'; }
  }
  function renderRows(rows) {
    const result = $('result'); result.replaceChildren();
    if (!rows.length) { const empty = document.createElement('p'); empty.className = 'result-empty'; empty.textContent = 'ไม่พบข้อมูลตามเงื่อนไข'; result.append(empty); return; }
    const keys = Object.keys(rows[0]), text = value => value === null ? 'NULL' : typeof value === 'object' ? JSON.stringify(value) : String(value);
    const toolbar = document.createElement('div'); toolbar.className = 'result-toolbar';
    const count = document.createElement('strong'), search = document.createElement('input'), wrap = document.createElement('button');
    search.type = 'search'; search.placeholder = 'ค้นหาในผลลัพธ์ที่โหลดแล้ว…'; search.setAttribute('aria-label', 'ค้นหาในผลลัพธ์'); search.id = 'result-search';
    wrap.type = 'button'; wrap.textContent = 'ตัดบรรทัดข้อมูล'; wrap.setAttribute('aria-pressed', 'false'); toolbar.append(count, search, wrap);
    const scroll = document.createElement('div'); scroll.className = 'result-scroll'; scroll.tabIndex = 0; scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', 'ตารางผลลัพธ์ SQL เลื่อนซ้ายขวาเพื่อดูทุกคอลัมน์');
    const table = document.createElement('table'); table.className = 'sql-results-table';
    const head = document.createElement('thead'), header = document.createElement('tr'), number = document.createElement('th'); number.textContent = '#'; number.scope = 'col'; number.className = 'row-number'; header.append(number);
    let sortKey = null, direction = 1; const headers = [];
    keys.forEach(key => {
      const th = document.createElement('th'); th.scope = 'col'; th.setAttribute('aria-sort', 'none');
      const button = document.createElement('button'); button.type = 'button'; button.textContent = key + ' ↕'; button.title = 'เรียงตาม ' + key;
      button.onclick = () => {
        direction = sortKey === key ? -direction : 1; sortKey = key;
        headers.forEach(item => { item.th.setAttribute('aria-sort', item.key === key ? direction === 1 ? 'ascending' : 'descending' : 'none'); item.button.textContent = item.key + (item.key === key ? direction === 1 ? ' ↑' : ' ↓' : ' ↕'); });
        renderBody();
      };
      headers.push({ th, button, key }); th.append(button); header.append(th);
    });
    head.append(header); const body = document.createElement('tbody'); table.append(head, body); scroll.append(table);
    const hint = document.createElement('p'); hint.className = 'result-hint'; hint.textContent = 'เลื่อน ↔ เพื่อดูทุกคอลัมน์ · คลิกหัวคอลัมน์เพื่อเรียงข้อมูลที่โหลดแล้ว · คลิกค่าที่ยาวเพื่อดูเต็ม';
    result.append(toolbar, hint, scroll);
    function renderBody() {
      const term = search.value.trim().toLocaleLowerCase();
      let visible = rows.map((row,index) => ({ row, index })).filter(item => !term || keys.some(key => text(item.row[key]).toLocaleLowerCase().includes(term)));
      if (sortKey) visible.sort((a,b) => text(a.row[sortKey]).localeCompare(text(b.row[sortKey]), 'th', { numeric: true }) * direction);
      count.textContent = visible.length + ' / ' + rows.length + ' แถว · ' + keys.length + ' คอลัมน์'; body.replaceChildren();
      visible.forEach(({row,index}) => {
        const tr = document.createElement('tr'), ordinal = document.createElement('td'); ordinal.textContent = String(index + 1); ordinal.className = 'row-number'; tr.append(ordinal);
        keys.forEach(key => {
          const td = document.createElement('td'), value = text(row[key]), long = value.length > 80 || /[\r\n]/.test(value);
          const content = document.createElement(long ? 'button' : 'span'); content.className = 'result-value' + (row[key] === null ? ' is-null' : '') + (long ? ' inspect-value' : ''); content.textContent = value; content.title = long ? 'เปิดดูค่าเต็ม: ' + key : value;
          if (long) { content.type = 'button'; content.onclick = () => { $('cell-title').textContent = key + ' · แถว ' + (index + 1); $('cell-content').textContent = value; $('result-cell-dialog').showModal(); }; }
          td.append(content); tr.append(td);
        });
        body.append(tr);
      });
      if (!visible.length) { const tr = document.createElement('tr'), td = document.createElement('td'); td.colSpan = keys.length + 1; td.textContent = 'ไม่พบข้อมูลในผลลัพธ์ที่โหลดแล้ว'; tr.append(td); body.append(tr); }
    }
    search.oninput = renderBody;
    let wrapped = false;
    wrap.onclick = () => { wrapped = !wrapped; table.className = 'sql-results-table' + (wrapped ? ' wrap-values' : ''); wrap.setAttribute('aria-pressed', String(wrapped)); wrap.textContent = wrapped ? 'แสดงข้อมูลบรรทัดเดียว' : 'ตัดบรรทัดข้อมูล'; };
    renderBody();
  }
  function template(action) {
    hideSuggestions();
    invalidate();
    const table = selected(); if (!table) return;
    const name = quote(table.schema) + '.' + quote(table.table);
    const filter = columns[0] ? quote(columns[0].name) : '[id]';
    const editable = columns.find(c => !Number(c.is_identity) && !Number(c.is_computed) && !['timestamp','rowversion'].includes(c.type));
    $('query').value = action === 'SELECT' ? 'SELECT TOP 200 * FROM ' + name :
      action === 'UPDATE' ? 'UPDATE ' + name + '\nSET ' + quote(editable?.name || columns[0]?.name || 'column') + " = 'ค่าใหม่'\nWHERE " + filter + ' = 0' :
      'DELETE FROM ' + name + '\nWHERE ' + filter + ' = 0';
    status('');
  }
  function fillTables() {
    const search = $('table-search').value.toLowerCase();
    $('table').replaceChildren();
    tables.filter(t => (t.schema + '.' + t.table).toLowerCase().includes(search)).forEach(t => {
      const option = document.createElement('option'); option.value = JSON.stringify([t.schema, t.table]); option.textContent = t.schema + '.' + t.table; $('table').append(option);
    });
    $('table').selectedIndex = $('table').options.length ? 0 : -1;
  }
  async function loadColumns() {
    hideSuggestions();
    invalidate(); $('columns').replaceChildren(); $('result').replaceChildren(); $('query').value = ''; columns = [];
    if (!selected()) { status('ไม่พบตาราง'); return; }
    columns = (await request('columns')).columns;
    columns.forEach(c => { const div = document.createElement('div'); div.textContent = c.name + ' · ' + c.type + (Number(c.is_identity) ? ' · identity' : '') + (Number(c.is_computed) ? ' · computed' : ''); $('columns').append(div); });
    template('SELECT');
  }
  function chooseTable(target) {
    const table = tables.find(t => t.schema.toLowerCase() === target.schema.toLowerCase() && t.table.toLowerCase() === target.table.toLowerCase());
    if (!table) throw new Error('ไม่พบตาราง ' + target.database + '.' + target.schema + '.' + target.table + ' หรือบัญชี SQL ไม่มีสิทธิ์เห็นตาราง');
    $('table-search').value = ''; fillTables(); $('table').value = JSON.stringify([table.schema, table.table]);
  }
  async function loadTables(target = null) {
    hideSuggestions();
    invalidate(); $('table').replaceChildren(); $('columns').replaceChildren(); $('query').value = ''; $('result').replaceChildren(); tables = []; columns = [];
    const db = databases.find(d => d.key === $('database').value);
    if (db && Number(db.accessible ?? 1) === 0) { status('ฐานข้อมูล ' + db.name + ' · ' + db.state + ' · บัญชีปัจจุบันไม่มีสิทธิ์ใช้งาน กรุณาเลือกการเชื่อมต่อที่มีสิทธิ์', true); return; }
    tables = (await request('tables')).tables; $('table-search').value = ''; fillTables();
    if (target) chooseTable(target);
    await loadColumns();
  }
  function buildFromSearch(action) {
    return work(async () => {
      invalidate(); hideSuggestions();
      const value = $('database-search').value.trim();
      if (value.includes('.')) { $('query').value = ''; $('result').replaceChildren(); }
      const target = editor.target(value);
      if (target) {
        $('query').value = ''; $('result').replaceChildren();
        const db = databases.find(d => d.name.toLowerCase() === target.database.toLowerCase());
        if (!db) throw new Error('ไม่พบฐานข้อมูล ' + target.database + ' ใน SQL Connection ที่เลือก');
        if (Number(db.accessible ?? 1) === 0) throw new Error('บัญชีปัจจุบันไม่มีสิทธิ์เข้าถึง ' + db.name);
        openDatabases.add(db.key);
        if ($('database').value !== db.key) { $('database').value = db.key; await loadTables(target); }
        else { chooseTable(target); await loadColumns(); }
      }
      if (!selected()) throw new Error('กรุณาเลือกตาราง หรือพิมพ์ Database..Table ก่อน');
      template(action); $('query').focus();
      status('สร้างคำสั่ง ' + action + ' สำหรับ ' + $('database').value + '.' + selected().schema + '.' + selected().table + ' แล้ว กรุณาแก้ค่าและ WHERE ก่อนตรวจสอบ · ยังไม่ได้รัน SQL');
    });
  }
  async function loadDatabases() {
    invalidate(); status('กำลังโหลดฐานข้อมูล…');
    const previous = $('database').value;
    $('backup-location').textContent = '';
    connectionFailed = false; $('connection-error').hidden = true; $('connection-dot').className = 'connection-dot pending';
    $('connected-server').textContent = $('connection').selectedOptions?.[0]?.textContent || $('connection').value;
    $('connected-login').textContent = 'กำลังเชื่อมต่อ SQL…';
    let data;
    try { data = await request('databases'); }
    catch (error) {
      connectionFailed = true; databases = []; tables = []; columns = []; openDatabases.clear();
      $('database').replaceChildren(); $('table').replaceChildren(); $('columns').replaceChildren(); $('query').value = ''; $('result').replaceChildren();
      $('connected-login').textContent = 'เชื่อมต่อไม่สำเร็จ'; $('connection-dot').className = 'connection-dot failed';
      $('connection-error').textContent = error.message; $('connection-error').hidden = false; throw error;
    }
    databases = data.databases; $('connection-dot').className = 'connection-dot';
    $('backup-location').textContent = data.backup?.configured ? 'ปลายทาง: ' + data.backup.web_directory + ' · เว้นชื่อว่างเพื่อใช้ชื่อฐานข้อมูลและวันที่อัตโนมัติ' : (data.backup?.error || 'ยังไม่ได้ตั้งปลายทาง Backup กรุณารัน Setup-SqlBackup.ps1');
    $('connected-server').textContent = data.server?.name || $('connection').value;
    $('connected-login').textContent = 'SQL login: ' + (data.server?.login || '—') + ' · ' + $('connection').value;
    $('database').replaceChildren();
    databases.forEach(db => { const option = document.createElement('option'); option.value = db.key; option.textContent = db.name; $('database').append(option); });
    const initial = databases.find(db => db.key === previous) || databases.find(db => !Number(db.isSystem) && Number(db.accessible ?? 1)) || databases[0];
    if (!initial) {
      tables = []; columns = []; $('table').replaceChildren(); $('columns').replaceChildren(); $('query').value = ''; $('result').replaceChildren();
      status('ไม่พบฐานข้อมูลที่มีสิทธิ์เข้าถึง'); return;
    }
    $('database').value = initial.key; openDatabases.add(initial.key); await loadTables();
  }
  $('database-search').oninput = renderTree;
  $('database-search').onkeydown = event => { if (event.key === 'Enter') { event.preventDefault(); buildFromSearch('SELECT'); } };
  $('connection').onchange = () => work(async () => {
    invalidate(); databases = []; tables = []; columns = []; openDatabases.clear();
    $('database').replaceChildren(); $('table').replaceChildren(); $('database-tree').replaceChildren(); $('columns').replaceChildren(); $('query').value = ''; $('result').replaceChildren(); $('database-search').value = '';
    $('connected-server').textContent = $('connection').value; $('connected-login').textContent = 'กำลังเชื่อมต่อ…';
    try { await loadDatabases(); } catch (error) { $('connected-login').textContent = 'เชื่อมต่อไม่สำเร็จ'; throw error; }
  });
  $('refresh-databases').onclick = () => work(loadDatabases);
  $('database').onchange = () => work(loadTables);
  $('table').onchange = () => work(loadColumns);
  $('table-search').oninput = () => work(async () => { fillTables(); await loadColumns(); });
  $('query').oninput = () => { invalidate(); suggest(); };
  $('query').onclick = suggest;
  $('query').onblur = event => { if (!$('field-suggestions').contains(event.relatedTarget)) hideSuggestions(); };
  $('query').onkeydown = event => {
    if (event.ctrlKey && event.code === 'Space') { event.preventDefault(); suggest(); return; }
    if (!suggestion) return;
    if (['ArrowDown','ArrowUp'].includes(event.key)) {
      event.preventDefault(); suggestionIndex = (suggestionIndex + (event.key === 'ArrowDown' ? 1 : -1) + suggestion.matches.length) % suggestion.matches.length; renderSuggestions();
    } else if (['Enter','Tab'].includes(event.key)) { event.preventDefault(); insertSuggestion(suggestionIndex); }
    else if (event.key === 'Escape') { event.preventDefault(); hideSuggestions(); }
    else if (['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) hideSuggestions();
  };
  document.querySelectorAll('[data-template]').forEach(button => button.onclick = () => buildFromSearch(button.dataset.template));
  $('cancel').onclick = () => { invalidate(); status('ยกเลิกการยืนยันแล้ว'); };
  $('run').onclick = async () => {
    if (busy) return;
    if ($('query-mode').value === 'script') {
      const query = $('query').value, connection = $('connection').value, database = $('database').value;
      if (!query.trim()) return;
      if (!await safetyDialog({title:'Run T-SQL Script',message:connection + ' / ' + database + ' / Script can change multiple tables or databases via USE. No preview.',query,phrase:'RUN ' + database})) return;
      return work(async () => { invalidate(); const data = await request('script', {query}); renderRows(data.rows); status(data.message); });
    }
    const risk = editor.risk($('query').value);
    if (risk.missingWhere) {
      invalidate(); status('บล็อก ' + risk.action + ': ไม่พบ WHERE เพื่อป้องกันการเปลี่ยนข้อมูลทั้งตาราง', true);
      await safetyDialog({ title: 'หยุดคำสั่ง: ไม่มี WHERE', message: 'คำสั่ง ' + risk.action + ' นี้อาจกระทบข้อมูลทั้งตาราง ระบบไม่อนุญาตให้ดำเนินการ กรุณาระบุ WHERE ให้ชัดเจนแล้วตรวจสอบใหม่', query: $('query').value }); return;
    }
    return work(async () => {
    invalidate(); status('กำลังตรวจสอบ SQL…');
    if (!selected()) throw new Error('กรุณาเลือกตาราง');
    const data = await request('preview', { query: $('query').value }); renderRows(data.rows);
    if (data.token) {
      preview = { token: data.token, query: $('query').value, count: data.count };
      $('confirm-text').textContent = 'คำสั่งนี้จะเปลี่ยนข้อมูล ' + data.count + ' แถว ใน ' + $('database').selectedOptions[0].textContent + '.' + selected().schema + '.' + selected().table + ' · แสดงตัวอย่างก่อนแก้ไขสูงสุด 50 แถว · การยืนยันมีอายุ 5 นาที';
      $('confirmation').hidden = false; status('ตรวจสอบข้อมูลและ SQL ก่อนยืนยันบันทึก');
    } else status('แสดง ' + data.rows.length + ' แถว (ตาม TOP ที่ระบุ)');
    });
  };
  $('commit').onclick = async () => {
    if (busy) return;
    if (!preview || preview.query !== $('query').value) { status('กรุณาตรวจสอบ SQL ใหม่', true); return; }
    const pending = preview, action = editor.risk(pending.query).action;
    const target = $('connection').value + ' / ' + $('database').value + ' / ' + selected().schema + '.' + selected().table;
    const accepted = await safetyDialog({ title: action === 'DELETE' ? 'ยืนยันลบข้อมูลถาวร' : 'ยืนยันแก้ไขข้อมูล', message: target + '\nกระทบ ' + pending.count + ' แถว · ตรวจ WHERE และข้อมูลตัวอย่างให้แน่ใจ ก่อนยืนยันจะยังไม่มีการเปลี่ยนข้อมูล', query: pending.query, phrase: action + ' ' + pending.count });
    if (!accepted) { status('ยกเลิกแล้ว ยังไม่มีการเปลี่ยนข้อมูล'); return; }
    if (preview !== pending || pending.query !== $('query').value) { invalidate(); status('SQL เปลี่ยนไป กรุณาตรวจสอบใหม่', true); return; }
    return work(async () => {
      invalidate(); status('กำลังบันทึก… หากการเชื่อมต่อขาดให้ตรวจข้อมูลก่อนส่งซ้ำ');
      const data = await request('commit', { token: pending.token, query: pending.query }); $('result').replaceChildren(); status(data.message);
    });
  };
  $('backup').onclick = () => work(async () => {
    if (!$('database').value) throw new Error('กรุณาเลือกฐานข้อมูล');
    if ($('backup-name').value === automaticBackupName) $('backup-name').value = automaticBackupName = backupDefaults($('database').value).name;
    const backupFolder = $('backup-folder').value.trim(), backupName = $('backup-name').value.trim();
    for (const value of [backupFolder, backupName]) {
      if (value && (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/.test(value) || /^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i.test(value))) throw new Error('ชื่อโฟลเดอร์และ Backup ใช้ A-Z, a-z, 0-9, _ หรือ - สูงสุด 80 ตัวอักษร');
    }
    invalidate(); status('กำลังสร้างโฟลเดอร์ สำรองฐานข้อมูล และบีบอัด ZIP อาจใช้เวลาหลายนาที…');
    const list = $('backup-downloads'); list.querySelector('.backup-empty')?.remove();
    const card = document.createElement('article'); card.className = 'backup-file pending';
    const heading = document.createElement('strong'); heading.textContent = $('database').value;
    const detail = document.createElement('p'); detail.textContent = 'กำลังสร้าง .bak และ ZIP…'; card.append(heading, detail); list.prepend(card);
    try {
      const data = await request('backup', { backupFolder, backupName }); card.className = 'backup-file ready';
      detail.textContent = (data.filename || '.zip') + ' · ' + (Number(data.bytes) / 1024 / 1024).toLocaleString('th-TH', { maximumFractionDigits: 2 }) + ' MB' + (data.folder ? ' · โฟลเดอร์ ' + data.folder : '');
      const expiry = document.createElement('small'); expiry.textContent = 'ลิงก์หมดอายุ ' + new Date(data.expiresAt).toLocaleString('th-TH');
      const link = document.createElement('a'); link.className = 'download-link'; link.href = data.download; link.textContent = '↓ ดาวน์โหลด ZIP (Stream)'; link.download = data.filename || ''; card.append(expiry, link);
      link.click();
      status('สำรองและบีบอัด ZIP เรียบร้อยแล้ว เริ่มดาวน์โหลดอัตโนมัติ หากเบราว์เซอร์ไม่เริ่มให้กดลิงก์ดาวน์โหลด');
    } catch (error) { card.className = 'backup-file failed'; detail.textContent = error.message; throw error; }
  });
  const tabs = []; let activeTab = null;
  function saveTab() {
    if (activeTab) Object.assign(activeTab, {connection:$('connection').value, database:$('database').value, table:$('table').value, query:$('query').value, mode:$('query-mode').value});
  }
  function drawTabs() {
    $('query-tabs').replaceChildren();
    tabs.forEach((tab, index) => {
      const button = document.createElement('button'); button.type = 'button'; button.role = 'tab';
      button.textContent = (index + 1) + ' / ' + (tab === activeTab ? $('connection').value : tab.connection) + ' / ' + ((tab === activeTab ? $('database').value : tab.database) || 'SQL');
      button.setAttribute('aria-selected', String(tab === activeTab));
      button.onclick = () => { if (busy || tab === activeTab) return; saveTab(); work(async () => {
        activeTab = tab; $('connection').value = tab.connection; await loadDatabases();
        if (databases.some(db => db.key === tab.database)) { $('database').value = tab.database; await loadTables(); }
        if (Array.from($('table').options).some(o => o.value === tab.table)) { $('table').value = tab.table; await loadColumns(); }
        $('query').value = tab.query; $('query-mode').value = tab.mode; invalidate(); drawTabs();
      }); }; $('query-tabs').append(button);
      const close = document.createElement('button'); close.type = 'button'; close.textContent = 'x'; close.title = 'Close tab';
      close.onclick = () => { if (busy || tabs.length === 1) return; if (tab.query && !confirm('Close this SQL tab?')) return; if (tab === activeTab) { const next = tabs.find(t => t !== tab); $('query-tabs').querySelectorAll('[role=tab]')[tabs.indexOf(next)].click(); } tabs.splice(tabs.indexOf(tab), 1); drawTabs(); };
      $('query-tabs').append(close);
    });
  }
  $('new-query-tab').onclick = () => { if (busy) return; saveTab(); activeTab = {connection:$('connection').value,database:$('database').value,table:$('table').value,query:'',mode:'safe'}; tabs.push(activeTab); $('query').value = ''; $('result').replaceChildren(); invalidate(); drawTabs(); };
  $('query-mode').onchange = invalidate;
  $('restore').onclick = async () => {
    if (busy || $('connection').value !== 'local') return;
    const file = $('restore-file').files[0], database = $('database').value;
    if (!file || !database) { status('Select a Local database and ZIP file first', true); return; }
    const replace = $('restore-replace').checked, closeConnections = $('restore-close').checked;
    if (!await safetyDialog({title:'Confirm Restore Local',message:'File ' + file.name + ' to Local (.) / ' + database + '\nOverwrite: ' + replace + ' / Close connections: ' + closeConnections,query:'RESTORE WITH RECOVERY',phrase:'RESTORE ' + database})) return;
    work(async () => {
      const form = new FormData(); Object.entries({csrf:config.csrf,action:'restore',connection:'local',database,replace:replace?'1':'0',closeConnections:closeConnections?'1':'0'}).forEach(([k,v]) => form.append(k,v)); form.append('backup',file);
      status('Uploading and restoring...'); const response = await fetch('sql-manager-api.php',{method:'POST',credentials:'same-origin',body:form}); const data = await response.json(); if (!response.ok || data.error) throw new Error(data.error || 'Restore failed'); await loadDatabases(); status(data.message);
    });
  };
  work(async () => { await loadDatabases(); activeTab = {connection:$('connection').value,database:$('database').value,table:$('table').value,query:$('query').value,mode:'safe'}; tabs.push(activeTab); drawTabs(); });
}());

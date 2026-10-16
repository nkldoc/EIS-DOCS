const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor(tag = 'div') { this.tag = tag; this.children = []; this._value = ''; this.className = ''; this.textContent = ''; this.dataset = {}; }
  get options() { return this.children; }
  get value() { return this._value || (this.tag === 'select' ? this.children[0]?.value || '' : ''); }
  set value(v) { this._value = v; }
  set selectedIndex(index) { this._value = this.children[index]?.value || ''; }
  get selectedOptions() { return this.children.filter(c => c.value === this.value); }
  append(...nodes) { nodes.forEach(node => { node.parent = this; this.children.push(node); }); }
  prepend(node) { node.parent = this; this.children.unshift(node); }
  replaceChildren(...nodes) { this.children = []; this._value = ''; this.append(...nodes); }
  setAttribute(key, value) { this[key] = value; }
  removeAttribute(key) { delete this[key]; }
  contains(node) { return this === node || walk(this).includes(node); }
  focus() {}
  click() { this.clicked = true; }
  setRangeText(text,start,end) { this.value = this.value.slice(0,start)+text+this.value.slice(end); this.selectionStart = this.selectionEnd = start+text.length; }
  showModal() { this.open = true; }
  close() { this.open = false; this.onclose?.(); }
  remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
  querySelector(selector) { return walk(this).find(el => el.className.split(' ').includes(selector.slice(1))); }
}
const elements = new Map();
function el(id) { if (!elements.has(id)) elements.set(id, new Element(['database','table'].includes(id) ? 'select' : 'div')); return elements.get(id); }
function walk(root) { return root.children.flatMap(child => [child, ...walk(child)]); }
const templates = ['SELECT','UPDATE','DELETE'].map(action => { const b = new Element('button'); b.dataset.template = action; return b; });
const calls = [];
let failBackup = false;
let failDatabases = false;
const context = vm.createContext({
  document: {
    getElementById: el, createElement: tag => new Element(tag),
    querySelectorAll: selector => selector === '[data-template]' ? templates : [...elements.values(), ...templates].flatMap(e => [e, ...walk(e)])
  },
  window: { SQL_MANAGER: { csrf: 'test', case: 'case-1' }, SQL_EDITOR: require('../app/public/docMain/sql-editor.js') },
  fetch: async (_, options) => {
    const input = JSON.parse(options.body); calls.push(input);
    if(input.action === 'databases' && failDatabases) return {ok:false,json:async()=>({error:'SQL network timeout'})};
    const responses = {
      databases: { databases: [{key:'master',name:'master',isSystem:1},{key:'NMU_ERP',name:'NMU_ERP',isSystem:0},{key:'chatdb',name:'chatdb',isSystem:0},{key:'EIS_PROCURE',name:'EIS_PROCURE',isSystem:0,accessible:0,state:'ONLINE'}], server:{name:'test-server',login:'test-login'} },
      tables: { tables: input.database === 'chatdb' ? [] : [{schema:'dbo',table:'items'},{schema:'dbo',table:'sp_tor'}] },
      columns: { columns: [{name:'id',type:'int',is_identity:1},{name:'name',type:'nvarchar'},{name:'i_enable',type:'int'}] },
      preview: /^(UPDATE|DELETE)/i.test(input.query || '') ? {rows:[{id:1}],token:'preview-test',count:1} : { rows: [{id:2,name:'sample z',dc_sub_cost_name_long:'x'.repeat(150)},{id:1,name:'sample a',dc_sub_cost_name_long:null}] },
      commit: {message:'saved'},
      backup: failBackup ? {error:'backup unavailable'} : {download:'sql-manager-download.php?token=fixture',filename:'chatdb.zip',folder:'daily/production-fixture',bytes:1048576,expiresAt:'2030-01-01T00:00:00+07:00'}
    };
    return {ok: !(input.action === 'backup' && failBackup), json: async () => responses[input.action]};
  }
});
vm.runInContext(fs.readFileSync(__dirname+'/../app/public/docMain/sql-manager.js','utf8'), context);
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  await flush();
  assert.equal(el('database').value, 'NMU_ERP', 'Prefer user database over system database');
  assert.match(el('query').value, /SELECT TOP 200/);
  await el('run').onclick();
  const grid=walk(el('result')).find(e=>e.tag==='table');
  const tbody=walk(grid).find(e=>e.tag==='tbody');
  assert.equal(tbody.children.length,2);
  const sort=walk(grid).find(e=>e.tag==='button' && e.title==='เรียงตาม id'); sort.onclick();
  assert.equal(tbody.children[0].children[1].children[0].textContent,'1');
  const long=walk(grid).find(e=>e.className.includes('inspect-value')); long.onclick();
  assert.equal(el('result-cell-dialog').open,true);assert.equal(el('cell-content').textContent,'x'.repeat(150));
  el('result-cell-dialog').close();
  const filter=walk(el('result')).find(e=>e.id==='result-search');filter.value='sample z';filter.oninput();
  assert.equal(tbody.children.length,1);
  const wrap=walk(el('result')).find(e=>e.tag==='button' && e.textContent==='ตัดบรรทัดข้อมูล');wrap.onclick();
  assert.equal(wrap['aria-pressed'],'true');
  el('query').value = 'SELECT i_ FROM [dbo].[items]'; el('query').selectionStart = el('query').selectionEnd = 9;
  el('query').oninput(); assert.equal(el('field-suggestions').hidden, false);
  el('query').onkeydown({key:'Tab',preventDefault(){}});
  assert.equal(el('query').value, 'SELECT [i_enable] FROM [dbo].[items]');
  for (const sql of ["UPDATE dbo.items SET name='WHERE'",'DELETE FROM dbo.items']) {
    el('query').value=sql; const before=calls.length;
    const pending=el('run').onclick(); assert.equal(el('sql-safety').open,true);
    assert.equal(calls.length,before,'Missing WHERE never calls API');
    el('safety-cancel').onclick(); await pending;
  }
  el('query').value='DELETE FROM dbo.items WHERE id=1'; await el('run').onclick();
  assert.equal(el('confirmation').hidden,false);
  let confirmation=el('commit').onclick();
  assert.equal(el('safety-phrase').textContent,'DELETE 1');
  assert.equal(el('safety-accept').disabled,true);
  el('safety-cancel').onclick(); await confirmation;
  assert.notEqual(calls.at(-1).action,'commit','Cancel never commits');
  confirmation=el('commit').onclick();
  el('safety-input').value='DELETE 1'; el('safety-input').oninput();
  assert.equal(el('safety-accept').disabled,false);
  el('safety-accept').onclick(); await confirmation;
  assert.equal(calls.at(-1).action,'commit');
  assert(walk(el('database-tree')).some(e => e.textContent === '📁 System Databases'));
  const summary = walk(el('database-tree')).find(e => e.tag === 'summary' && e.title === 'chatdb');
  summary.onclick({preventDefault(){}}); await flush();
  assert.equal(el('database').value, 'chatdb');
  assert.equal(el('backup-database').textContent, 'chatdb');
  assert.equal(el('backup-folder').value,'backup-chatdb');assert.match(el('backup-name').value,/^chatdb-\d{8}-\d{6}$/);
  assert.equal(el('query').value, '', 'Empty database clears previous query');
  el('backup-folder').value='daily';el('backup-name').value='chat-snapshot';
  await el('backup').onclick();
  assert.equal(calls.at(-1).action, 'backup'); assert.equal(calls.at(-1).database, 'chatdb');
  assert.equal(calls.at(-1).backupFolder,'daily');assert.equal(calls.at(-1).backupName,'chat-snapshot');
  const link = walk(el('backup-downloads')).find(e => e.tag === 'a');
  assert.equal(link.href, 'sql-manager-download.php?token=fixture');
  assert.match(link.textContent, /Stream/);
  assert.match(link.textContent,/ZIP/);assert.equal(link.clicked,true,'Download starts automatically');assert.equal(link.download,'chatdb.zip');
  const beforeInvalid=calls.length;el('backup-folder').value='../escape';await el('backup').onclick();assert.equal(calls.length,beforeInvalid,'Invalid folder is not sent');assert.match(el('status').textContent,/80/);el('backup-folder').value='daily';
  el('database').value = 'NMU_ERP'; await el('database').onchange();
  assert(walk(el('backup-downloads')).includes(link), 'Download survives changing database');
  assert.equal(el('backup-folder').value,'backup-NMU-ERP');assert.match(el('backup-name').value,/^NMU-ERP-\d{8}-\d{6}$/);
  failBackup = true; await el('backup').onclick();
  assert(walk(el('backup-downloads')).some(e => e.className === 'backup-file failed'));
  assert(walk(el('backup-downloads')).includes(link), 'Failed backup preserves completed links');
  const procure = walk(el('database-tree')).find(e => e.tag === 'summary' && e.title === 'EIS_PROCURE');
  assert(procure, 'Database without access remains visible');
  const before = calls.length; procure.onclick({preventDefault(){}}); await flush();
  assert.equal(calls.length, before, 'Do not query tables for inaccessible database');
  assert.equal(el('backup').disabled, true);
  assert.match(el('status').textContent, /ไม่มีสิทธิ์/);
  assert.equal(el('connected-login').textContent, 'SQL login: test-login · local');
  failDatabases=true; await el('refresh-databases').onclick();
  assert.equal(el('database').options.length,0,'Failed connection clears stale databases');
  assert.equal(el('connected-login').textContent,'เชื่อมต่อไม่สำเร็จ');
  assert.equal(el('connection-error').hidden,false);
  assert.equal(el('connection-error').textContent,'SQL network timeout');
  assert.equal(el('backup').disabled,true);
  failDatabases=false; await el('refresh-databases').onclick();
  assert.equal(el('connection-error').hidden,true);
  assert.equal(el('database').options.length,4);
  el('database-search').value='NMU_ERP..sp_tor';
  const beforeBuild=calls.length;
  await templates[1].onclick();
  assert.equal(el('database').value,'NMU_ERP');
  assert.match(el('query').value,/^UPDATE \[dbo\]\.\[sp_tor\]/);
  assert(calls.slice(beforeBuild).every(c=>['tables','columns'].includes(c.action)),'Search builds SQL without executing it');
  await templates[2].onclick();assert.match(el('query').value,/^DELETE FROM \[dbo\]\.\[sp_tor\]/);
  el('database-search').value='NMU_ERP..missing';await templates[1].onclick();
  assert.equal(el('query').value,'','Missing table must not generate SQL for previous table');
  assert.match(el('status').textContent,/ไม่พบตาราง/);
  el('database').value='EIS_DOCS';await el('database').onchange();
  assert.equal(el('backup-folder').value,'backup-EIS-DOCS');assert.match(el('backup-name').value,/^EIS-DOC-\d{8}-\d{6}$/);
  console.log('PASS Object Explorer grouping/selection, database folder/file naming, backup target, automatic stream download, persistent links and failure state');
})().catch(error => { console.error(error); process.exitCode = 1; });

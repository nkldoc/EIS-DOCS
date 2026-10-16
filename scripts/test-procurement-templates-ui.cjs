const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor(selector) {
    this.selector = selector;
    this.options = [];
    this.listeners = {};
    this.value = '';
    this.classList = {toggle() {}, add() {}, remove() {}};
  }
  addEventListener(event, callback) { (this.listeners[event] ||= []).push(callback); }
  async emit(event) { await Promise.all((this.listeners[event] || []).map(callback => callback({target: this}))); }
  dispatchEvent(event) { return this.emit(event.type); }
  add(option) { this.options.push(option); }
  replaceChildren(...options) { this.options = options; }
  setAttribute() {}
  querySelectorAll() { return []; }
  focus() {}
}
const elements = new Map();
const element = selector => {
  if (!elements.has(selector)) elements.set(selector, new Element(selector));
  return elements.get(selector);
};
const document = {
  querySelector: element, querySelectorAll: () => [], addEventListener() {}
};
let library = {types: [], templates: []};
let counter = 0;
const context = vm.createContext({
  document, window: {addEventListener() {}}, console,
  Option: class { constructor(text, value) { this.text = text; this.value = value; } },
  Event: class { constructor(type) { this.type = type; } },
  fetch: async (url, options) => {
    if (url !== 'procurement-templates.php') return {ok: true, json: async () => ({success: true, documents: []})};
    let savedId;
    if (options.method === 'POST') {
      const data = JSON.parse(options.body);
      const template = {id: data.mode === 'update' ? data.id : `set-${++counter}`, name: data.name, docType: data.docType,
        catalog: data.catalog, documents: data.catalog.flatMap(group => [group, ...(group.children || [])]).filter(item => data.selectedIds.includes(String(item.id)))};
      if (data.mode === 'update') library.templates = library.templates.map(item => item.id === data.id ? template : item);
      else library.templates.push(template);
      savedId = template.id;
    }
    return {ok: true, json: async () => JSON.parse(JSON.stringify({...library, success: true, savedId}))};
  }
});
const source = fs.readFileSync(path.join(__dirname, '../app/public/docMain/app.js'), 'utf8');
vm.runInContext(source.replace(/\}\)\(\);\s*$/, 'globalThis.testApp = {state, initStep0, renderProcurementDocuments, buildExportData};})();'), context);

(async () => {
  const app = context.testApp;
  app.initStep0();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(app.state.privateProcurementLibraryReady, true);
  assert.ok(app.state.procurementCatalog.length > 0);
  assert.equal(element('#procurementAddTopic').disabled, false, 'Template editing does not require a PR instance');
  app.state.procurementDocuments = app.state.procurementCatalog.slice(0, 2).map(item => ({...item}));
  element('#procurementTemplateName').value = 'under 500k';
  await element('#procurementTemplateName').emit('input');
  await element('#procurementTemplateSave').emit('click');
  const firstId = app.state.procurementTemplateId;
  assert.equal(library.templates.length, 1);
  assert.equal(element('#procurementTemplateUpdate').disabled, false);
  element('#procurementTemplateName').value = 'E-bidding';
  await element('#procurementTemplateName').emit('input');
  await element('#procurementTemplateSave').emit('click');
  assert.equal(library.templates.length, 2);
  element('#procurementTemplateSelect').value = firstId;
  await element('#procurementTemplateSelect').emit('change');
  assert.equal(element('#procurementTemplateName').value, 'under 500k');
  app.state.procurementCatalog[0].title = 'updated title';
  assert.notEqual(app.state.privateProcurementLibrary.templates[0].catalog[0].title, 'updated title', 'Loading clones the library snapshot');
  await element('#procurementTemplateUpdate').emit('click');
  assert.equal(library.templates.length, 2);
  assert.equal(library.templates[0].catalog[0].title, 'updated title');
  element('#docType').value = 'GR - goods';
  await element('#docType').emit('change');
  assert.equal(app.state.procurementCatalog.length, 0);
  assert.equal(element('#procurementTemplateName').value, '');
  assert.equal(element('#procurementTemplateUpdate').disabled, true);
  element('#docType').value = 'PR - ใบขอซื้อ/ขอจ้าง';
  await element('#docType').emit('change');
  assert.equal(app.state.procurementTemplateId, firstId);
  assert.equal(element('#procurementTemplateName').value, 'under 500k');
  assert.equal(app.state.procurementCatalog[0].title, 'updated title');
  const exported = JSON.parse(JSON.stringify(app.buildExportData()));
  assert.equal(exported.procurementByType[app.state.config.docType].templateId, firstId);
  console.log('PASS: initialization, new named sets, update, snapshot isolation, type switching and export');
})().catch(error => { console.error(error); process.exitCode = 1; });

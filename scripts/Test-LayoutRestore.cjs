const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync(`${__dirname}/../app/public/docMain/layout.html`, 'utf8');
const start = html.indexOf('                let restoringDocument = false;');
const end = html.indexOf("                defaultAssigneeSelect.addEventListener", start);
async function run({original = true, saved = true, fail = false} = {}) {
  let handler;
  const messages = [], requests = [];
  const payload = {signatureFields:[{page:2, xRatio:0.2, fontSize:18}], bookmarks:[{name:'Test',page:2}]};
  const parent = {postMessage:message => messages.push(message)};
  const context = {
    window:{parent,location:{origin:'http://localhost'},addEventListener:(_, fn) => handler = fn},
    location:{origin:'http://localhost'}, parentDocsId:null, docIdInput:{}, coords:{},
    selectedPdfFile:null, documentPdf:null, pageSizes:{}, pdfFileName:null, totalPages:0, fields:[],
    File:class {constructor(_, name) {this.name = name;}},
    fetch:async url => {
      requests.push(url);
      if (url.startsWith('../pdf/fields')) return {ok:true,json:async()=>payload};
      const status = fail ? 500 : url.startsWith('document-source') && !original ? 404 : 200;
      return {ok:status === 200,status,blob:async()=>({arrayBuffer:async()=>new ArrayBuffer(2)})};
    },
    pdfjsLib:{getDocument:()=>({promise:Promise.resolve({numPages:2,getPage:async()=>({getViewport:()=>({width:595,height:842})})})})},
    showPage:async()=>{},
    restoreFromPayload:async data => {context.restored = data; context.fields = data.signatureFields;},
    buildPayload:()=>context.restored
  };
  vm.createContext(context); vm.runInContext(html.slice(start,end),context);
  await handler({origin:'http://localhost',source:parent,data:{type:'EIS_DOCS_RESTORE_DOCUMENT',docsId:3,docId:'PR/test',payload:saved ? payload : null}});
  if (fail) {
    assert.equal(messages.length,0);
    assert.match(context.coords.textContent,/HTTP 500/);
  } else {
    assert.equal(context.restored.signatureFields[0].fontSize,18);
    assert.equal(context.pageSizes[2].displayWidth,595);
    assert.equal(context.totalPages,2);
    assert.equal(Boolean(context.selectedPdfFile),original);
    assert.equal(messages.at(-1).type,'EIS_DOCS_DOCUMENT_RESTORED');
    assert.equal(requests.some(url=>url.startsWith('../pdf/fields')),!saved);
    if (!original) assert.match(context.coords.textContent,/ต้นฉบับ/);
  }
}
(async()=>{await run();await run({original:false,saved:false});await run({fail:true});console.log('PASS: original PDF restore, legacy fallback, page sizes, saved settings, and failed restore');})().catch(error=>{console.error(error);process.exitCode=1;});

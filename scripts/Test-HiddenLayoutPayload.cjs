const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync(`${__dirname}/../app/public/docMain/layout.html`, 'utf8');
const start = html.indexOf('                function buildPayload()');
const end = html.indexOf('                function decodeHeader(', start);
const context = {
  pdfFileName:'replacement.pdf', totalPages:2, pageWidth:600, pageHeight:800,
  signatureSourceMode:'group',
  page:{clientWidth:0,clientHeight:0},
  pageSizes:{1:{displayWidth:600,displayHeight:800},2:{displayWidth:800,displayHeight:600}},
  workflows:[],roles:[],activeRoles:()=>[],loadedWfGroupName:'original',participants:[],bookmarks:[],
  isReviewOnly:()=>false, actionEnabled:value=>Boolean(value),
  fields:[1,2].map(page=>({id:`f${page}`,page,x:120,y:160,w:240,h:80,hiddenRows:[],signAction:true,approveAction:false,reviewAction:true}))
};
vm.createContext(context);
vm.runInContext(html.slice(start,end),context);
const saved = JSON.parse(JSON.stringify(context.buildPayload()));
assert.equal(saved.samplePdf.fileName,'replacement.pdf');
assert.equal(saved.signatureFields[0].xRatio,0.2);
assert.equal(saved.signatureFields[1].xRatio,0.15);
assert.equal(saved.signatureFields[1].yRatio,0.26667);
for(const field of saved.signatureFields) for(const key of ['xRatio','yRatio','widthRatio','heightRatio']) assert.equal(Number.isFinite(field[key]),true);
assert.deepEqual(JSON.parse(JSON.stringify(context.payload())).signatureFields,saved.signatureFields);
console.log('PASS: hidden layout preserves finite coordinates on differently sized PDF pages in saved and generated payloads');

(() => {
  const panel = document.createElement('section');
  panel.id = 'signaturePreviewPanel';
  const style = document.createElement('style');
  style.textContent = `
    @font-face{font-family:'SignaturePreviewSarabun';src:url('../lib/tcpdf/fonts/THSarabun.ttf')}
    @font-face{font-family:'SignaturePreviewSarabun';src:url('../lib/tcpdf/fonts/THSarabun Bold.ttf');font-weight:700}
    #signaturePreviewPanel label{margin:0;font-size:12px;line-height:1.5}
    #signaturePreviewPanel select{width:auto;min-width:65px;height:38px;padding:5px 10px;margin:0}
    #signaturePreviewPanel button{width:auto;white-space:nowrap;margin:0;flex:none}
    #signaturePreviewPages{display:flex;flex-wrap:wrap;gap:12px;max-height:380px!important;align-items:flex-start}
    #signaturePreviewPages .signature-box-preview{width:min(100%,340px);border:1px solid #bfd0e6;border-radius:8px;overflow:hidden;background:white}
    #signaturePreviewPages .signature-box-preview canvas{display:block;width:100%;height:auto}
  `;
  document.head.appendChild(style);
  panel.style.cssText = 'margin:20px 0;padding:16px;border:1px solid #dce4ef;border-radius:12px;background:#f8fbff';
  panel.innerHTML = '<h3 style="margin:0 0 10px">ตัวอย่างลายเซ็นในกล่องบนเอกสาร</h3><p>ดูตำแหน่งและขนาดก่อนยืนยันลงนาม — ตัวอย่างนี้ยังไม่บันทึกเอกสาร</p><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><button type="button" id="signaturePreviewRefresh">👁 ดูตัวอย่างลายเซ็น</button><label for="signaturePreviewPage">หน้า</label><select id="signaturePreviewPage"></select></div><p id="signaturePreviewStatus" role="status"></p><div id="signaturePreviewPages" style="overflow:auto;max-height:650px"></div>';
  panel.querySelector('h3').textContent = 'ตัวอย่างกล่องลายเซ็น';
  panel.querySelector('p').hidden = true;
  document.querySelector('label[for="rejectReason"]').before(panel);
  panel.hidden = true;
  const placement = () => ({scale:.85, x:.5, y:.5});
  window.getSignaturePlacement = placement;
  let allowedUser = '', permissionKey = '';
  let firstBoxOnly = false;
  const firstButton = document.createElement('button'); firstButton.type = 'button'; firstButton.textContent = '👁 พรีวิวกล่องแรก';
  panel.querySelector('#signaturePreviewRefresh').after(firstButton);
  window.previewFirstSignatureBox = async () => { firstBoxOnly = true; await refresh(); if (!panel.hidden) panel.scrollIntoView({block:'start',behavior:'smooth'}); };
  firstButton.onclick = window.previewFirstSignatureBox;
  const pagePicker = panel.querySelector('select');
  const status = panel.querySelector('[role="status"]');
  const pages = panel.querySelector('#signaturePreviewPages');
  let cachedId = '', pdfPromise = null, sequence = 0, timer;
  pdfjsLib.GlobalWorkerOptions.workerSrc = '../js/pdf.worker.min.js';
  const enabled = value => value !== false && value !== 0 && value !== 'false' && value !== '0';
  const eligible = () => selectedUserFields().filter(field => enabled(field.signAction ?? field.actions?.sign ?? true)
    && !(field.hiddenRows || []).includes('signatureLine') && !(field.hiddenRows || []).includes('signatureImage'));

  async function refresh() {
    const current = ++sequence;
    const firstDocument = isBulkSigning ? (selectedBulkDocuments()[0] || bulkDocuments[0]) : null;
    const previewDocsId = firstDocument?.docsId || workflowDocsId;
    const previewDocId = firstDocument?.docId || docIdInput.value.trim();
    const key = `${previewDocsId}:${previewDocId}:${userIdInput.value.trim()}`;
    if (key !== permissionKey) {
      permissionKey = key; allowedUser = ''; panel.hidden = true;
      try {
        const response = await fetch(`signature-preview-permission.php?docs_id=${encodeURIComponent(previewDocsId || '')}&docId=${encodeURIComponent(previewDocId)}`,{credentials:'same-origin'});
        const permission = await response.json();
        if (current !== sequence) { permissionKey = ''; return; }
        if (response.ok && permission.allowed) allowedUser = String(permission.user_id);
      } catch (_) { permissionKey = ''; }
    }
    if (!allowedUser || userIdInput.value.trim() !== allowedUser) { panel.hidden = true; return; }
    panel.hidden = false;
    let fields = eligible();
    if (firstDocument) {
      try {
        const loaded = await loadFieldsForBulkSigning(firstDocument.docId, userIdInput.value.trim());
        if (current !== sequence) return;
        fields = loaded.filter(field => String(field.user_id || field.assigneeId || '') === userIdInput.value.trim() && enabled(field.signAction ?? field.actions?.sign ?? true) && !(field.hiddenRows || []).includes('signatureLine') && !(field.hiddenRows || []).includes('signatureImage'));
      } catch(error) {status.textContent = error.message; return;}
    }
    if (firstBoxOnly) fields = fields.slice().sort((a,b) => Number(a.page || 1)-Number(b.page || 1) || Number(a.yRatio || 0)-Number(b.yRatio || 0) || Number(a.xRatio || 0)-Number(b.xRatio || 0)).slice(0,1);
    const numbers = [...new Set(fields.map(field => Number(field.page || 1)))].sort((a,b) => a-b);
    const chosen = numbers.includes(Number(pagePicker.value)) ? Number(pagePicker.value) : numbers[0];
    pagePicker.replaceChildren(...numbers.map(number => new Option(String(number),String(number))));
    pagePicker.value = String(chosen || '');
    pages.replaceChildren();
    if (!fields.length) { status.textContent = 'ไม่มีกล่องลายเซ็นที่แสดงได้สำหรับผู้ลงนามนี้'; return; }

    const id = previewDocId;
    if (!id) { status.textContent = 'ยังไม่ได้เลือกเอกสาร'; return; }
    status.textContent = 'กำลังแสดงตัวอย่าง...';
    try {
      if (id !== cachedId || !pdfPromise) {
        cachedId = id;
        pdfPromise = pdfjsLib.getDocument({url:signedDocumentUrl(id),withCredentials:true}).promise;
      }
      const pdf = await pdfPromise;
      const page = await pdf.getPage(chosen);
      const base = page.getViewport({scale:1});
      const scale = Math.min(1.6, Math.max(0.5,(panel.clientWidth-34)/base.width));
      const viewport = page.getViewport({scale});
      const preview = document.createElement('canvas');
      preview.width = Math.ceil(viewport.width); preview.height = Math.ceil(viewport.height);
      preview.style.cssText = 'display:block;width:100%;height:auto;background:white';
      const context = preview.getContext('2d');
      await page.render({canvasContext:context,viewport}).promise;
      if (current !== sequence) return;
      const image = new Image(); image.src = window.getSignatureCanvas().toDataURL('image/png'); await image.decode();
      await document.fonts.load('16px SignaturePreviewSarabun');
      await document.fonts.load('700 16px SignaturePreviewSarabun');
      if (current !== sequence) return;
      const renderedHeights = new Map();
      for (const field of fields.filter(item => Number(item.page || 1) === chosen)) {
        const hidden = field.hiddenRows || [];
        const values = {...field};
        for (const [key,inputId] of [['signerName','fName'],['position','fRole'],['organization','fOrg']]) {
          const value = document.getElementById(inputId)?.value.trim();
          if (value) values[key] = value;
        }
        if (field.signDateMode === 'none') values.signedDate = '';
        else if (['fixed','capped'].includes(field.signDateMode) && field.signDateFixed) {
          const fixed = new Date(`${field.signDateFixed}T00:00:00`), now = new Date(); now.setHours(0,0,0,0);
          const date = field.signDateMode === 'capped' && now < fixed ? now : fixed;
          values.signedDate = Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('th-TH',{dateStyle:'long'}).format(date);
        } else values.signedDate = thaiDate();
        const visible = key => !hidden.includes(key) && String(values[key] || '').trim();
        const x = Number(field.xRatio || 0)*base.width, y = Number(field.yRatio || 0)*base.height;
        const width = Math.max(20,Number(field.widthRatio || .4)*base.width);
        const rowKeys = ['signerName','position','faculty','actingForPresident','organization','signedDate'];
        const hasSignatureRow = !hidden.includes('signatureLine') &&
          (enabled(field.signatureSpace ?? false) || visible('signText') || visible('signatureImage') || visible('committee'));
        const requiredHeight = 10 + (visible('approval') ? 17 : 0) + (hasSignatureRow ? 42 : 0) +
          rowKeys.filter(key => visible(key)).length * 17;
        const height = Math.max(20,Number(field.heightRatio || .15)*base.height,requiredHeight);
        renderedHeights.set(field,height);
        const innerWidth = Math.max(10,width-14);
        const prefix = visible('signText') ? Math.min(42,innerWidth*.22) : 0;
        const suffix = visible('committee') ? Math.min(86,innerWidth*.38) : 0;
        const areaWidth = Math.max(0,innerWidth-prefix-suffix);
        const rowBottom = y+5+(visible('approval') ? 17 : 0)+(hasSignatureRow ? 42 : 0);
        // Redraw the whole field just as the signing service does, so the
        // original un-signed text cannot overlap the new signature row.
        context.save();
        context.fillStyle = '#fff'; context.fillRect(x*scale,y*scale,width*scale,height*scale);
        const fontSize = Math.max(8,Math.min(72,Number(field.fontSize || 12)));
        const text = (value,left,area,baseline,bold=false) => {
          if (!value) return;
          context.font = `${bold ? '700' : '400'} ${fontSize*scale}px SignaturePreviewSarabun`;
          context.fillStyle = '#000'; context.textBaseline = 'alphabetic';
          const measured = context.measureText(value).width/scale;
          context.fillText(value,(left+Math.max(2,(area-measured)/2))*scale,baseline*scale);
        };
        text(visible('approval'),x+7,innerWidth,y+5+fontSize,true);
        if (hasSignatureRow) {
          text(visible('signText'),x+7,prefix,rowBottom-3);
          text(visible('committee'),x+7+innerWidth-suffix,suffix,rowBottom-3);
        }
        let cursor = rowBottom;
        for (const key of rowKeys) {
          const value = visible(key); if (!value) continue;
          cursor += 17; if (cursor > y+height) break;
          text(value,x+7,innerWidth,cursor-2);
        }
        context.restore();
        const settings = placement();
        const imageScale = Math.min(areaWidth/image.width,38/image.height)*settings.scale;
        const drawWidth = image.width*imageScale, drawHeight = image.height*imageScale;
        if (hasSignatureRow) context.drawImage(image,(x+7+prefix+(areaWidth-drawWidth)*settings.x)*scale,
          (rowBottom-2-38+(38-drawHeight)*settings.y)*scale,drawWidth*scale,drawHeight*scale);
        context.save(); context.strokeStyle = '#1e6fcc'; context.lineWidth = 1; context.setLineDash([5,4]);
        context.strokeRect(x*scale,y*scale,width*scale,height*scale); context.restore();
      }
      const boxes = fields.filter(item => Number(item.page || 1) === chosen).map(field => {
        const left = Math.max(0, Math.round(Number(field.xRatio || 0)*base.width*scale));
        const top = Math.max(0, Math.round(Number(field.yRatio || 0)*base.height*scale));
        const width = Math.max(1,Math.min(preview.width-left,Math.round(Math.max(20,Number(field.widthRatio || .4)*base.width)*scale)));
        const height = Math.max(1,Math.min(preview.height-top,Math.round((renderedHeights.get(field) || Math.max(20,Number(field.heightRatio || .15)*base.height))*scale)));
        const cropped = document.createElement('canvas'); cropped.width=width; cropped.height=height;
        cropped.getContext('2d').drawImage(preview,left,top,width,height,0,0,width,height);
        const box = document.createElement('div'); box.className='signature-box-preview';
        box.appendChild(cropped); return box;
      });
      pages.replaceChildren(...boxes);
      status.textContent = `${firstDocument ? (firstDocument.no || '') + ' · ' : ''}หน้า ${chosen} · ${boxes.length} กล่อง · ${hasContent ? 'ตัวอย่างก่อนลงนาม' : 'วาดลายเซ็นเพื่อแสดงในกรอบนี้'}`;
    } catch (error) { if (current === sequence) { pdfPromise = null; status.textContent = `แสดงตัวอย่างไม่ได้: ${error.message}`; } }
  }
  const schedule = () => { clearTimeout(timer); timer = setTimeout(refresh,300); };
  document.addEventListener('eis-signature-changed',schedule);
  panel.querySelector('button').addEventListener('click',refresh);
  pagePicker.addEventListener('change',() => {firstBoxOnly=false; refresh();});
  canvas.addEventListener('pointerup',schedule);
  canvas.addEventListener('mouseup',schedule);
  canvas.addEventListener('touchend',schedule);
  clearBtn.addEventListener('click',schedule);
  fileInput.addEventListener('change',() => setTimeout(schedule,500));
  userIdInput.addEventListener('input',schedule);
  schedule();
})();

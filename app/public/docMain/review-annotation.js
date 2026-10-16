(() => {
    let pending = null;
    window.reviewAnnotation = {
        clear() { pending = null; document.getElementById('annotationState').textContent = ''; const canvas = document.getElementById('annotationCanvas'); canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height); },
        take(page) { const image = pending?.page === page ? pending.image : null; pending = null; document.getElementById('annotationState').textContent = ''; return image; },
        preview(image) { const dialog = document.createElement('dialog'); const img = document.createElement('img'); img.src = image; img.style.maxWidth = '85vw'; const close = document.createElement('button'); close.textContent = 'ปิด'; close.onclick = () => dialog.close(); dialog.append(close, img); dialog.onclose = () => dialog.remove(); document.body.append(dialog); dialog.showModal(); }
    };
    document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById('annotationDialog'), canvas = document.getElementById('annotationCanvas');
        const ctx = canvas.getContext('2d'), state = document.getElementById('annotationState');
        let pdf = null, base = null, page = 1, drawing = false;
        document.getElementById('annotatePage').onclick = async () => {
            const button = document.getElementById('annotatePage'); button.disabled = true;
            state.textContent = 'กำลังโหลดหน้าเอกสาร...';
            try {
                page = Number(document.getElementById('reviewMarkPage').value);
                if (!Number.isInteger(page) || page < 1) throw new Error('กรุณาระบุเลขหน้า');
                pdfjsLib.GlobalWorkerOptions.workerSrc = '../js/pdf.worker.min.js';
                const url = new URL('../pdf/view', location.href); url.searchParams.set('docId', document.getElementById('docIdInput').value.trim());
                if (!pdf) pdf = await pdfjsLib.getDocument(url.href).promise;
                if (page > pdf.numPages) throw new Error(`เอกสารมี ${pdf.numPages} หน้า`);
                const sheet = await pdf.getPage(page); const viewport = sheet.getViewport({scale:1.5});
                canvas.width = viewport.width; canvas.height = viewport.height;
                await sheet.render({canvasContext:ctx, viewport}).promise;
                base = ctx.getImageData(0,0,canvas.width,canvas.height);
                dialog.showModal(); state.textContent = `กำลังทำเครื่องหมายหน้า ${page}`;
            } catch(error) { state.textContent = error.message; } finally { button.disabled = false; }
        };
        const point = event => {const rect = canvas.getBoundingClientRect(); return [(event.clientX-rect.left)*canvas.width/rect.width,(event.clientY-rect.top)*canvas.height/rect.height];};
        canvas.onpointerdown = event => {drawing = true; canvas.setPointerCapture(event.pointerId); ctx.beginPath(); ctx.moveTo(...point(event)); ctx.strokeStyle = '#dc2626'; ctx.lineWidth = 3; ctx.lineCap = 'round';};
        canvas.onpointermove = event => {if(drawing){ctx.lineTo(...point(event));ctx.stroke();}};
        canvas.onpointerup = canvas.onpointercancel = () => {drawing = false;};
        document.getElementById('annotationClear').onclick = () => {if(base)ctx.putImageData(base,0,0);};
        document.getElementById('annotationCancel').onclick = () => dialog.close();
        document.getElementById('annotationSave').onclick = () => {pending = {page,image:canvas.toDataURL('image/jpeg',0.8)}; state.textContent = `เก็บรูปหน้า ${page} แล้ว — ใส่คำอธิบายแล้วกดเพิ่มรายการแก้ไข`; dialog.close();};
    });
})();

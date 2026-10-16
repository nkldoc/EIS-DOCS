(() => {
  const form = document.getElementById('adminDocumentForm');
  const all = document.getElementById('selectAllDocuments');
  const boxes = [...form.querySelectorAll('input[name="ids[]"]')];
  const update = () => {
    const count = boxes.filter(box => box.checked).length;
    document.getElementById('selectedCount').textContent = `เลือก ${count} รายการ`;
    all.checked = count > 0 && count === boxes.length;
    all.indeterminate = count > 0 && count < boxes.length;
  };
  all.addEventListener('change', () => { boxes.forEach(box => { box.checked = all.checked; }); update(); });
  boxes.forEach(box => box.addEventListener('change', update));
  form.addEventListener('submit', event => {
    const count = boxes.filter(box => box.checked).length;
    if (!count) { event.preventDefault(); alert('กรุณาเลือกอย่างน้อย 1 รายการ'); return; }
    const action = event.submitter?.value;
    const text = action === 'delete' ? `ลบ ${count} รายการและประวัติ Workflow ถาวร?` : `${action === 'disable' ? 'ยกเลิกการใช้งาน' : 'เปิดใช้งาน'} ${count} รายการ?`;
    if (!confirm(text)) event.preventDefault();
  });
})();

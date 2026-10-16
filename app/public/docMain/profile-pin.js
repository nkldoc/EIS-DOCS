(() => {
  const form = document.getElementById('profilePinForm');
  const status = document.getElementById('profilePinStatus');
  const edit = document.getElementById('profilePinEdit');
  const message = document.getElementById('profilePinMessage');
  const forgot = document.getElementById('profilePinForgot');
  const next = document.getElementById('profileNewPin');
  const confirmPinInput = document.getElementById('profileConfirmPin');
  let hasPin = false;
  async function request(body) {
    const response = await fetch('profile-pin.php', {method:body ? 'POST' : 'GET', credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="app-csrf"]').content}, ...(body ? {body:JSON.stringify(body)} : {})});
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'โหลด PIN ไม่สำเร็จ');
    return result;
  }
  function render(result) {
    hasPin = result.has_pin; form.reset();
    status.textContent = !hasPin ? 'ยังไม่ได้ตั้ง PIN' : result.can_edit ? 'admin ปลดล็อกแล้ว สามารถตั้ง PIN ใหม่ได้หนึ่งครั้ง' : result.request_status === 'PENDING' ? 'ส่งคำขอแล้ว รอ admin ปลดล็อก' : 'ตั้ง PIN แล้ว •••••• (ล็อกการแก้ไข)';
    edit.hidden = !result.can_edit; edit.textContent = hasPin ? 'ตั้ง PIN ใหม่' : 'ตั้ง PIN';
    forgot.hidden = !result.can_request;
    form.hidden = true;
  }
  document.querySelectorAll('[data-user-action="profile"]').forEach(element => element.addEventListener('click', async () => {
    form.reset(); form.hidden = true; edit.hidden = true; forgot.hidden = true; status.textContent = 'กำลังโหลด…'; message.textContent = '';
    try { render(await request()); } catch(error) {status.textContent = '—'; message.textContent = error.message;}
  }));
  edit.addEventListener('click', () => {form.hidden = false; form.reset(); next.focus(); message.textContent = '';});
  forgot.addEventListener('click', async () => {
    if(!window.confirm('แจ้งลืม PIN เพื่อขอให้ admin ปลดล็อกการตั้งรหัสใหม่?')) return;
    forgot.disabled = true;
    try {render(await request({action:'request_reset'})); message.textContent = 'ส่งคำขอให้ admin แล้ว กรุณาเปิดโปรไฟล์ใหม่หลังได้รับการปลดล็อก';}
    catch(error) {message.textContent = error.message;}
    finally {forgot.disabled = false;}
  });
  document.getElementById('profilePinCancel').addEventListener('click', () => {form.hidden = true; form.reset(); message.textContent = '';});
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if(next.value !== confirmPinInput.value) {message.textContent = 'รหัส PIN ยืนยันไม่ตรงกัน'; return;}
    const button = form.querySelector('button'); button.disabled = true;
    try {render(await request({pin:next.value, confirm_pin:confirmPinInput.value})); message.textContent = 'บันทึก PIN แล้ว ล็อกการแก้ไขเรียบร้อย';}
    catch(error) {message.textContent = error.message; next.value = ''; confirmPinInput.value = '';}
    finally {button.disabled = false;}
  });
})();

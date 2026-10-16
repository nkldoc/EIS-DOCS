(() => {
  const display = document.getElementById('profileEmailValue');
  const form = document.getElementById('profileEmailForm');
  const input = document.getElementById('profileEmailInput');
  const message = document.getElementById('profileEmailMessage');
  const button = form.querySelector('button');
  const edit = document.getElementById('profileEmailEdit');
  const cancel = document.getElementById('profileEmailCancel');
  let currentEmail = '';
  let loading = false;
  async function request(method = 'GET', email) {
    const response = await fetch('profile-email.php', {method, credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="app-csrf"]').content}, ...(method === 'POST' ? {body:JSON.stringify({email})} : {})});
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'โหลดอีเมลไม่สำเร็จ');
    return result;
  }
  function render(result) {
    currentEmail = result.email;
    edit.hidden = !currentEmail;
    display.textContent = result.email || 'ยังไม่ได้ระบุอีเมล';
    form.hidden = !result.can_save;
    input.value = '';
  }
  async function load() {
    if (loading) return;
    loading = true; form.hidden = true; edit.hidden = true; message.textContent = ''; display.textContent = 'กำลังโหลด…';
    try { render(await request()); } catch(error) { display.textContent = '—'; message.textContent = error.message; } finally { loading = false; }
  }
  document.querySelectorAll('[data-user-action="profile"]').forEach(element => element.addEventListener('click', load));
  edit.addEventListener('click', () => { form.hidden = false; input.value = currentEmail; input.focus(); message.textContent = ''; });
  cancel.addEventListener('click', () => { form.hidden = true; input.value = ''; message.textContent = ''; });
  form.addEventListener('submit', async event => {
    event.preventDefault(); button.disabled = true; message.textContent = 'กำลังบันทึก…';
    try { render(await request('POST', input.value.trim())); message.textContent = 'บันทึกอีเมลแล้ว'; }
    catch(error) { message.textContent = error.message; }
    finally { button.disabled = false; }
  });
})();

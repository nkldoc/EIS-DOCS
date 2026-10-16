document.addEventListener('DOMContentLoaded',()=>{
 const input=document.getElementById('apSearch'),list=document.getElementById('apSearchResults'),id=document.getElementById('selectedApId'),info=document.getElementById('apSelectedInfo');
 let timer,revision=0;
 input.addEventListener('input',()=>{
  clearTimeout(timer);const ticket=++revision;id.value='';info.textContent='ยังไม่ได้เลือกเลขตรวจรับ';list.replaceChildren();
  if(!input.value.trim())return;
  timer=setTimeout(async()=>{try{
   list.textContent='กำลังค้นหา...';const response=await fetch('ap-search.php?q='+encodeURIComponent(input.value.trim()),{credentials:'same-origin'});const result=await response.json();
   if(ticket!==revision)return;if(!response.ok||!result.success)throw new Error(result.message);
   list.replaceChildren();result.rows.forEach(row=>{
    const button=document.createElement('button');button.type='button';button.style.cssText='display:block;text-align:left;width:100%;padding:12px;background:white;border:1px solid #ddd;border-radius:6px;margin:5px 0';
    const text=`${row.c_code||'—'} — ${row.c_name||'ไม่ระบุชื่อ'} | ยอดตรวจรับรวม VAT ${row.amount==null?'ไม่ระบุ':Number(row.amount).toLocaleString('th-TH',{minimumFractionDigits:2})} บาท | สัญญา ${row.contract_code||'—'}`;
    button.textContent=text;button.onclick=()=>{revision++;id.value=row.id;input.value=row.c_code||'';info.textContent=text;list.replaceChildren();};list.append(button);
   });if(!result.rows.length)list.textContent='ไม่พบเลขตรวจรับ';
  }catch(error){if(ticket===revision)list.textContent=error.message;}},300);
 });
});

(() => {
  'use strict';
  const params = new URLSearchParams(location.search);
  const pages = document.getElementById('pages');
  const number = document.getElementById('number');
  const status = document.getElementById('status');
  let pdf, current = 1, zoom = 1, generation = 0, ticking = false;
  let sheets = [];
  const rendered = new Set();
  function select(page) {
    if (!pdf || !Number.isInteger(page) || page < 1 || page > pdf.numPages) return;
    current = page;
    number.value = page;
    parent.postMessage({type:'EIS_DOCS_REVIEW_PAGE_CHANGED', page}, location.origin);
  }
  function navigate(page) {
    if (!pdf) return;
    page = Math.max(1, Math.min(pdf.numPages, Number(page) || 1));
    select(page);
    pages.scrollTop = sheets[page - 1].offsetTop - pages.offsetTop - 12;
    update();
  }
  async function render(index) {
    if (rendered.has(index)) return;
    rendered.add(index);
    const version = generation;
    try {
      const page = await pdf.getPage(index + 1);
      if (version !== generation) return;
      const element = sheets[index];
      const scale = element.clientWidth / page.getViewport({scale:1}).width;
      const viewport = page.getViewport({scale:scale * Math.min(devicePixelRatio || 1, 2)});
      const canvas = document.createElement('canvas');
      canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
      element.replaceChildren(canvas);
      await page.render({canvasContext:canvas.getContext('2d'), viewport}).promise;
    } catch (error) { if (version === generation) status.textContent = error.message; }
  }
  function update() {
    ticking = false;
    if (!pdf) return;
    const bounds = pages.getBoundingClientRect();
    let best = current, largest = -1;
    sheets.forEach((sheet, index) => {
      const rect = sheet.getBoundingClientRect();
      const visible = Math.max(0, Math.min(rect.bottom, bounds.bottom) - Math.max(rect.top, bounds.top));
      if (visible > largest && visible > 0) { largest = visible; best = index + 1; }
      if (rect.bottom >= bounds.top - bounds.height && rect.top <= bounds.bottom + bounds.height) render(index);
    });
    if (best !== current) select(best);
  }
  function schedule() { if (!ticking) { ticking = true; requestAnimationFrame(update); } }
  async function layout(target = current) {
    const version = ++generation;
    rendered.clear();
    const width = Math.max(180, pages.clientWidth - 24) * zoom;
    const elements = [];
    for (let i = 1; i <= pdf.numPages; i++) {
      const page = await pdf.getPage(i);
      if (version !== generation) return;
      const viewport = page.getViewport({scale:1});
      const sheet = document.createElement('div');
      sheet.className = 'page'; sheet.dataset.page = i;
      sheet.style.width = `${width}px`; sheet.style.height = `${width * viewport.height / viewport.width}px`;
      elements.push(sheet);
    }
    sheets = elements; pages.replaceChildren(...sheets); navigate(target);
  }
  pages.addEventListener('scroll', schedule, {passive:true});
  pages.addEventListener('click', event => {
    const sheet = event.target.closest('.page'); if (sheet) select(Number(sheet.dataset.page));
  });
  number.addEventListener('change', () => navigate(Number(number.value)));
  document.getElementById('prev').onclick = () => navigate(current - 1);
  document.getElementById('next').onclick = () => navigate(current + 1);
  document.getElementById('in').onclick = () => {if(pdf){zoom = Math.min(3, zoom + .25); layout();}};
  document.getElementById('out').onclick = () => {if(pdf){zoom = Math.max(.5, zoom - .25); layout();}};
  window.addEventListener('message', event => {
    if (event.origin === location.origin && event.source === parent && event.data?.type === 'EIS_DOCS_REVIEW_GO_TO_PAGE') navigate(event.data.page);
  });
  let resizeTimer;
  window.addEventListener('resize', () => {clearTimeout(resizeTimer); resizeTimer = setTimeout(() => {if(pdf)layout();},150);});
  (async () => {
    try {
      const url = new URL(params.get('file'), location.href);
      if (url.origin !== location.origin) throw new Error('URL เอกสารไม่ถูกต้อง');
      pdfjsLib.GlobalWorkerOptions.workerSrc = '../js/pdf.worker.min.js';
      pdf = await pdfjsLib.getDocument(url.href).promise;
      number.max = pdf.numPages; document.getElementById('total').textContent = `/ ${pdf.numPages}`;
      await layout(Number(params.get('page')) || 1); status.textContent = '';
    } catch (error) {status.textContent = `โหลด PDF ไม่สำเร็จ: ${error.message}`;}
  })();
})();

(() => {
  "use strict";

  // ═══════════════════════════════════════════════════════════
  // DATA
  // ═══════════════════════════════════════════════════════════
  const ROLES = [
    { id: "r1", name: "ผู้จัดทำ", color: "#3B82F6" },
    { id: "r2", name: "ผู้ตรวจสอบ", color: "#8B5CF6" },
    { id: "r3", name: "ผู้อนุมัติ", color: "#10B981" },
    { id: "r4", name: "ผู้รับทราบ", color: "#F59E0B" },
  ];

  const EMPLOYEES = [
    { id: "e1", name: "นายสมชาย ใจดี", dept: "จัดซื้อ", position: "เจ้าหน้าที่จัดซื้อ" },
    { id: "e2", name: "นางสาวสมหญิง รักดี", dept: "จัดซื้อ", position: "หัวหน้าแผนกจัดซื้อ" },
    { id: "e3", name: "นายวิชัย มานะ", dept: "บัญชี", position: "ผู้จัดการบัญชี" },
    { id: "e4", name: "นางสาวอรุณี สวัสดี", dept: "บริหาร", position: "ผู้อำนวยการ" },
    { id: "e5", name: "นายประสิทธิ์ ดีงาม", dept: "จัดซื้อ", position: "ผู้จัดการจัดซื้อ" },
  ];

  const STEPS = [
    { icon: "📄", title: "ข้อมูลเอกสารลงนาม", subtitle: "กำหนดชนิดเอกสารและเลือกเอกสาร PR" },
    { icon: "👥", title: "กลุ่มผู้ลงนาม", subtitle: "สร้างกลุ่มและกำหนด role ของแต่ละคน" },
    { icon: "📌", title: "แสกน PDF & วาง Signature Box", subtitle: "อัปโหลด PDF และลากวางตำแหน่งกล่องลายเซ็น" },
    { icon: "💾", title: "ตรวจสอบและบันทึก", subtitle: "Preview และ save template" },
  ];

  let DOCS = [];
  const selectedPendingDocuments = new Set();
  let docsLoading = false;
  let docsError = "";
  let notifications = [];
  let notificationSocket = null;
  let notificationReconnectTimer = null;
  let notificationConnecting = false;
  let notificationRetryDelay = 5000;
  let notificationStopped = false;

  const DOC_TYPE_LABEL = { PR: "PR - ใบขอซื้อ/ขอจ้าง", PO: "PO - ใบสั่งซื้อ", GR: "GR - ใบรับสินค้า", IV: "IV - ใบแจ้งหนี้" };

  // ═══════════════════════════════════════════════════════════
  // BACKEND CONFIG — Spring Boot + PDFBox service
  // ═══════════════════════════════════════════════════════════
  const PDF_EXPORT_CONFIG = {
    // ปรับ URL นี้ให้ตรงกับ endpoint จริงของ service ที่ประมวลผลด้วย PDFBox
    // เช่น "/notif/api/pdf/export-signature-template" หรือ full URL ของ notif-server
    url: "/eis-docs/api/pdf/generate",
    method: "POST",
    timeoutMs: 60000, // PDFBox กับไฟล์หลายหน้าอาจใช้เวลา ปรับตามจริง
  };

  const uid = () => Math.random().toString(36).slice(2, 8);
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const PROCUREMENT_DOCUMENTS = [
    {id:1,title:"ขออนุมัติประกาศเผยแพร่แผนซื้อหรือจ้าง",page:1,group:1,children:[{id:2,title:"ประกาศเผยแพร่แผนซื้อหรือจ้าง",page:2,group:1}]},
    {id:3,title:"เห็นชอบ แต่งตั้งคณะกรรมการจัดทำ (TOR)",page:1,group:2,children:[{id:"ext-record-29",title:"แต่งตั้งคณะกรรมการจัดทำ (TOR)",page:2,group:2}]},
    {id:5,title:"รายงานผลการจัดทำ (TOR)",page:1,group:3,children:[{id:6,title:"รายงานขอซื้อ / รายงานขอจ้าง",page:2,group:3},{id:7,title:"แต่งตั้งคณะกรรมการ (พิจารณาผล/ตรวจรับพัสดุ)",page:3,group:3},{id:8,title:"- ร่าง - เอกสารประกวดราคา",page:4,group:3},{id:9,title:"- ร่าง - ประกาศประกวดราคา",page:5,group:3}]},
    {id:10,title:"รายงานผลการพิจารณาปรับปรุง - ร่าง-",page:1,group:4,children:[{id:11,title:"เอกสารประกวดราคา",page:2,group:4},{id:12,title:"ประกาศประกวดราคา",page:3,group:4}]},
    {id:13,title:"บริษัทที่ยื่นเสนอราคา",page:1,group:5,children:[{id:14,title:"ใบเสนอราคา",page:2,group:5},{id:15,title:"เอกสารบริษัทส่วน 1",page:3,group:5},{id:16,title:"เอกสารบริษัทส่วน 2",page:4,group:5},{id:17,title:"ใบต่อรองราคา",page:5,group:5},{id:18,title:"รายงานผลการพิจารณา (คณะกรรมการ)",page:6,group:5},{id:19,title:"รายงานผลการพิจารณาและขออนุมัติ (คณบดี)",page:7,group:5},{id:20,title:"แต่งตั้งคณะกรรมการ (ตรวจรับพัสดุ)",page:8,group:5},{id:21,title:"ประกาศผู้ชนะการเสนอราคา",page:9,group:5}]},
    {id:22,title:"พวช. หนังสือขอให้ตรวจร่างสัญญา (ส่งนิติกร)",page:1,group:6,children:[{id:23,title:"สนธ. ฝ่ายนิติการ ตรวจร่างสัญญา (จากนิติกร)",page:2,group:6}]},
    {id:24,title:"หนังสือแจ้งลงนามสัญญา (ส่งบริษัท)",page:1,group:7},
    {id:25,title:"หนังสือหลักค้ำประกันสัญญา (จากบริษัท)",page:1,group:8,children:[{id:26,title:"ตรวจสอบ หลักค้ำประกันสัญญา (ส่งธนาคาร)",page:2,group:8},{id:27,title:"ยืนยัน หลักค้ำประกันสัญญา (จากธนาคาร)",page:3,group:8}]},
    {id:28,title:"หนังสือลงนามสัญญา (คณบดี)",page:1,group:9},
    {id:"ext-record-30",title:"ใบสั่งซื้อ/จ้าง",page:1,group:10},
    {id:"ext-record-31",title:"ใบตรวจรับ",page:1,group:11}
  ].map(group => ({...group,status:1,sigLayout:1,children:(group.children || []).map(item => ({...item,status:1,sigLayout:1}))}));

  // ═══════════════════════════════════════════════════════════
  // STATE
  // ═══════════════════════════════════════════════════════════
  const state = {
    view: "create",
    step: 0,
    config: { docsId: null, docType: "PR - ใบขอซื้อ/ขอจ้าง", prId: "", prCode: "", prName: "", templateName: "", description: "", status: "Draft", amount: 0, department: "", docDate: "", filePath: "", pdfDocId: "", signDateMode: "actual", signDateFixed: "" },
    groups: [],
    procurementCatalog: JSON.parse(JSON.stringify(PROCUREMENT_DOCUMENTS)),
    procurementDocuments: [],
    signatureBoxes: [],
    editingGroupId: null,
    // pdf canvas
    zoom: 1,
    placingMode: false,
    selectedGroupId: null,
    pageNum: 1,
    totalPages: 1,
    startPos: null,
    // list view
    listTab: "all",
    search: "",
    typeFilter: "",
    pendingSearch: "",
    pendingTypeFilter: "",
    pendingTab: "queue",
  };

  // ═══════════════════════════════════════════════════════════
  // MAIN MENU
  // ═══════════════════════════════════════════════════════════
  function initMainMenu() {
    $$(".menu-item").forEach(btn => {
      btn.addEventListener("click", () => {
        state.view = btn.dataset.view;
        $$(".menu-item").forEach(b => b.classList.toggle("active", b === btn));
        $$(".app-view").forEach(v => v.classList.remove("active"));
        $(`#view-${state.view}`).classList.add("active");
        if (state.view === "list" || state.view === "pending") loadDocuments();
        document.body.classList.remove("mobile-menu-open");
        $("#mobileMenuToggle")?.setAttribute("aria-expanded", "false");
        if ($("#sidebarBackdrop")) $("#sidebarBackdrop").hidden = true;
      });
    });
  }

  function renderNotifications(unreadCount = notifications.filter(item => !item.is_read).length) {
    const badge = $('#notificationBadge');
    badge.hidden = unreadCount === 0; badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
    const container = $('#notificationItems');
    if (!notifications.length) { container.innerHTML = '<p>ยังไม่มีการแจ้งเตือน</p>'; return; }
    container.innerHTML = notifications.map(item => `<button class="notification-item ${item.is_read ? '' : 'unread'}" type="button" data-notification-id="${item.notification_id}" data-doc-id="${item.docs_id}" data-doc-no="${escapeHtml(item.doc_no || '')}"><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.message)}</small><small>${formatThaiDateTime(item.created_at)}</small></button>`).join('');
    $$('.notification-item', container).forEach(button => button.addEventListener('click', () => openNotification(button.dataset.notificationId, button.dataset.docId, button.dataset.docNo)));
  }

  async function loadNotifications() {
    try {
      const response = await fetch('notifications.php', {credentials: 'same-origin', cache: 'no-store'});
      const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.message);
      notifications = result.notifications || []; renderNotifications(Number(result.unread_count || 0));
    } catch (error) { console.warn('Notifications unavailable', error); }
  }

  async function markNotificationRead(notificationId) {
    await fetch('notifications.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json','X-CSRF-Token':wfCsrf}, body: JSON.stringify({notification_id: Number(notificationId)})});
  }

  async function openNotification(notificationId, docsId, docNo) {
    $('#notificationPopover').hidden = true; $('#notificationBell').setAttribute('aria-expanded', 'false');
    try { await markNotificationRead(notificationId); } catch (_) { }
    state.view = 'pending'; state.pendingSearch = docNo || '';
    $$('.menu-item').forEach(button => button.classList.toggle('active', button.dataset.view === 'pending'));
    $$('.app-view').forEach(view => view.classList.toggle('active', view.id === 'view-pending'));
    $('#pendingDocSearch').value = state.pendingSearch;
    await loadDocuments(); renderPendingDocs(); loadNotifications();
  }

  function notificationSocketUrl(configuredUrl) {
    const url = new URL(configuredUrl || '/eis-docs/ws/notifications', location.origin);
    if (url.protocol === 'https:') url.protocol = 'wss:';
    if (url.protocol === 'http:') url.protocol = 'ws:';
    if (location.protocol === 'https:' && url.protocol === 'ws:') url.protocol = 'wss:';
    if (!['ws:', 'wss:'].includes(url.protocol)) throw new Error('Invalid WebSocket URL');
    return url;
  }

  function scheduleNotificationReconnect() {
    clearTimeout(notificationReconnectTimer);
    if (notificationStopped) return;
    notificationReconnectTimer = setTimeout(connectNotificationSocket, notificationRetryDelay);
    notificationRetryDelay = Math.min(notificationRetryDelay * 2, 60000);
  }

  async function connectNotificationSocket() {
    if (notificationStopped || notificationConnecting || (notificationSocket && notificationSocket.readyState < 2)) return;
    notificationConnecting = true;
    try {
      const response = await fetch('notifications.php?action=ws_token', {credentials: 'same-origin', cache: 'no-store'});
      if (response.status === 401) { notificationStopped = true; return; }
      if (!response.ok) throw new Error('Notification token unavailable');
      const result = await response.json();
      if (!result.success) throw new Error('Notification token unavailable');
      if (!result.enabled || !result.token) { scheduleNotificationReconnect(); return; }
      if (notificationStopped) return;
      const configuredUrl = document.querySelector('meta[name="notification-ws-url"]')?.content;
      const wsBase = notificationSocketUrl(configuredUrl);
      wsBase.searchParams.set('token', result.token);
      const socket = new WebSocket(wsBase.href);
      notificationSocket = socket;
      socket.onopen = () => { notificationRetryDelay = 5000; loadNotifications(); };
      socket.onmessage = event => { try { const data = JSON.parse(event.data); if (data.type === 'EIS_DOCS_NOTIFICATION') loadNotifications(); } catch (_) {} };
      socket.onclose = () => { if (notificationSocket === socket) notificationSocket = null; scheduleNotificationReconnect(); };
      socket.onerror = () => { if (socket.readyState === WebSocket.OPEN) socket.close(); };
    } catch (_) { scheduleNotificationReconnect(); }
    finally { notificationConnecting = false; }
  }

  function initNotifications() {
    $('#notificationBell').addEventListener('click', () => { const popover = $('#notificationPopover'); popover.hidden = !popover.hidden; $('#notificationBell').setAttribute('aria-expanded', String(!popover.hidden)); if (!popover.hidden) loadNotifications(); });
    $('#markAllNotificationsRead').addEventListener('click', async () => { await fetch('notifications.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':wfCsrf},body:JSON.stringify({action:'mark_all_read'})}); await loadNotifications(); });
    loadNotifications(); connectNotificationSocket();
    // Keep notifications available while the proxy/socket reconnects.
    setInterval(() => { if (!notificationStopped && !document.hidden && notificationSocket?.readyState !== WebSocket.OPEN) loadNotifications(); }, 30000);
    window.addEventListener('pagehide', () => { notificationStopped = true; clearTimeout(notificationReconnectTimer); notificationSocket?.close(); });
    window.addEventListener('pageshow', event => { if (event.persisted) { notificationStopped = false; connectNotificationSocket(); } });
  }

  function initResponsiveSidebar() {
    const collapseToggle = $("#sidebarCollapseToggle");
    const mobileToggle = $("#mobileMenuToggle");
    const backdrop = $("#sidebarBackdrop");
    const mobileQuery = window.matchMedia("(max-width: 860px)");

    const setCollapsed = collapsed => {
      document.body.classList.toggle("sidebar-collapsed", collapsed);
      collapseToggle?.setAttribute("aria-expanded", String(!collapsed));
      collapseToggle?.setAttribute("aria-label", collapsed ? "ขยายเมนู" : "ย่อเมนู");
      localStorage.setItem("eis-docs-sidebar-collapsed", collapsed ? "1" : "0");
    };
    const setMobileOpen = open => {
      document.body.classList.toggle("mobile-menu-open", open);
      mobileToggle?.setAttribute("aria-expanded", String(open));
      mobileToggle?.setAttribute("aria-label", open ? "ปิดเมนูหลัก" : "เปิดเมนูหลัก");
      if (backdrop) backdrop.hidden = !open;
    };

    setCollapsed(localStorage.getItem("eis-docs-sidebar-collapsed") === "1");
    collapseToggle?.addEventListener("click", () => setCollapsed(!document.body.classList.contains("sidebar-collapsed")));
    mobileToggle?.addEventListener("click", () => setMobileOpen(!document.body.classList.contains("mobile-menu-open")));
    backdrop?.addEventListener("click", () => setMobileOpen(false));
    document.addEventListener("keydown", event => { if (event.key === "Escape") setMobileOpen(false); });
    mobileQuery.addEventListener("change", () => setMobileOpen(false));
  }

  function initUserMenu() {
    const chip = $("#userMenuChip");
    const toggle = $("#userMenuToggle");
    const menu = $("#userMenu");
    const modal = $("#userPanelModal");
    const modalTitle = $("#userPanelTitle");
    const modalSubtitle = $("#userPanelSubtitle");
    const modalIcon = $("#userPanelIcon");
    const profilePanel = $("#profilePanel");
    const settingsPanel = $("#settingsPanel");
    const compactSetting = $("#compactMenuSetting");
    const reduceMotionSetting = $("#reduceMotionSetting");
    if (!chip || !toggle || !menu || !modal) return;

    const setMenuOpen = open => {
      menu.hidden = !open;
      toggle.setAttribute("aria-expanded", String(open));
    };
    const openPanel = panel => {
      const settings = panel === "settings";
      profilePanel.hidden = settings;
      settingsPanel.hidden = !settings;
      modalIcon.textContent = settings ? "⚙️" : "👤";
      modalTitle.textContent = settings ? "ตั้งค่าการแสดงผล" : "โปรไฟล์ผู้ใช้";
      modalSubtitle.textContent = settings ? "ปรับรูปแบบหน้าจอให้เหมาะกับการใช้งาน" : "ข้อมูลบัญชีที่กำลังใช้งาน";
      modal.style.display = "flex";
      setMenuOpen(false);
    };

    toggle.addEventListener("click", event => {
      event.stopPropagation();
      setMenuOpen(menu.hidden);
    });
    document.addEventListener("click", event => {
      if (!chip.contains(event.target)) setMenuOpen(false);
    });
    $$('[data-user-action]').forEach(button => button.addEventListener("click", () => openPanel(button.dataset.userAction)));
    $("#userPanelClose")?.addEventListener("click", () => { modal.style.display = "none"; });
    modal.addEventListener("click", event => { if (event.target === modal) modal.style.display = "none"; });

    compactSetting.checked = localStorage.getItem("eis-docs-compact-menu") === "1"
      || localStorage.getItem("eis-docs-sidebar-collapsed") === "1";
    reduceMotionSetting.checked = localStorage.getItem("eis-docs-reduce-motion") === "1";
    document.body.classList.toggle("compact-menu", compactSetting.checked);
    document.body.classList.toggle("sidebar-collapsed", compactSetting.checked);
    document.body.classList.toggle("reduce-motion", reduceMotionSetting.checked);
    compactSetting.addEventListener("change", () => {
      document.body.classList.toggle("compact-menu", compactSetting.checked);
      document.body.classList.toggle("sidebar-collapsed", compactSetting.checked);
      localStorage.setItem("eis-docs-sidebar-collapsed", compactSetting.checked ? "1" : "0");
      localStorage.setItem("eis-docs-compact-menu", compactSetting.checked ? "1" : "0");
    });
    reduceMotionSetting.addEventListener("change", () => {
      document.body.classList.toggle("reduce-motion", reduceMotionSetting.checked);
      localStorage.setItem("eis-docs-reduce-motion", reduceMotionSetting.checked ? "1" : "0");
    });
  }

  // ═══════════════════════════════════════════════════════════
  // WIZARD: STEP NAVIGATION
  // ═══════════════════════════════════════════════════════════
  function goToStep(i) {
    state.step = i;
    $$(".tab-btn").forEach((b, idx) => b.classList.toggle("active", idx === i));
    $$(".step-item").forEach((el, idx) => {
      el.classList.toggle("active", idx === i);
      el.classList.toggle("done", idx < i);
      el.querySelector(".step-num").textContent = idx < i ? "✓" : String(idx + 1);
    });
    $$(".step-panel").forEach((el, idx) => el.classList.toggle("active", idx === i));

    $("#sectionIcon").textContent = STEPS[i].icon;
    $("#sectionTitle").textContent = STEPS[i].title;
    $("#sectionSubtitle").textContent = STEPS[i].subtitle;

    $("#backBtn").disabled = i === 0;
    $("#nextBtn").style.display = i === STEPS.length - 1 ? "none" : "inline-flex";

    if (i === 2) {
      initCanvasIfNeeded();
      syncSelectedGroupToLayout();
    }
    if (i === 3) {
      renderPreview();
      $('#layoutFrame')?.contentWindow?.postMessage({type:'EIS_DOCS_REQUEST_LAYOUT_STATE'},location.origin);
    }
  }

  function syncSelectedGroupToLayout() {
    const frame = $("#layoutFrame");
    if (!frame?.contentWindow) return;
    const group = state.groups.find(item => item.id === state.selectedGroupId) || state.groups[0];
    if (group && !group.fromLayout) {
      frame.contentWindow.postMessage({
        type: "EIS_DOCS_LOAD_WF_GROUP",
        groupId: String(group.sourceId || group.name),
      }, window.location.origin);
    }
    const catalog = state.procurementCatalog || [];
    const selectedIds = new Set(state.procurementDocuments.map(item => String(item.id)));
    const bookmarks = catalog.flatMap(parent => [
      ...(selectedIds.has(String(parent.id)) ? [{...parent, level: 0}] : []),
      ...(parent.children || []).filter(child => selectedIds.has(String(child.id))).map(child => ({...child, level: 1}))
    ]).sort((a, b) => Number(a.group) - Number(b.group) || Number(a.page) - Number(b.page));
    frame.contentWindow.postMessage({
      type: "EIS_DOCS_SYNC_PROCUREMENT_BOOKMARKS",
      prCode: state.config.prCode || "",
      docsId: state.config.docsId || null,
      documentPath: state.config.filePath || state.config.pdfDocId || "",
      documentType: state.config.docType || "",
      bookmarks: bookmarks.map(item => ({
        documentId: String(item.id), name: item.title, page: Number(item.page) || 1,
        group: Number(item.group) || 0, level: Number(item.level) || 0
      }))
    }, window.location.origin);
  }

  function initWizardNav() {
    $$(".tab-btn").forEach((b, i) => b.addEventListener("click", () => goToStep(i)));
    $$(".step-item").forEach((b, i) => b.addEventListener("click", () => goToStep(i)));
    $("#backBtn").addEventListener("click", () => goToStep(Math.max(0, state.step - 1)));
    $("#nextBtn").addEventListener("click", () => goToStep(Math.min(STEPS.length - 1, state.step + 1)));
  }

  // ═══════════════════════════════════════════════════════════
  // STEP 0: TEMPLATE CONFIG
  // ═══════════════════════════════════════════════════════════
  function initStep0() {
    $("#docType").addEventListener("change", e => state.config.docType = e.target.value);
    let prSearchTimer;
    const searchInput = $("#prSearch");
    searchInput.addEventListener("input", () => {
      if ($("#manualPrToggle").checked) return;
      clearTimeout(prSearchTimer);
      state.config.prId = "";
      state.config.prCode = "";
      state.config.prName = "";
      state.procurementDocuments = [];
      $("#prSelected").textContent = "กำลังค้นหา...";
      renderProcurementDocuments();
      prSearchTimer = setTimeout(() => searchPrDocuments(searchInput.value), 300);
    });
    searchInput.addEventListener("focus", () => {
      if (!$("#manualPrToggle").checked) searchPrDocuments(searchInput.value);
    });
    $("#manualPrToggle").addEventListener("change", event => {
      state.config.prId = "";
      state.config.prCode = "";
      state.config.prName = "";
      $("#prId").value = "";
      $("#manualPrCode").value = "";
      $("#manualPrName").value = "";
      $("#prSearch").value = "";
      state.procurementDocuments = [];
      syncManualPrMode(event.target.checked);
      if (!event.target.checked) $("#prSelected").textContent = "ยังไม่ได้เลือกเอกสาร PR";
      renderProcurementDocuments();
    });
    ["#manualPrCode", "#manualPrName"].forEach(selector => $(selector).addEventListener("input", () => {
      state.config.prId = "";
      state.config.prCode = $("#manualPrCode").value.trim();
      state.config.prName = $("#manualPrName").value.trim();
      $("#prId").value = "";
      $("#prSelected").textContent = state.config.prCode
        ? `${state.config.prCode}${state.config.prName ? ` — ${state.config.prName}` : ""} (ระบุเอง)`
        : "กรุณาระบุเลขที่อ้างอิง";
      renderProcurementDocuments();
      updateSignedDocumentPath();
    }));
    document.addEventListener("click", event => {
      if (!$("#prPicker").contains(event.target)) $("#prResults").hidden = true;
    });
    $("#procurementSelectAll").addEventListener("change", event => {
      setProcurementSelection(procurementFlatList(), event.target.checked);
      renderProcurementDocuments();
    });
    $("#procurementAddTopic").addEventListener("click", () => editProcurementItem(null, null));
    $("#templateName").addEventListener("input", e => {
      state.config.templateName = e.target.value;
      updateSignedDocumentPath();
    });
    $("#description").addEventListener("input", e => state.config.description = e.target.value);
    $("#serverFilePath").addEventListener("input", e => state.config.filePath = e.target.value);
    $("#savedDocumentSelect").addEventListener("change", e => e.target.value ? loadDocumentRecord(e.target.value) : resetDocumentForm());
    $("#newDocumentBtn").addEventListener("click", resetDocumentForm);
    $("#saveDocumentBtn").addEventListener("click", saveDocumentRecord);
    $("#deleteDocumentBtn").addEventListener("click", deleteDocumentRecord);
    $$(".status-btn").forEach(btn => {
      btn.addEventListener("click", () => {
        state.config.status = btn.dataset.status;
        $$(".status-btn").forEach(b => b.classList.toggle("active", b === btn));
      });
    });
    loadSavedDocumentOptions();
  }

  async function registerGeneratedPdf(message) {
    if (!state.config.docsId || (message.docsId && Number(message.docsId) !== Number(state.config.docsId))) {
      setCrudMessage("สร้าง PDF แล้ว แต่ยังไม่ได้บันทึกรายการนี้ใน EIS_DOCS กรุณาบันทึกข้อมูลก่อน", "err");
      return;
    }
    try {
      const response = await fetch("document-templates.php", {method:"POST", credentials:"same-origin",
        headers:{"Content-Type":"application/json","X-CSRF-Token":wfCsrf}, body:JSON.stringify({
          action:"publish_pdf", docs_id:state.config.docsId,
          pdf_doc_id:String(message.docId || ""), file_path:String(message.pdfPath || "")
        })});
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
      state.config.pdfDocId = String(message.docId || message.pdfPath || "");
      state.config.filePath = String(message.pdfPath || state.config.filePath || "");
      state.config.status = "PENDING";
      await loadDocuments();
      state.view = "pending";
      $$(".menu-item").forEach(button => button.classList.toggle("active", button.dataset.view === "pending"));
      $$(".app-view").forEach(view => view.classList.toggle("active", view.id === "view-pending"));
      switchPendingTab("queue");
      renderPendingDocs();
      window.scrollTo({top:0, behavior:"smooth"});
    } catch (error) {
      setCrudMessage(`สร้าง PDF สำเร็จ แต่ส่งเข้ารายการรอเซ็นไม่สำเร็จ: ${error.message}`, "err");
    }
  }

  async function searchPrDocuments(query = "") {
    const results = $("#prResults");
    results.hidden = false;
    results.innerHTML = '<div class="pr-result-state">กำลังค้นหาเอกสาร PR...</div>';
    try {
      const response = await fetch(`pr-documents.php?q=${encodeURIComponent(query.trim())}`, { credentials: "same-origin" });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) throw new Error(data.message || `HTTP ${response.status}`);
      const documents = Array.isArray(data.documents) ? data.documents : [];
      if (!documents.length) {
        results.innerHTML = '<div class="pr-result-state">ไม่พบเลข PR หรือชื่อเรื่องที่ค้นหา</div>';
        $("#prSelected").textContent = "ไม่พบเอกสาร PR";
        return;
      }
      results.replaceChildren(...documents.map(documentRow => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "pr-result-item";
        const code = document.createElement("strong");
        code.textContent = documentRow.code;
        const name = document.createElement("span");
        name.textContent = documentRow.name || "ไม่มีชื่อเรื่อง";
        button.append(code, name);
        button.addEventListener("click", () => selectPrDocument(documentRow));
        return button;
      }));
      $("#prSelected").textContent = `พบ ${documents.length} รายการ — เลือกเอกสารจากรายการ`;
    } catch (error) {
      results.innerHTML = `<div class="pr-result-state error">โหลดเอกสาร PR ไม่สำเร็จ: ${escapeHtml(error.message)}</div>`;
      $("#prSelected").textContent = "เรียกข้อมูลเอกสาร PR ไม่สำเร็จ";
    }
  }

  function selectPrDocument(documentRow) {
    $("#manualPrToggle").checked = false;
    syncManualPrMode(false);
    state.config.prId = String(documentRow.id || "");
    state.config.prCode = String(documentRow.code || "");
    state.config.prName = String(documentRow.name || "");
    state.config.amount = Number(documentRow.amount || 0);
    state.config.department = String(documentRow.department || "");
    state.config.docDate = String(documentRow.docDate || "");
    state.config.filePath = String(documentRow.filePath || "");
    $("#prId").value = state.config.prId;
    $("#prSearch").value = state.config.prCode;
    $("#prSelected").textContent = `${state.config.prCode} — ${state.config.prName}`;
    $("#serverFilePath").value = state.config.filePath;
    $("#prResults").hidden = true;
    state.procurementDocuments = [];
    renderProcurementDocuments();
    updateSignedDocumentPath();
  }

  function syncManualPrMode(enabled) {
    $("#manualPrToggle").checked = enabled;
    $("#manualPrFields").hidden = !enabled;
    $("#prSearch").disabled = enabled;
    $("#prResults").hidden = true;
    if (enabled) {
      $("#manualPrCode").value = state.config.prCode || "";
      $("#manualPrName").value = state.config.prName || "";
      $("#prSelected").textContent = state.config.prCode
        ? `${state.config.prCode}${state.config.prName ? ` — ${state.config.prName}` : ""} (ระบุเอง)`
        : "กรุณาระบุเลขที่อ้างอิงและชื่อรายการ";
    }
  }

  function signedDocumentFileName() {
    const prCode = String(state.config.prCode || "").trim();
    const documentName = Array.from(String(state.config.templateName || "").trim().replace(/[\\/:*?"<>|]+/g, "-").replace(/[. ]+$/g, "")).slice(0, 160).join("");
    return prCode && documentName ? `${prCode}- ${documentName}.pdf` : "";
  }

  function updateSignedDocumentPath() {
    const fileName = signedDocumentFileName();
    if (!fileName) return;
    state.config.filePath = `upload/${fileName}`;
    state.config.pdfDocId = "";
    $("#serverFilePath").value = state.config.filePath;
  }

  function setCrudMessage(message, kind = "") {
    const target = $("#documentCrudMessage");
    target.textContent = message;
    target.className = kind;
  }

  async function loadSavedDocumentOptions(selectedId = state.config.docsId) {
    try {
      const response = await fetch("document-templates.php", { credentials: "same-origin" });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
      const select = $("#savedDocumentSelect");
      select.innerHTML = '<option value="">— สร้างรายการใหม่ —</option>' + (result.documents || []).map(row =>
        `<option value="${row.docs_id}">${escapeHtml(row.doc_no)} — ${escapeHtml(row.template_name || row.description || "ไม่มีชื่อเอกสารลงนาม")}</option>`
      ).join("");
      if (selectedId) select.value = String(selectedId);
    } catch (error) {
      setCrudMessage(`โหลดข้อมูลที่บันทึกไม่สำเร็จ: ${error.message}`, "err");
    }
  }

  function applyDocumentRecord(row) {
    const saved = row.config && typeof row.config === "object" ? row.config : {};
    state.config = {...state.config, ...saved, docsId: row.docs_id, docType: row.doc_type === "PR" ? "PR - ใบขอซื้อ/ขอจ้าง" : (row.doc_type_name || saved.docType || "PR - ใบขอซื้อ/ขอจ้าง"),
      prId: String(row.pr_tor_id || saved.prId || ""), prCode: row.doc_no || "", prName: saved.prName || row.description || "",
      templateName: row.template_name || "", description: row.description || "", status: row.status || "Draft",
      amount: Number(row.amount || 0), department: row.department || "", docDate: row.doc_date || "",
      filePath: row.file_path || "", pdfDocId: row.pdf_doc_id || "",
      signDateMode: (saved.config && saved.config.signDateMode) || "actual",
      signDateFixed: (saved.config && saved.config.signDateFixed) || ""};
    if (Array.isArray(saved.procurementDocuments)) state.procurementDocuments = saved.procurementDocuments;
    if (Array.isArray(saved.procurementCatalog)) state.procurementCatalog = saved.procurementCatalog;
    if (Array.isArray(saved.groups)) state.groups = saved.groups;
    if (Array.isArray(saved.signatureBoxes)) state.signatureBoxes = saved.signatureBoxes;
    $("#docType").value = state.config.docType;
    $("#prId").value = state.config.prId;
    $("#prSearch").value = state.config.prCode;
    syncManualPrMode(!state.config.prId && Boolean(state.config.prCode));
    if (state.config.prId) $("#prSelected").textContent = `${state.config.prCode} — ${state.config.prName}`;
    $("#templateName").value = state.config.templateName;
    $("#description").value = state.config.description;
    $("#serverFilePath").value = state.config.filePath;
    $$(".status-btn").forEach(btn => btn.classList.toggle("active", btn.dataset.status.toUpperCase() === String(state.config.status).toUpperCase()));
    $("#deleteDocumentBtn").disabled = false;
    renderProcurementDocuments();
    renderGroups();
  }

  async function loadDocumentRecord(id) {
    try {
      setCrudMessage("กำลังโหลดข้อมูล...");
      const response = await fetch(`document-templates.php?id=${encodeURIComponent(id)}`, { credentials: "same-origin" });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success || !result.documents?.[0]) throw new Error(result.message || "ไม่พบข้อมูล");
      applyDocumentRecord(result.documents[0]);
      await loadRevisionFeedback(id);
      setCrudMessage("โหลดข้อมูลแล้ว สามารถแก้ไขและกดบันทึกได้", "ok");
    } catch (error) { setCrudMessage(`โหลดข้อมูลไม่สำเร็จ: ${error.message}`, "err"); }
  }

  function resetDocumentForm() {
    document.getElementById('revisionFeedback')?.remove();
    state.config = {docsId:null, docType:"PR - ใบขอซื้อ/ขอจ้าง", prId:"", prCode:"", prName:"", templateName:"", description:"", status:"Draft", amount:0, department:"", docDate:"", filePath:"", pdfDocId:"", signDateMode:"actual", signDateFixed:""};
    $("#savedDocumentSelect").value = ""; $("#docType").value = state.config.docType; $("#prId").value = "";
    $("#prSearch").value = ""; $("#prSelected").textContent = "ยังไม่ได้เลือกเอกสาร PR"; syncManualPrMode(false);
    $("#templateName").value = ""; $("#description").value = ""; $("#serverFilePath").value = "";
    $("#deleteDocumentBtn").disabled = true; setCrudMessage("พร้อมสร้างรายการใหม่");
  }

  async function saveDocumentRecord() {
    state.config.docType = $("#docType").value; state.config.templateName = $("#templateName").value.trim();
    updateSignedDocumentPath();
    state.config.description = $("#description").value.trim(); state.config.filePath = $("#serverFilePath").value.trim();
    const button = $("#saveDocumentBtn"); button.disabled = true; setCrudMessage("กำลังบันทึกลง EIS_DOCS...");
    try {
      const response = await fetch("document-templates.php", {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/json","X-CSRF-Token":wfCsrf}, body:JSON.stringify({
        docs_id:state.config.docsId, doc_type:state.config.docType, pr_id:state.config.prId, pr_code:state.config.prCode,
        template_name:state.config.templateName, description:state.config.description, status:state.config.status,
        amount:state.config.amount, department:state.config.department, doc_date:state.config.docDate,
        file_path:state.config.filePath, pdf_doc_id:state.config.pdfDocId, route:workflowRouteForSave(), config:buildExportData()
      })});
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
      state.config.docsId = result.docs_id; $("#deleteDocumentBtn").disabled = false;
      await loadSavedDocumentOptions(result.docs_id); setCrudMessage(result.message, "ok"); loadDocuments();
      return true;
    } catch (error) { setCrudMessage(`บันทึกไม่สำเร็จ: ${error.message}`, "err"); return false; }
    finally { button.disabled = false; }
  }

  async function deleteDocumentRecord() {
    if (!state.config.docsId || !confirm(`ยืนยันลบ ${state.config.prCode} — ${state.config.templateName}?`)) return;
    try {
      const response = await fetch("document-templates.php", {method:"DELETE", credentials:"same-origin", headers:{"Content-Type":"application/json","X-CSRF-Token":wfCsrf}, body:JSON.stringify({docs_id:state.config.docsId})});
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
      resetDocumentForm(); await loadSavedDocumentOptions(); loadDocuments(); setCrudMessage("ลบข้อมูลแล้ว", "ok");
    } catch (error) { setCrudMessage(`ลบข้อมูลไม่สำเร็จ: ${error.message}`, "err"); }
  }

  function procurementFlatList() {
    return state.procurementCatalog.flatMap(group => [group, ...(group.children || [])]);
  }

  function editProcurementItem(item, parentGroup) {
    const isNew = !item;
    const title = prompt(isNew ? "ชื่อหัวข้อเอกสาร" : "แก้ไขชื่อหัวข้อ", item?.title || "");
    if (title === null || !title.trim()) return;
    const defaultGroup = item?.group ?? parentGroup?.group ?? Math.max(0, ...state.procurementCatalog.map(row => Number(row.group) || 0)) + 1;
    const groupValue = prompt("หมายเลขชุด", String(defaultGroup));
    if (groupValue === null || !/^\d+$/.test(groupValue) || Number(groupValue) < 1) {
      alert("กรุณาระบุหมายเลขชุดเป็นตัวเลขตั้งแต่ 1 ขึ้นไป");
      return;
    }
    const pageValue = prompt("หมายเลขหน้า", String(item?.page ?? (parentGroup ? (parentGroup.children || []).length + 2 : 1)));
    if (pageValue === null || !/^\d+$/.test(pageValue) || Number(pageValue) < 1) {
      alert("กรุณาระบุหมายเลขหน้าเป็นตัวเลขตั้งแต่ 1 ขึ้นไป");
      return;
    }
    if (item) {
      const previousGroup = item.group;
      item.title = title.trim();
      item.group = Number(groupValue);
      item.page = Number(pageValue);
      if (!parentGroup && Array.isArray(item.children) && Number(previousGroup) !== item.group) {
        item.children.forEach(child => child.group = item.group);
      }
      [item, ...(item.children || [])].forEach(changedItem => {
        const selected = state.procurementDocuments.find(row => String(row.id) === String(changedItem.id));
        if (selected) Object.assign(selected, changedItem);
      });
    } else {
      const newItem = {id:`custom-${Date.now()}-${uid()}`,title:title.trim(),group:Number(groupValue),page:Number(pageValue),status:1,sigLayout:1};
      if (parentGroup) {
        parentGroup.children ||= [];
        parentGroup.children.push(newItem);
      } else {
        newItem.children = [];
        state.procurementCatalog.push(newItem);
      }
    }
    renderProcurementDocuments();
  }

  function deleteProcurementItem(item, parentGroup) {
    if (!confirm(`ลบ “${item.title}” หรือไม่?`)) return;
    const removeIds = new Set([item, ...(item.children || [])].map(row => String(row.id)));
    if (parentGroup) parentGroup.children = (parentGroup.children || []).filter(row => String(row.id) !== String(item.id));
    else state.procurementCatalog = state.procurementCatalog.filter(row => String(row.id) !== String(item.id));
    state.procurementDocuments = state.procurementDocuments.filter(row => !removeIds.has(String(row.id)));
    renderProcurementDocuments();
  }

  function setProcurementSelection(items, checked) {
    const ids = new Set(items.map(item => String(item.id)));
    if (checked) {
      const current = new Map(state.procurementDocuments.map(item => [String(item.id), item]));
      items.forEach(item => current.set(String(item.id), {...item}));
      state.procurementDocuments = [...current.values()];
    } else {
      state.procurementDocuments = state.procurementDocuments.filter(item => !ids.has(String(item.id)));
    }
  }

  function renderProcurementDocuments() {
    const enabled = Boolean(state.config.prId || state.config.prCode);
    const picker = $("#procurementPicker");
    const list = $("#procurementDocumentList");
    picker.classList.toggle("is-disabled", !enabled);
    picker.setAttribute("aria-disabled", String(!enabled));
    $("#procurementSelectAll").disabled = !enabled;
    $("#procurementAddTopic").disabled = !enabled;
    if (!enabled) {
      list.innerHTML = '<div class="procurement-empty">กรุณาเลือกเอกสาร PR ก่อน</div>';
      $("#procurementSummary").textContent = "เลือกแล้ว 0 รายการ";
      syncSelectedGroupToLayout();
      return;
    }
    const selectedIds = new Set(state.procurementDocuments.map(item => String(item.id)));
    list.innerHTML = state.procurementCatalog.map(group => {
      const groupItems = [group, ...(group.children || [])];
      const allGroupSelected = groupItems.every(item => selectedIds.has(String(item.id)));
      return `<div class="procurement-group">
        <div class="procurement-row parent">
          <input type="checkbox" class="procurement-group-check" data-group="${group.group}" ${allGroupSelected ? "checked" : ""} />
          <strong>${escapeHtml(group.title)}</strong><span>${group.group}</span><span>${group.page}</span>
          <span class="procurement-actions"><button type="button" data-action="add-child" data-id="${escapeHtml(String(group.id))}" title="เพิ่มรายการย่อย">＋</button><button type="button" data-action="edit" data-id="${escapeHtml(String(group.id))}" title="แก้ไข">✎</button><button type="button" data-action="delete" data-id="${escapeHtml(String(group.id))}" title="ลบ">×</button></span>
        </div>
        ${(group.children || []).map(item => `<label class="procurement-row child">
          <input type="checkbox" class="procurement-item-check" data-id="${escapeHtml(String(item.id))}" ${selectedIds.has(String(item.id)) ? "checked" : ""} />
          <span>${escapeHtml(item.title)}</span><span>${item.group}</span><span>${item.page}</span><span class="procurement-actions"><button type="button" data-action="edit" data-id="${escapeHtml(String(item.id))}" title="แก้ไข">✎</button><button type="button" data-action="delete" data-id="${escapeHtml(String(item.id))}" title="ลบ">×</button></span>
        </label>`).join("")}
      </div>`;
    }).join("");
    $$(".procurement-group-check", list).forEach(input => input.addEventListener("change", () => {
      const group = state.procurementCatalog.find(item => String(item.group) === input.dataset.group);
      setProcurementSelection([group, ...(group.children || [])], input.checked);
      renderProcurementDocuments();
    }));
    $$(".procurement-item-check", list).forEach(input => input.addEventListener("change", () => {
      const item = procurementFlatList().find(candidate => String(candidate.id) === input.dataset.id);
      setProcurementSelection([item], input.checked);
      renderProcurementDocuments();
    }));
    $$(".procurement-actions button", list).forEach(button => button.addEventListener("click", event => {
      event.preventDefault();
      event.stopPropagation();
      let parent = null;
      let item = state.procurementCatalog.find(row => String(row.id) === button.dataset.id);
      if (!item) {
        parent = state.procurementCatalog.find(row => (row.children || []).some(child => String(child.id) === button.dataset.id));
        item = (parent?.children || []).find(child => String(child.id) === button.dataset.id);
      }
      if (button.dataset.action === "add-child") editProcurementItem(null, item);
      else if (button.dataset.action === "edit") editProcurementItem(item, parent);
      else if (button.dataset.action === "delete") deleteProcurementItem(item, parent);
    }));
    const total = procurementFlatList().length;
    $("#procurementSelectAll").checked = state.procurementDocuments.length === total;
    $("#procurementSelectAll").indeterminate = state.procurementDocuments.length > 0 && state.procurementDocuments.length < total;
    $("#procurementSummary").textContent = `เลือกแล้ว ${state.procurementDocuments.length} จาก ${total} รายการ`;
    syncSelectedGroupToLayout();
  }

  // ═══════════════════════════════════════════════════════════
  // STEP 1: SIGNATURE GROUPS
  // ═══════════════════════════════════════════════════════════
  function removeGroup(gid) {
    state.groups = state.groups.filter(g => g.id !== gid);
    if (state.selectedGroupId === gid) state.selectedGroupId = state.groups[0]?.id || null;
    renderGroups();
  }

  const wfCsrf = document.querySelector('meta[name="app-csrf"]')?.content || "";
  const currentLoginUserId = document.querySelector('meta[name="app-user-id"]')?.content?.trim() || "";

  async function wfApiPost(payload) {
    const response = await fetch("wf-groups.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": wfCsrf },
      credentials: "same-origin",
      body: JSON.stringify(payload),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
    return result;
  }

  function memberActionLabel(member) {
    const actions = [];
    if (member.reviewAction) actions.push("ตรวจสอบ");
    if (member.signAction) actions.push("ลงนาม");
    if (member.approveAction) actions.push("อนุมัติ");
    return actions.join(" • ") || "ไม่ระบุ Action";
  }

  const wfVisibilityLabels = {private: 'เห็นเฉพาะตัว', department: 'เห็นทั้งหน่วยงาน', all: 'เห็นทั้งหมด'};
  function wfVisibilityOptions(value = 'private') {
    return Object.entries(wfVisibilityLabels).map(([key, label]) => `<option value="${key}" ${key === value ? 'selected' : ''}>${label}</option>`).join('');
  }

  function renderGroups() {
    const listEl = $("#groupList");
    if (state.groups.length === 0) {
      listEl.innerHTML = `<p class="empty-msg">ยังไม่มีกลุ่มผู้ลงนาม — เพิ่มกลุ่มแรกด้านบน</p>`;
    } else {
      listEl.innerHTML = state.groups.map((g, gi) => `
        <div class="card group-card" data-gid="${escapeHtml(g.id)}">
          <div class="group-header">
            <div class="group-header-left">
              <span class="group-idx">${gi + 1}</span>
              <span class="group-name">${escapeHtml(g.name)}</span>
              <span class="group-count">${g.members.length} คน</span>
              <span class="badge">${wfVisibilityLabels[g.visibility] || 'เห็นทั้งหมด'}</span>
            </div>
            <div style="display:flex;gap:6px">
              <button class="btn btn-subtle btn-sm js-edit-group">${state.editingGroupId === g.id ? "✕ ปิด" : (g.canEdit === false ? "ดู / Save As" : "✏️ แก้ไข")}</button>
              <button class="btn btn-danger btn-sm js-remove-group">นำออก</button>
              ${g.sourceId ? '<button class="btn btn-danger btn-sm js-delete-group">ลบกลุ่ม</button>' : ''}
            </div>
          </div>
          <div class="group-body">
            ${state.editingGroupId === g.id ? `<label style="display:block;margin-bottom:12px">ขอบเขตกลุ่มใหม่เมื่อ Save As <select class="js-copy-visibility" aria-label="ขอบเขตกลุ่มใหม่เมื่อ Save As">${wfVisibilityOptions(g.saveAsVisibility)}</select><small> Administrator จัดการได้ทุกกลุ่ม • ผู้ใช้ทั่วไปดูและคัดลอกกลุ่ม “เห็นทั้งหมด” ได้ แต่แก้ไขหรือลบได้เฉพาะกลุ่มของตนเอง</small></label>` : ''}
            ${state.editingGroupId === g.id ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px"><input class="js-group-name list-search" aria-label="ชื่อกลุ่มผู้ลงนาม" maxlength="255" value="${escapeHtml(g.draftName ?? g.name)}"><button class="btn btn-primary btn-sm js-rename-group">Save Update - บันทึกทับกลุ่มเดิม</button><button class="btn btn-subtle btn-sm js-copy-group">Save As - บันทึกเป็นกลุ่มใหม่</button><button class="btn btn-subtle btn-sm js-set-login-wf1">👤 ตั้งผู้ใช้ปัจจุบันเป็น WF1</button></div>` : ''}
            ${g.members.length === 0 ? `<p class="empty-msg" style="padding:0;font-style:italic">ยังไม่มีสมาชิก</p>` : ""}
            ${g.members.map((m, memberIndex) => `
              <div class="member-row" data-mid="${escapeHtml(m.id)}">
                <span class="member-order" title="ลำดับเส้นทาง Workflow" style="background:${m.roleColor}22;color:${m.roleColor}">WF ${g.memberReplacements?.[m.id]?.wf_order ?? m.wfOrder ?? m.order}</span>
                <span class="member-name">${escapeHtml(g.memberReplacements?.[m.id]?.name || m.empName)}${g.memberReplacements?.[m.id] ? ' <small>(รอบันทึก)</small>' : ''}${memberIndex === 0 ? ' <small>(เจ้าหน้าที่ผู้จัดทำ)</small>' : ''}</span>
                ${state.editingGroupId === g.id ? '<button class="btn btn-subtle btn-sm js-edit-member">แก้ไข</button>' : ''}
                <span class="badge" style="background:${m.roleColor}22;color:${m.roleColor};border:1px solid ${m.roleColor}44">${escapeHtml(memberActionLabel(g.memberReplacements?.[m.id] ? {reviewAction:g.memberReplacements[m.id].review_action,signAction:g.memberReplacements[m.id].sign_action,approveAction:g.memberReplacements[m.id].approve_action} : m))}</span>
                ${state.editingGroupId === g.id ? '<button class="remove-x js-remove-db-member" title="ลบผู้ใช้ออกจากกลุ่ม">✕</button>' : ''}
              </div>
            `).join("")}
            ${state.editingGroupId === g.id ? `
              <div class="wf-member-editor">
                <p class="js-member-edit-hint" role="status">ค้นหาผู้ใช้เพื่อเพิ่มสมาชิก หรือกดแก้ไขในแถวที่ต้องการเปลี่ยน</p>
                <button class="btn btn-subtle btn-sm js-cancel-member-edit" hidden>ยกเลิกการแก้ไขแถว</button>
                ${Object.keys(g.memberReplacements || {}).length ? '<button class="btn btn-subtle btn-sm js-discard-member-changes">ยกเลิกการแก้ไขทั้งหมด</button>' : ''}
                <div class="wf-user-search-row">
                  <input class="js-wf-user-search" type="search" placeholder="ค้นหาชื่อหรือ Username" autocomplete="off" />
                  <button class="btn btn-subtle btn-sm js-search-wf-user">ค้นหา</button>
                </div>
                <select class="js-wf-user-select" disabled><option value="">— ค้นหาผู้ใช้ก่อน —</option></select>
                <div class="wf-action-options">
                  <label><input type="checkbox" class="js-action-review" checked /> ตรวจสอบ</label>
                  <label><input type="checkbox" class="js-action-sign" /> ลงนาม</label>
                  <label><input type="checkbox" class="js-action-approve" /> อนุมัติ</label>
                </div>
                <label class="wf-order-field">ลำดับ WF <input type="number" class="js-new-wf-order" min="1" max="999" value="${g.members.length + 1}" /> <small>เมื่อบันทึก ระบบจะเลื่อนสมาชิกและเรียง WF1, WF2, WF3 ต่อเนื่องกัน</small></label>
                <button class="btn btn-primary btn-sm js-add-db-member" disabled>+ เพิ่มผู้ใช้</button>
              </div>
            ` : ""}
          </div>
        </div>
      `).join("");
    }

    // Replacements are drafts until Save Update or Save As succeeds.
    $$(".group-card", listEl).forEach(card => {
      const group = state.groups.find(g => String(g.id) === card.dataset.gid);
      if (group.canEdit === false) {
        $$('.js-rename-group, .js-delete-group, .js-set-login-wf1, .js-edit-member, .js-remove-db-member, .js-save-order, .wf-member-editor', card).forEach(control => {
          control.hidden = true;
          control.style.display = 'none';
          if ('disabled' in control) control.disabled = true;
        });
      }
      if (Object.keys(group.memberReplacements || {}).length) {
        $$(".js-set-login-wf1, .js-remove-db-member, .js-save-order, .js-add-db-member", card).forEach(button => button.disabled = true);
      }
    });
    $$(".js-discard-member-changes", listEl).forEach(button => button.addEventListener("click", () => {
      const group = state.groups.find(g => String(g.id) === button.closest(".group-card").dataset.gid);
      group.memberReplacements = {};
      renderGroups();
    }));
    $$(".js-cancel-member-edit", listEl).forEach(button => button.addEventListener("click", () => renderGroups()));
    $$(".js-edit-member", listEl).forEach(button => button.addEventListener("click", () => {
      const card = button.closest(".group-card");
      const group = state.groups.find(g => String(g.id) === card.dataset.gid);
      const member = group.members.find(m => m.id === button.closest(".member-row").dataset.mid);
      card.dataset.editMemberId = member.id;
      const draft = group.memberReplacements?.[member.id];
      $(".js-member-edit-hint", card).textContent = `แก้ไข ${draft?.name || member.empName} — เปลี่ยนสิทธิ์และลำดับ WF ได้ทันที หรือค้นหาเพื่อเปลี่ยนผู้ใช้`;
      $(".js-cancel-member-edit", card).hidden = false;
      $(".wf-action-options", card).hidden = false;
      $(".wf-order-field", card).hidden = false;
      $(".js-action-review", card).checked = draft?.review_action ?? member.reviewAction;
      $(".js-action-sign", card).checked = draft?.sign_action ?? member.signAction;
      $(".js-action-approve", card).checked = draft?.approve_action ?? member.approveAction;
      $(".js-new-wf-order", card).value = draft?.wf_order ?? member.wfOrder ?? member.order;
      const select = $(".js-wf-user-select", card);
      const option = new Option(draft?.name || member.empName, draft?.user_id || member.userId);
      option.dataset.empId = draft?.dc_emp_id || member.dcEmpId;
      option.dataset.empName = draft?.name || member.empName;
      select.replaceChildren(option);
      select.disabled = false;
      const apply = $(".js-add-db-member", card);
      apply.textContent = "ใช้การแก้ไข — รอบันทึก";
      apply.disabled = false;
      $(".js-action-review", card).focus();
    }));
    // wire events
    $$('.js-copy-visibility', listEl).forEach(select => select.addEventListener('change', () => {
      const group = state.groups.find(g => String(g.id) === select.closest('.group-card').dataset.gid);
      group.saveAsVisibility = select.value;
    }));
    $$('.js-rename-group, .js-copy-group',listEl).forEach(button=>button.addEventListener('click',async()=>{
      const card=button.closest('.group-card');const group=state.groups.find(g=>String(g.id)===card.dataset.gid);
      const name=$('.js-group-name',card).value.trim();if(!name){setWfGroupHint('กรุณาระบุชื่อกลุ่ม',true);return;}
      const isCopy = button.classList.contains('js-copy-group');
      if (isCopy && name === (group.sourceId || group.name)) { setWfGroupHint('กรุณาตั้งชื่อกลุ่มใหม่ก่อนกด Save As', true); $('.js-group-name', card).focus(); return; }
      const controls = $$('button, input, select', card).map(control => [control, control.disabled]);
      controls.forEach(([control]) => control.disabled = true);
      try {
        await wfApiPost({action:isCopy?'copy_group':'rename_group',source_name:group.sourceId || group.name,group_name:name,visibility:$('.js-copy-visibility',card).value,replacements:Object.values(group.memberReplacements || {}).map(({name, ...replacement}) => replacement)});
        group.memberReplacements = {};
        delete group.draftName;
        let savedGroup = group;
        if (isCopy) {
          savedGroup = {id: `wf-${name}`, sourceId:name, name, members:[]};
          state.groups.push(savedGroup);
        } else {
          group.name=name;group.sourceId=name;group.fromLayout=false;
        }
        state.selectedGroupId = savedGroup.id;
        state.editingGroupId = savedGroup.id;
        await reloadWfGroup(savedGroup);
        $('#wfGroupSearch').value = '';
        await searchWfGroups();
        $('#wfGroupSelect').value = name;
        $('#addWfGroupBtn').disabled = false;
        setWfGroupHint(`บันทึกกลุ่ม “${name}” พร้อมผู้ลงนาม ${savedGroup.members.length} คนแล้ว`);
      }catch(error){setWfGroupHint(error.message,true);}finally{controls.forEach(([control, disabled]) => control.disabled = disabled);}
    }));
    $$('.js-delete-group', listEl).forEach(button => button.addEventListener('click', async () => {
      const card = button.closest('.group-card');
      const group = state.groups.find(g => String(g.id) === card.dataset.gid);
      if (!group?.sourceId || !confirm(`ลบกลุ่ม “${group.name}” และสมาชิกทั้งหมดของกลุ่มนี้จากฐานข้อมูลหรือไม่?`)) return;
      const controls = $$('button, input, select', card).map(control => [control, control.disabled]);
      controls.forEach(([control]) => control.disabled = true);
      try {
        await wfApiPost({action:'delete_group', group_name:group.sourceId});
        if (state.editingGroupId === group.id) state.editingGroupId = null;
        removeGroup(group.id);
        await searchWfGroups();
        setWfGroupHint(`ลบกลุ่ม “${group.name}” แล้ว`);
      } catch (error) { setWfGroupHint(error.message, true); }
      finally { controls.forEach(([control, disabled]) => control.disabled = disabled); }
    }));
    $$('.js-set-login-wf1', listEl).forEach(button => button.addEventListener('click', async () => {
      const card = button.closest('.group-card');
      const group = state.groups.find(item => item.id === card.dataset.gid);
      if (!group || !confirm('ตั้งผู้ใช้ที่ล็อกอินอยู่เป็น WF1 ของกลุ่มนี้หรือไม่?')) return;
      button.disabled = true;
      try {
        await wfApiPost({ action: 'set_current_user_wf1', group_name: group.name });
        await reloadWfGroup(group);
        setWfGroupHint('ตั้งผู้ใช้ปัจจุบันเป็น WF1 แล้ว');
      } catch (error) {
        setWfGroupHint(`ตั้งค่า WF1 ไม่สำเร็จ: ${error.message}`, true);
      } finally {
        button.disabled = false;
      }
    }));
    $$(".js-edit-group", listEl).forEach(btn => {
      btn.addEventListener("click", () => {
        const gid = btn.closest(".group-card").dataset.gid;
        state.editingGroupId = state.editingGroupId === gid ? null : gid;
        renderGroups();
      });
    });
    $$(".js-remove-group", listEl).forEach(btn => {
      btn.addEventListener("click", () => removeGroup(btn.closest(".group-card").dataset.gid));
    });
    $$(".js-search-wf-user", listEl).forEach(btn => {
      btn.addEventListener("click", async () => {
        const card = btn.closest(".group-card");
        const input = $(".js-wf-user-search", card);
        const select = $(".js-wf-user-select", card);
        btn.disabled = true;
        select.disabled = true;
        $(".js-add-db-member", card).disabled = true;
        select.innerHTML = '<option value="">— กำลังค้นหา —</option>';
        try {
          const response = await fetch(`wf-groups.php?employee_q=${encodeURIComponent(input.value.trim())}`, { credentials: "same-origin" });
          const result = await response.json().catch(() => ({}));
          if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
          const employees = result.employees || [];
          select.replaceChildren(new Option(employees.length ? "— เลือกผู้ใช้ —" : "— ไม่พบผู้ใช้ —", ""));
          employees.forEach(employee => {
            const username = employee.c_user_name ? ` • ${employee.c_user_name}` : "";
            const option = new Option(`${employee.c_name}${username} (user_id: ${employee.user_id})`, employee.user_id);
            option.dataset.empId = employee.dc_emp_id;
            option.dataset.empName = employee.c_name;
            select.add(option);
          });
          select.disabled = employees.length === 0;
          select.onchange = () => {
            const group = state.groups.find(g => String(g.id) === card.dataset.gid);
            $(".js-add-db-member", card).disabled = !select.value || (!card.dataset.editMemberId && Object.keys(group.memberReplacements || {}).length > 0);
          };
        } catch (error) {
          setWfGroupHint(`ค้นหาผู้ใช้ไม่สำเร็จ: ${error.message}`, true);
        } finally {
          btn.disabled = false;
        }
      });
    });
    $$(".js-add-db-member", listEl).forEach(btn => {
      btn.addEventListener("click", async () => {
        const card = btn.closest(".group-card");
        const group = state.groups.find(item => item.id === card.dataset.gid);
        const select = $(".js-wf-user-select", card);
        const option = select.selectedOptions[0];
        if (!select.value || !option?.dataset.empId) { setWfGroupHint("กรุณาเลือกผู้ใช้", true); return; }
        if (card.dataset.editMemberId) {
          const member = group.members.find(m => m.id === card.dataset.editMemberId);
          if (group.members.some(m => m.id !== member.id && (m.userId === select.value || group.memberReplacements?.[m.id]?.user_id === select.value))) {
            setWfGroupHint("ผู้ใช้นี้อยู่ในกลุ่มแล้ว กรุณาเลือกผู้ใช้อื่น", true); return;
          }
          group.memberReplacements ||= {};
          const order = Number($(".js-new-wf-order", card).value);
          const review = $(".js-action-review", card).checked;
          const sign = $(".js-action-sign", card).checked;
          const approve = $(".js-action-approve", card).checked;
          if (!Number.isInteger(order) || order < 1 || order > 999) { setWfGroupHint("ลำดับ WF ต้องอยู่ระหว่าง 1–999", true); return; }
          if (!review && !sign && !approve) { setWfGroupHint("กรุณาเลือกสิทธิ์อย่างน้อย 1 รายการ", true); return; }
          group.memberReplacements[member.id] = { old_user_id: member.userId, old_dc_emp_id: member.dcEmpId, user_id: select.value, dc_emp_id: option.dataset.empId, name: option.dataset.empName, wf_order: order, review_action: review, sign_action: sign, approve_action: approve };
          group.draftName = $(".js-group-name", card).value;
          renderGroups();
          setWfGroupHint("แก้ไขแถวแล้ว กด Save Update หรือระบุชื่อกลุ่มใหม่แล้วกด Save As เพื่อบันทึก");
          return;
        }
        btn.disabled = true;
        try {
          await wfApiPost({
            action: "add_member", group_name: group.name,
            user_id: select.value, dc_emp_id: option.dataset.empId,
            review_action: $(".js-action-review", card).checked,
            sign_action: $(".js-action-sign", card).checked,
            approve_action: $(".js-action-approve", card).checked,
            wf_order: Number($(".js-new-wf-order", card).value),
          });
          await reloadWfGroup(group);
          setWfGroupHint(`เพิ่มผู้ใช้ในกลุ่ม “${group.name}” แล้ว`);
        } catch (error) {
          setWfGroupHint(`เพิ่มผู้ใช้ไม่สำเร็จ: ${error.message}`, true);
          btn.disabled = false;
        }
      });
    });
    $$(".js-remove-db-member", listEl).forEach(btn => {
      btn.addEventListener("click", async () => {
        const card = btn.closest(".group-card");
        const group = state.groups.find(item => item.id === card.dataset.gid);
        const member = group.members.find(item => item.id === btn.closest(".member-row").dataset.mid);
        if (!member || !confirm(`ลบ ${member.empName} ออกจากกลุ่มหรือไม่?`)) return;
        try {
          await wfApiPost({ action: "remove_member", group_name: group.name, user_id: member.userId, dc_emp_id: member.dcEmpId });
          await reloadWfGroup(group);
        } catch (error) {
          setWfGroupHint(`ลบผู้ใช้ไม่สำเร็จ: ${error.message}`, true);
        }
      });
    });
    $$(".js-save-order", listEl).forEach(btn => {
      btn.addEventListener("click", async () => {
        const card = btn.closest(".group-card");
        const group = state.groups.find(item => item.id === card.dataset.gid);
        const member = group.members.find(item => item.id === btn.closest(".member-row").dataset.mid);
        const wfOrder = Number($(".js-member-order", btn.closest(".member-row")).value);
        try {
          await wfApiPost({ action: "update_order", group_name: group.name, user_id: member.userId, dc_emp_id: member.dcEmpId, wf_order: wfOrder });
          await reloadWfGroup(group);
          setWfGroupHint(`บันทึกลำดับ WF ${wfOrder} แล้ว`);
        } catch (error) {
          setWfGroupHint(`แก้ไขลำดับไม่สำเร็จ: ${error.message}`, true);
        }
      });
    });

    renderGroupSelector();
  }

  let wfSearchTimer = null;

  async function reloadWfGroup(group) {
    const response = await fetch(`wf-groups.php?group_id=${encodeURIComponent(group.sourceId || group.name)}`, { credentials: "same-origin" });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
    group.members = wfMembers(result.group?.members || [], group.sourceId || group.name);
    group.visibility = result.group?.visibility || 'all';
    group.canEdit = result.group?.can_edit === true;
    renderGroups();
  }

  function wfMembers(sourceMembers, groupId) {
    return sourceMembers.map((member, index) => ({
      id: `${groupId}-${member.user_id}-${member.dc_emp_id || index}`,
      userId: String(member.user_id || ""),
      dcEmpId: String(member.dc_emp_id || ""),
      empName: member.c_name || `พนักงาน ${member.dc_emp_id || index + 1}`,
      reviewAction: Boolean(member.review_action),
      signAction: Boolean(member.sign_action),
      approveAction: Boolean(member.approve_action),
      wfOrder: Number(member.wf_order || index + 1),
      roleName: "ผู้ลงนาม", roleColor: ROLES[0].color, order: Number(member.wf_order || index + 1),
    })).sort((a, b) => a.wfOrder - b.wfOrder);
  }

  function workflowRouteForSave() {
    const group = state.groups.find(item => item.id === state.selectedGroupId) || state.groups[0];
    return (group?.members || []).slice()
      .sort((a, b) => Number(a.wfOrder || a.order) - Number(b.wfOrder || b.order))
      .map((member, index) => ({
        wf_order: index + 1,
        user_id: String(member.userId || ""),
        dc_emp_id: member.dcEmpId ? Number(member.dcEmpId) : null,
        signer_name: member.empName || "ผู้ลงนาม",
        review_action: Boolean(member.reviewAction),
        sign_action: Boolean(member.signAction),
        approve_action: Boolean(member.approveAction),
      }));
  }

  window.addEventListener('message', event => {
    if(event.origin!==location.origin || event.source!==$('#layoutFrame')?.contentWindow || event.data?.type!=='EIS_DOCS_LAYOUT_STATE')return;
    const data=event.data.data;
    if(!data || !Array.isArray(data.signatureFields))return;
    const fields=data.signatureFields;
    if(!fields.length)return;
    const groupId='layout-current';const members=new Map();
    fields.forEach((field,index)=>{
      const id=String(field.user_id||field.assigneeId||'');if(!id)return;
      const review=Boolean(field.reviewAction??field.actions?.review),sign=Boolean(field.signAction??field.actions?.sign),approve=Boolean(field.approveAction??field.actions?.approve);
      if(members.has(id)){const m=members.get(id);m.reviewAction ||= review;m.signAction ||= sign;m.approveAction ||= approve;return;}
      members.set(id,{id:`layout-${id}`,userId:id,dcEmpId:'',empName:field.assigneeName||field.signerName||id,reviewAction:review,signAction:sign,approveAction:approve,wfOrder:Number(field.wfOrder)||index+1,roleColor:ROLES[0].color});
    });
    const ordered=Array.from(members.values()).sort((a,b)=>a.wfOrder-b.wfOrder).map((m,index)=>({...m,wfOrder:index+1,order:index+1,roleName:[m.reviewAction&&'ตรวจสอบ',m.signAction&&'ลงนาม',m.approveAction&&'อนุมัติ'].filter(Boolean).join(' / ')}));
    state.groups=[{id:groupId,name:event.data.name||fields[0]?.wfGroupName||'กลุ่มจากผังปัจจุบัน',fromLayout:true,members:ordered}];
    state.selectedGroupId=groupId;
    state.signatureBoxes=fields.map(f=>({...f,id:f.id,groupId,page:f.page,x:Math.round(f.xRatio*(data.pageSize?.width||595)),y:Math.round(f.yRatio*(data.pageSize?.height||842)),w:Math.round(f.widthRatio*(data.pageSize?.width||595)),h:Math.round(f.heightRatio*(data.pageSize?.height||842))}));
    renderGroups();if(state.step===3)renderPreview();
  });

  function setWfGroupHint(message, isError = false) {
    const hint = $("#wfGroupHint");
    hint.textContent = message;
    hint.classList.toggle("err", isError);
    hint.classList.toggle("ok", !isError && Boolean(message));
  }

  async function searchWfGroups() {
    const select = $("#wfGroupSelect");
    const button = $("#addWfGroupBtn");
    const query = $("#wfGroupSearch").value.trim();
    select.disabled = true;
    button.disabled = true;
    select.innerHTML = '<option value="">— กำลังค้นหา —</option>';
    try {
      const response = await fetch(`wf-groups.php?q=${encodeURIComponent(query)}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
      const groups = Array.isArray(result.groups) ? result.groups : [];
      select.replaceChildren(new Option(groups.length ? "— เลือกกลุ่มผู้ลงนาม —" : "— ไม่พบกลุ่มผู้ลงนาม —", ""));
      groups.forEach(group => select.add(new Option(`${group.name} (${group.member_count} คน) • ${wfVisibilityLabels[group.visibility] || 'เห็นทั้งหมด'}`, group.id)));
      select.disabled = groups.length === 0;
      setWfGroupHint(groups.length ? `พบ ${groups.length} กลุ่มจากฐาน EIS_DOCS` : "ไม่พบกลุ่มที่ค้นหา");
    } catch (error) {
      select.innerHTML = '<option value="">— โหลดข้อมูลไม่สำเร็จ —</option>';
      setWfGroupHint(`โหลดกลุ่มผู้ลงนามไม่สำเร็จ: ${error.message}`, true);
    }
  }

  async function addSelectedWfGroup() {
    const select = $("#wfGroupSelect");
    const groupId = select.value;
    if (!groupId) return;
    const existing = state.groups.find(group => String(group.sourceId) === groupId);
    if (existing) { state.editingGroupId = existing.id; state.selectedGroupId = existing.id; renderGroups(); return; }
    const button = $("#addWfGroupBtn");
    button.disabled = true;
    try {
      const response = await fetch(`wf-groups.php?group_id=${encodeURIComponent(groupId)}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
      const source = result.group || {};
      const members = wfMembers(Array.isArray(source.members) ? source.members : [], groupId);
      const group = { id: `wf-${groupId}`, sourceId: groupId, name: source.name || select.selectedOptions[0].text, visibility:source.visibility || 'all', canEdit:source.can_edit === true, members };
      state.groups.push(group);
      state.selectedGroupId = group.id;
      state.editingGroupId = group.id;
      renderGroups();
      setWfGroupHint(`เลือกกลุ่ม “${group.name}” แล้ว จำนวน ${members.length} คน`);
    } catch (error) {
      setWfGroupHint(`เพิ่มกลุ่มไม่สำเร็จ: ${error.message}`, true);
    } finally {
      button.disabled = !select.value;
    }
  }

  function initStep1() {
    $("#wfGroupSelect").addEventListener("change", event => {
      $("#addWfGroupBtn").disabled = !event.target.value;
    });
    $("#addWfGroupBtn").addEventListener("click", addSelectedWfGroup);
    $("#createWfGroupBtn").addEventListener("click", async () => {
      const input = $("#newWfGroupName");
      const name = input.value.trim();
      if (!name) return;
      try {
        await wfApiPost({ action: "create_group", group_name: name, visibility: $('#newWfGroupVisibility').value });
        input.value = "";
        $("#wfGroupSearch").value = name;
        await searchWfGroups();
        $("#wfGroupSelect").value = name;
        $("#addWfGroupBtn").disabled = false;
        setWfGroupHint(`สร้างกลุ่ม “${name}” แล้ว — เพิ่มผู้ใช้ที่ล็อกอินเป็น WF1 ให้อัตโนมัติ`);
      } catch (error) {
        setWfGroupHint(`สร้างกลุ่มไม่สำเร็จ: ${error.message}`, true);
      }
    });
    $("#wfGroupSearch").addEventListener("input", () => {
      clearTimeout(wfSearchTimer);
      wfSearchTimer = setTimeout(searchWfGroups, 300);
    });
    searchWfGroups();
    $("#layoutFrame")?.addEventListener("load", syncSelectedGroupToLayout);
  }

  // ═══════════════════════════════════════════════════════════
  // STEP 2: PDF CANVAS + SIGNATURE BOX PLACEMENT
  // ═══════════════════════════════════════════════════════════
  let canvasInited = false;
  const CANVAS_W = 595, CANVAS_H = 842;

  function renderFakePDF() {
    const canvas = $("#pdfCanvas");
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, CANVAS_W, CANVAS_H);
    ctx.fillStyle = "#f0f4f8"; ctx.fillRect(40, 40, CANVAS_W - 80, 80);
    ctx.fillStyle = "#1E6FCC"; ctx.font = "bold 18px sans-serif";
    ctx.fillText("ใบขอซื้อ (PR) - Purchase Request", 60, 85);
    ctx.fillStyle = "#6B7280"; ctx.font = "12px sans-serif";
    ["เลขที่เอกสาร:", "วันที่:", "แผนก:", "ผู้ขอซื้อ:", "รายการสินค้า:"].forEach((t, i) => {
      ctx.fillText(t, 60, 160 + i * 30);
      ctx.strokeStyle = "#E2E6EA";
      ctx.strokeRect(160, 145 + i * 30, 380, 22);
    });
    ctx.fillStyle = "#374151"; ctx.font = "bold 13px sans-serif";
    ctx.fillText("ตาราง รายการสินค้า", 60, 330);
    ctx.strokeStyle = "#E2E6EA"; ctx.strokeRect(40, 340, CANVAS_W - 80, 200);
    ["ลำดับ", "รายการ", "จำนวน", "หน่วย", "ราคา/หน่วย", "รวม"].forEach((h, i) => {
      const x = 40 + i * ((CANVAS_W - 80) / 6);
      ctx.fillStyle = "#F3F4F6"; ctx.fillRect(x, 340, (CANVAS_W - 80) / 6, 24);
      ctx.strokeStyle = "#D1D5DB"; ctx.strokeRect(x, 340, (CANVAS_W - 80) / 6, 24);
      ctx.fillStyle = "#374151"; ctx.font = "bold 10px sans-serif";
      ctx.fillText(h, x + 4, 356);
    });
    ctx.fillStyle = "#9CA3AF"; ctx.font = "11px sans-serif";
    ctx.fillText("(ลากเพื่อวางกล่องลายเซ็น ด้านล่าง)", 60, 580);
    ctx.fillStyle = "#E5E7EB"; ctx.fillRect(40, 600, CANVAS_W - 80, 200);
    ctx.fillStyle = "#9CA3AF"; ctx.font = "italic 12px sans-serif";
    ctx.fillText("พื้นที่ลายเซ็น — ลากวางกล่องที่นี่", 180, 700);
  }

  function initCanvasIfNeeded() {
    if (canvasInited) { renderGroupSelector(); renderBoxOverlay(); return; }
    canvasInited = true;
    renderFakePDF();

    $("#pdfUpload").addEventListener("change", e => {
      // Real PDF rendering requires pdf.js which isn't bundled here; keep the mock preview.
      if (e.target.files[0]) renderFakePDF();
    });

    $("#placeModeBtn").addEventListener("click", () => {
      state.placingMode = !state.placingMode;
      $("#placeModeBtn").textContent = state.placingMode ? "⏹ หยุดวาง" : "✏️ วางกล่องลายเซ็น";
      $("#placeModeBtn").classList.toggle("btn-danger", state.placingMode);
      $("#placeModeBtn").classList.toggle("btn-ghost", !state.placingMode);
      $("#placeBanner").style.display = state.placingMode ? "block" : "none";
      $("#canvasContainer").classList.toggle("placing", state.placingMode);
    });

    $("#zoomIn").addEventListener("click", () => setZoom(Math.min(2, state.zoom + 0.1)));
    $("#zoomOut").addEventListener("click", () => setZoom(Math.max(0.5, state.zoom - 0.1)));

    const canvas = $("#pdfCanvas");
    canvas.addEventListener("mousedown", onCanvasDown);
    canvas.addEventListener("mousemove", onCanvasMove);
    canvas.addEventListener("mouseup", onCanvasUp);

    setZoom(1);
    renderGroupSelector();
    renderBoxOverlay();
  }

  function setZoom(z) {
    state.zoom = z;
    $("#pdfCanvas").style.transform = `scale(${z})`;
    $("#boxOverlay").style.width = (CANVAS_W * z) + "px";
    $("#boxOverlay").style.height = (CANVAS_H * z) + "px";
    $("#zoomLabel").textContent = Math.round(z * 100) + "%";
    renderBoxOverlay();
  }

  function getCanvasPos(e) {
    const rect = $("#pdfCanvas").getBoundingClientRect();
    return {
      x: Math.round((e.clientX - rect.left) / state.zoom),
      y: Math.round((e.clientY - rect.top) / state.zoom),
    };
  }

  function onCanvasDown(e) {
    if (!state.placingMode || !state.selectedGroupId) return;
    state.startPos = getCanvasPos(e);
  }
  function onCanvasMove(e) {
    if (!state.placingMode || !state.startPos) return;
    const pos = getCanvasPos(e);
    renderBoxOverlay({
      x: Math.min(state.startPos.x, pos.x), y: Math.min(state.startPos.y, pos.y),
      w: Math.abs(pos.x - state.startPos.x), h: Math.abs(pos.y - state.startPos.y),
    });
  }
  function onCanvasUp(e) {
    if (!state.placingMode || !state.startPos) return;
    const pos = getCanvasPos(e);
    const box = {
      id: uid(), groupId: state.selectedGroupId, page: state.pageNum,
      x: Math.min(state.startPos.x, pos.x), y: Math.min(state.startPos.y, pos.y),
      w: Math.max(Math.abs(pos.x - state.startPos.x), 80),
      h: Math.max(Math.abs(pos.y - state.startPos.y), 40),
    };
    if (box.w > 10 && box.h > 10) state.signatureBoxes.push(box);
    state.startPos = null;
    renderBoxOverlay();
    renderBoxList();
  }

  function renderGroupSelector() {
    const el = $("#groupSelector");
    if (!el) return;
    if (state.groups.length === 0) {
      el.innerHTML = `<p class="empty-msg">ยังไม่มีกลุ่ม — สร้างในขั้นตอนที่ 2</p>`;
      return;
    }
    if (!state.selectedGroupId) state.selectedGroupId = state.groups[0].id;
    el.innerHTML = state.groups.map(g => `
      <div class="group-pick-row ${state.selectedGroupId === g.id ? "selected" : ""}" data-gid="${g.id}" style="${state.selectedGroupId === g.id ? `background:${roleColorForGroup(g)}18` : ""}">
        <div class="group-dot" style="background:${roleColorForGroup(g)}"></div>
        <span class="group-pick-name" style="font-weight:${state.selectedGroupId === g.id ? 700 : 400}">${escapeHtml(g.name)}</span>
        <span class="group-pick-count">${g.members.length} คน</span>
      </div>
    `).join("");
    $$(".group-pick-row", el).forEach(row => {
      row.addEventListener("click", () => {
        state.selectedGroupId = row.dataset.gid;
        renderGroupSelector();
      });
    });
    renderBoxList();
  }

  // give each group a stable color derived from its id for visual distinction
  const GROUP_PALETTE = ["#1E6FCC", "#8B5CF6", "#10B981", "#F59E0B", "#EC4899", "#06B6D4"];
  function roleColorForGroup(g) {
    const idx = state.groups.findIndex(x => x.id === g.id);
    return GROUP_PALETTE[idx % GROUP_PALETTE.length];
  }

  function renderBoxOverlay(preview) {
    const overlay = $("#boxOverlay");
    if (!overlay) return;
    const pageBoxes = state.signatureBoxes.filter(b => b.page === state.pageNum);
    let html = pageBoxes.map(box => {
      const g = state.groups.find(gr => gr.id === box.groupId);
      const color = g ? roleColorForGroup(g) : "#1E6FCC";
      return `
        <div class="sig-box" style="left:${box.x * state.zoom}px;top:${box.y * state.zoom}px;width:${box.w * state.zoom}px;height:${box.h * state.zoom}px;border-color:${color};background:${color}18">
          <span style="color:${color}">${g ? escapeHtml(g.name) : "?"}</span>
        </div>`;
    }).join("");
    if (preview) {
      html += `<div class="preview-box" style="left:${preview.x * state.zoom}px;top:${preview.y * state.zoom}px;width:${preview.w * state.zoom}px;height:${preview.h * state.zoom}px"></div>`;
    }
    overlay.innerHTML = html;
  }

  function renderBoxList() {
    const listEl = $("#boxList");
    const countEl = $("#boxCount");
    if (!listEl) return;
    countEl.textContent = state.signatureBoxes.length;
    if (state.signatureBoxes.length === 0) {
      listEl.innerHTML = `<p class="empty-msg" style="padding:0;font-style:italic">ยังไม่มีกล่อง</p>`;
      return;
    }
    listEl.innerHTML = state.signatureBoxes.map(box => {
      const g = state.groups.find(gr => gr.id === box.groupId);
      const color = g ? roleColorForGroup(g) : "#ccc";
      return `
        <div class="box-row" data-bid="${box.id}">
          <div class="group-dot" style="width:8px;height:8px;background:${color}"></div>
          <span class="box-row-name">${g ? escapeHtml(g.name) : "?"} — หน้า ${box.page}</span>
          <span class="box-row-dim">${box.w}×${box.h}</span>
          <button class="remove-x js-remove-box">✕</button>
        </div>`;
    }).join("");
    $$(".js-remove-box", listEl).forEach(btn => {
      btn.addEventListener("click", () => {
        const bid = btn.closest(".box-row").dataset.bid;
        state.signatureBoxes = state.signatureBoxes.filter(b => b.id !== bid);
        renderBoxOverlay();
        renderBoxList();
      });
    });
  }

  // ═══════════════════════════════════════════════════════════
  // STEP 3: PREVIEW + SAVE
  // ═══════════════════════════════════════════════════════════
  function renderSignDateOption() {
    const c = state.config;
    const mode = c.signDateMode || "actual";
    $$('input[name="signDateMode"]').forEach(r => { r.checked = r.value === mode; });
    const showDate = mode === "fixed" || mode === "capped";
    const row = $("#signDateValueRow");
    if (row) row.style.display = showDate ? "block" : "none";
    const dateInput = $("#signDateValue");
    if (dateInput) dateInput.value = c.signDateFixed || "";
  }

  function initSignDateOption() {
    $$('input[name="signDateMode"]').forEach(radio => {
      radio.addEventListener("change", () => {
        if (!radio.checked) return;
        state.config.signDateMode = radio.value;
        renderSignDateOption();
      });
    });
    const dateInput = $("#signDateValue");
    dateInput?.addEventListener("change", () => {
      state.config.signDateFixed = dateInput.value;
    });
  }

  function renderPreview() {
    const c = state.config;
    renderSignDateOption();
    $("#previewConfig").innerHTML = [
      ["ชนิดเอกสาร", c.docType], ["เอกสาร PR", [c.prCode, c.prName].filter(Boolean).join(" — ")],
      ["เอกสารจัดซื้อที่เลือก", `${state.procurementDocuments.length} รายการ`],
      ["ชื่อเอกสารลงนาม", c.templateName], ["สถานะ", c.status],
    ].map(([k, v]) => `<div class="preview-row"><span class="k">${k}</span><span class="v">${escapeHtml(v) || "—"}</span></div>`).join("");

    $("#previewBoxCount").textContent = state.signatureBoxes.length;
    $("#previewBoxes").innerHTML = state.signatureBoxes.length === 0
      ? `<p class="empty-msg" style="padding:0">ยังไม่มีกล่อง</p>`
      : state.signatureBoxes.map(box => {
          const g = state.groups.find(gr => gr.id === box.groupId);
          const color = g ? roleColorForGroup(g) : "#ccc";
          return `<div class="preview-box-row">
            <div class="group-dot" style="width:8px;height:8px;background:${color};margin-top:3px"></div>
            <span style="flex:1">${g ? escapeHtml(g.name) : "?"}</span>
            <span style="color:var(--text-muted)">หน้า ${box.page}, (${box.x},${box.y}) ${box.w}×${box.h}px</span>
          </div>`;
        }).join("");

    $("#previewGroupCount").textContent = state.groups.length;
    $("#previewGroups").innerHTML = state.groups.map(g => `
      <div class="preview-group-block">
        <div class="preview-group-head">
          <div class="group-dot" style="background:${roleColorForGroup(g)}"></div>
          <span style="font-weight:700;font-size:13px">${escapeHtml(g.name)}</span>
        </div>
        ${g.members.map(m => `
          <div class="preview-member-row">
            <span style="flex:1;color:var(--text)">${escapeHtml(m.empName)}</span>
            <span class="badge" style="background:${m.roleColor}22;color:${m.roleColor};border:1px solid ${m.roleColor}44">${m.roleName}</span>
          </div>`).join("")}
      </div>
    `).join("");
  }

  function initStep3() {
    $("#saveBtn").addEventListener("click", async () => {
      const btn = $("#saveBtn");
      btn.disabled = true;
      btn.textContent = "⏳ กำลังบันทึก...";
      const saved = await saveDocumentRecord();
      if (saved) {
        btn.disabled = false;
        btn.textContent = "✅ บันทึกแล้ว";
        $("#savedMsg").style.display = "block";
        $("#savedDetail").textContent = `Template ID: ${uid()} · ${new Date().toLocaleString("th-TH")}`;
      } else { btn.disabled = false; btn.textContent = "💾 บันทึก Template"; }
    });

    $("#exportJsonFileBtn").addEventListener("click", () => {
      const data = buildExportData();
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = `signature-template-${state.config.templateName || "unnamed"}.json`;
      a.click();
      URL.revokeObjectURL(a.href);
    });

    $("#exportPdfBtn").addEventListener("click", async () => {
      const button=$('#exportPdfBtn'),status=$('#generateSavedPdfStatus');
      const layout=$('#layoutFrame')?.contentWindow;
      if(typeof layout?.generateSavedPdfForParent!=='function'){status.textContent='หน้าวางตำแหน่งยังไม่พร้อม กรุณาเปิดแถบ 3 ก่อน';return;}
      button.disabled=true;status.textContent='กำลังสร้าง PDF...';
      try{status.textContent=await layout.generateSavedPdfForParent();}
      catch(error){status.textContent=`สร้าง PDF ไม่สำเร็จ: ${error.message}`;}
      finally{button.disabled=false;}
    });
  }

  // ═══════════════════════════════════════════════════════════
  // JSON MEMORIES — บันทึก/โหลด Template config ผ่าน localStorage
  // (บันทึกไว้ในเบราว์เซอร์ของเครื่องนี้ ไม่ผ่าน server)
  // ═══════════════════════════════════════════════════════════
  const JSON_MEMORY_KEY = "nmu_signature_template_memories_v1";

  function buildExportData() {
    return {
      config: state.config,
      procurementDocuments: state.procurementDocuments,
      procurementCatalog: state.procurementCatalog,
      groups: state.groups,
      signatureBoxes: state.signatureBoxes,
      exportedAt: new Date().toISOString(),
    };
  }

  function loadJsonMemories() {
    try {
      const raw = localStorage.getItem(JSON_MEMORY_KEY);
      const list = raw ? JSON.parse(raw) : [];
      return Array.isArray(list) ? list : [];
    } catch {
      return [];
    }
  }

  function saveJsonMemories(list) {
    try {
      localStorage.setItem(JSON_MEMORY_KEY, JSON.stringify(list));
      return true;
    } catch (err) {
      // เช่น localStorage เต็ม หรือถูกปิดใช้งานโดยเบราว์เซอร์
      console.error("บันทึก Memories ไม่สำเร็จ:", err);
      return false;
    }
  }

  function setJsonMemoryHint(msg, kind /* 'ok' | 'err' | '' */ = "") {
    const el = $("#jsonMemoryHint");
    if (!el) return;
    el.textContent = msg || "";
    el.classList.remove("ok", "err");
    if (kind) el.classList.add(kind);
  }

  function renderJsonMemorySelect(selectId) {
    const sel = $("#jsonMemorySelect");
    const list = loadJsonMemories().sort((a, b) => (b.savedAt || "").localeCompare(a.savedAt || ""));
    sel.innerHTML = `<option value="">-- เลือก Template จาก Memories เพื่อใช้งาน --</option>` +
      list.map(item => {
        const dt = item.savedAt ? new Date(item.savedAt).toLocaleString("th-TH", { dateStyle: "short", timeStyle: "short" }) : "";
        return `<option value="${item.id}">${escapeHtml(item.name)} — ${dt} (${item.data.signatureBoxes.length} กล่อง)</option>`;
      }).join("");
    if (selectId) sel.value = selectId;
  }

  function saveCurrentToMemory() {
    const defaultName = state.config.templateName || `Template-${new Date().toLocaleDateString("th-TH")}`;
    const name = prompt("ตั้งชื่อ Template ที่จะบันทึกใน Memories:", defaultName);
    if (name === null) return; // ผู้ใช้กด cancel
    const trimmed = name.trim();
    if (!trimmed) {
      setJsonMemoryHint("กรุณาระบุชื่อ Template", "err");
      return;
    }

    const list = loadJsonMemories();
    const existing = list.find(item => item.name === trimmed);
    if (existing && !confirm(`มี Template ชื่อ "${trimmed}" อยู่แล้วใน Memories ต้องการเขียนทับหรือไม่?`)) {
      return;
    }

    const entry = {
      id: existing ? existing.id : uid(),
      name: trimmed,
      savedAt: new Date().toISOString(),
      data: buildExportData(),
    };

    const nextList = existing
      ? list.map(item => (item.id === existing.id ? entry : item))
      : [...list, entry];

    if (saveJsonMemories(nextList)) {
      renderJsonMemorySelect(entry.id);
      const layoutFrame = $("#layoutFrame");
      layoutFrame?.contentWindow?.postMessage({type:"EIS_DOCS_SAVE_LAYOUT_TEMPLATE", name:trimmed}, window.location.origin);
      setJsonMemoryHint(`✅ บันทึก "${trimmed}" ใน Memories และ Template ที่บันทึกไว้แล้ว`, "ok");
    } else {
      setJsonMemoryHint("⚠️ บันทึกไม่สำเร็จ — พื้นที่จัดเก็บของเบราว์เซอร์อาจเต็มหรือถูกปิดใช้งาน", "err");
    }
  }

  // sync ค่าใน step 0 (input/select/สถานะ) ให้ตรงกับ state.config หลังโหลดจาก memory
  function syncStep0FromConfig() {
    const c = state.config;
    $("#docType").value = c.docType || "";
    $("#prId").value = c.prId || "";
    $("#prSearch").value = c.prCode || "";
    syncManualPrMode(!c.prId && Boolean(c.prCode));
    if (c.prId) $("#prSelected").textContent = `${c.prCode} — ${c.prName || ""}`;
    $("#templateName").value = c.templateName || "";
    $("#description").value = c.description || "";
    $$(".status-btn").forEach(b => b.classList.toggle("active", b.dataset.status === c.status));
  }

  function loadMemoryIntoState(id) {
    const list = loadJsonMemories();
    const entry = list.find(item => item.id === id);
    if (!entry) return;

    // deep clone กันไม่ให้ mutate ข้อมูลใน localStorage โดยตรง
    const data = JSON.parse(JSON.stringify(entry.data));
    state.config = data.config || state.config;
    state.procurementDocuments = Array.isArray(data.procurementDocuments) ? data.procurementDocuments : [];
    state.procurementCatalog = Array.isArray(data.procurementCatalog) ? data.procurementCatalog : JSON.parse(JSON.stringify(PROCUREMENT_DOCUMENTS));
    state.groups = data.groups || [];
    state.signatureBoxes = data.signatureBoxes || [];
    state.selectedGroupId = state.groups[0]?.id || null;
    state.editingGroupId = null;

    // sync UI ทุกส่วนที่ผูกกับ state
    syncStep0FromConfig();
    renderProcurementDocuments();
    renderGroups();
    if ($("#boxOverlay")) {
      renderBoxOverlay();
      renderBoxList();
    }
    if (typeof renderGroupSelector === "function" && $("#groupSelector")) renderGroupSelector();
    renderPreview();

    const layoutFrame = $("#layoutFrame");
    layoutFrame?.contentWindow?.postMessage({type:"EIS_DOCS_LOAD_LAYOUT_TEMPLATE", name:entry.name}, window.location.origin);

    setJsonMemoryHint(`✅ โหลด "${entry.name}" พร้อมรูปแบบผังที่บันทึกไว้มาใช้งานแล้ว`, "ok");
  }

  function deleteSelectedMemory() {
    const sel = $("#jsonMemorySelect");
    const id = sel.value;
    if (!id) {
      setJsonMemoryHint("กรุณาเลือก Template ที่ต้องการลบก่อน", "err");
      return;
    }
    const list = loadJsonMemories();
    const entry = list.find(item => item.id === id);
    if (!entry) return;
    if (!confirm(`ลบ Template "${entry.name}" ออกจาก Memories?`)) return;

    saveJsonMemories(list.filter(item => item.id !== id));
    renderJsonMemorySelect();
    setJsonMemoryHint(`🗑️ ลบ "${entry.name}" แล้ว`, "ok");
  }

  function initJsonMemories() {
    renderJsonMemorySelect();

    $("#exportJsonMemoryBtn").addEventListener("click", saveCurrentToMemory);
    $("#jsonMemoryDeleteBtn").addEventListener("click", deleteSelectedMemory);

    // เลือกจาก combo แล้วโหลดมาใช้งานทันที
    $("#jsonMemorySelect").addEventListener("change", (e) => {
      const id = e.target.value;
      if (id) loadMemoryIntoState(id);
      else setJsonMemoryHint("");
    });
  }

  // ═══════════════════════════════════════════════════════════
  // PDF EXPORT — POST JSON to PDFBox (Java) backend with progress UI
  // ═══════════════════════════════════════════════════════════
  let activeExportXhr = null;

  function setPdfStage(stageName, status /* 'active' | 'done' | 'error' */) {
    const li = $(`#pdfStageList li[data-stage="${stageName}"]`);
    if (!li) return;
    if (status === "active") {
      li.classList.add("active");
      li.classList.remove("done", "error");
    } else if (status === "done") {
      li.classList.remove("active", "error");
      li.classList.add("done");
    } else if (status === "error") {
      li.classList.remove("active", "done");
      li.classList.add("error");
    }
  }

  function setPdfProgress(pct, label, mode /* 'normal' | 'indeterminate' | 'error' | 'success' */ = "normal") {
    const fill = $("#pdfProgressFill");
    fill.classList.remove("indeterminate", "error", "success");
    if (mode === "indeterminate") {
      fill.classList.add("indeterminate");
      $("#pdfProgressPct").textContent = "";
    } else {
      fill.style.width = `${Math.max(0, Math.min(100, pct))}%`;
      $("#pdfProgressPct").textContent = `${Math.round(pct)}%`;
      if (mode === "error") fill.classList.add("error");
      if (mode === "success") fill.classList.add("success");
    }
    if (label) $("#pdfProgressStatus").textContent = label;
  }

  function resetPdfModal() {
    $$("#pdfStageList li").forEach(li => li.classList.remove("active", "done", "error"));
    setPdfProgress(0, "กำลังเตรียมข้อมูล...", "normal");
    $("#pdfModalIcon").textContent = "📄";
    $("#pdfModalTitle").textContent = "กำลังสร้างไฟล์ PDF";
    $("#pdfModalSub").textContent = "โปรดรอสักครู่ ระบบกำลังประมวลผลบน Server";
    $("#pdfModalClose").style.display = "none";
    $("#pdfRetryBtn").style.display = "none";
    $("#pdfCancelBtn").style.display = "inline-flex";
    $("#pdfCancelBtn").textContent = "ยกเลิก";
  }

  function openPdfModal() {
    resetPdfModal();
    $("#pdfExportModal").style.display = "flex";
  }

  function closePdfModal() {
    $("#pdfExportModal").style.display = "none";
  }

  function showPdfError(message) {
    setPdfProgress(100, message || "เกิดข้อผิดพลาด", "error");
    $("#pdfModalIcon").textContent = "⚠️";
    $("#pdfModalTitle").textContent = "สร้างไฟล์ PDF ไม่สำเร็จ";
    $("#pdfModalSub").textContent = message || "กรุณาลองใหม่อีกครั้ง";
    $("#pdfModalClose").style.display = "inline-flex";
    $("#pdfCancelBtn").style.display = "none";
    $("#pdfRetryBtn").style.display = "inline-flex";
  }

  function showPdfSuccess(filename) {
    setPdfProgress(100, "เสร็จสิ้น", "success");
    $("#pdfModalIcon").textContent = "✅";
    $("#pdfModalTitle").textContent = "สร้างไฟล์ PDF สำเร็จ";
    $("#pdfModalSub").textContent = filename ? `ดาวน์โหลดไฟล์ ${filename} แล้ว` : "ดาวน์โหลดไฟล์เรียบร้อยแล้ว";
    $("#pdfModalClose").style.display = "inline-flex";
    $("#pdfCancelBtn").style.display = "none";
    $("#pdfRetryBtn").style.display = "none";
  }

  function exportPdfToServer() {
    if (state.signatureBoxes.length === 0) {
      alert("กรุณาวางกล่องลายเซ็นอย่างน้อย 1 ตำแหน่งก่อน Export PDF");
      return;
    }

    // Payload ส่งให้ backend (Spring Boot) เพื่อใช้ PDFBox วางตำแหน่ง signature field ลงบน PDF ต้นฉบับ
    const payload = {
      config: state.config,
      procurementDocuments: state.procurementDocuments,
      procurementCatalog: state.procurementCatalog,
      groups: state.groups,
      signatureBoxes: state.signatureBoxes,
      exportedAt: new Date().toISOString(),
    };
    const jsonBody = JSON.stringify(payload);
    const filename = `${state.config.templateName || "signature-template"}.pdf`;

    openPdfModal();
    setPdfStage("prepare", "active");
    setPdfProgress(4, "กำลังเตรียมข้อมูล JSON...");

    setTimeout(() => {
      setPdfStage("prepare", "done");
      sendExportRequest(jsonBody, filename);
    }, 250);
  }

  function sendExportRequest(jsonBody, filename) {
    const xhr = new XMLHttpRequest();
    activeExportXhr = xhr;

    xhr.open(PDF_EXPORT_CONFIG.method, PDF_EXPORT_CONFIG.url, true);
    xhr.responseType = "blob";
    xhr.timeout = PDF_EXPORT_CONFIG.timeoutMs;
    xhr.setRequestHeader("Content-Type", "application/json");
    xhr.setRequestHeader("Accept", "application/pdf, application/json");

    let uploadDone = false;

    // ── Upload phase (0–35%): ส่ง JSON config/signatureBoxes ไปยัง server ──
    xhr.upload.onprogress = (e) => {
      setPdfStage("upload", "active");
      const pct = e.lengthComputable ? (e.loaded / e.total) * 35 : 20;
      setPdfProgress(pct, "กำลังส่งข้อมูลไปยัง Server...");
    };
    xhr.upload.onload = () => {
      uploadDone = true;
      setPdfStage("upload", "done");
      setPdfStage("process", "active");
      // ช่วงนี้ server กำลังรัน PDFBox (วาง signature field, วาด box ต่างๆ) — ไม่มี progress ที่แน่นอน
      setPdfProgress(0, "กำลังประมวลผล PDF ด้วย PDFBox (Java)...", "indeterminate");
    };

    // ── Download phase (server กำลังส่ง PDF กลับมา) ──
    xhr.onprogress = (e) => {
      if (!uploadDone) return; // ยังอยู่ช่วง upload ใหญ่ (กรณี browser รวม event)
      setPdfStage("process", "done");
      setPdfStage("download", "active");
      if (e.lengthComputable) {
        const pct = 65 + (e.loaded / e.total) * 30; // 65–95%
        setPdfProgress(pct, "กำลังดาวน์โหลดไฟล์ PDF...");
      } else {
        setPdfProgress(0, "กำลังดาวน์โหลดไฟล์ PDF...", "indeterminate");
      }
    };

    xhr.onload = () => {
      activeExportXhr = null;
      if (xhr.status >= 200 && xhr.status < 300) {
        setPdfStage("download", "done");
        setPdfProgress(98, "กำลังบันทึกไฟล์ลงเครื่อง...");

        const blob = xhr.response instanceof Blob
          ? xhr.response
          : new Blob([xhr.response], { type: "application/pdf" });

        // ดึงชื่อไฟล์จาก header ถ้า server ระบุมา (Content-Disposition)
        let finalName = filename;
        const disposition = xhr.getResponseHeader("Content-Disposition");
        if (disposition) {
          const match = /filename\*?=(?:UTF-8'')?"?([^;"]+)"?/i.exec(disposition);
          if (match && match[1]) finalName = decodeURIComponent(match[1]);
        }

        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = finalName;
        a.click();
        URL.revokeObjectURL(a.href);

        showPdfSuccess(finalName);
      } else {
        handleExportFailure(xhr);
      }
    };

    xhr.onerror = () => {
      activeExportXhr = null;
      setPdfStage("upload", uploadDone ? "done" : "error");
      setPdfStage("process", "error");
      showPdfError("เชื่อมต่อ Server ไม่สำเร็จ กรุณาตรวจสอบเครือข่ายหรือสถานะ Server");
    };

    xhr.ontimeout = () => {
      activeExportXhr = null;
      setPdfStage("process", "error");
      showPdfError("การประมวลผลใช้เวลานานเกินกำหนด (timeout) กรุณาลองใหม่อีกครั้ง");
    };

    xhr.onabort = () => {
      activeExportXhr = null;
      closePdfModal();
    };

    xhr.send(jsonBody);

    // เก็บ context ไว้ retry
    sendExportRequest._lastArgs = [jsonBody, filename];
  }

  function handleExportFailure(xhr) {
    setPdfStage("process", "error");
    setPdfStage("download", "error");

    // พยายามอ่าน error message จาก server (ถ้าตอบกลับเป็น JSON error แทน PDF)
    if (xhr.response instanceof Blob && xhr.response.type.indexOf("json") !== -1) {
      const reader = new FileReader();
      reader.onload = () => {
        try {
          const err = JSON.parse(reader.result);
          showPdfError(err.message || `Server ตอบกลับผิดพลาด (HTTP ${xhr.status})`);
        } catch {
          showPdfError(`Server ตอบกลับผิดพลาด (HTTP ${xhr.status})`);
        }
      };
      reader.readAsText(xhr.response);
    } else {
      showPdfError(`Server ตอบกลับผิดพลาด (HTTP ${xhr.status})`);
    }
  }

  function initPdfExportModal() {
    $("#pdfCancelBtn").addEventListener("click", () => {
      if (activeExportXhr) activeExportXhr.abort();
      else closePdfModal();
    });
    $("#pdfModalClose").addEventListener("click", closePdfModal);
    $("#pdfRetryBtn").addEventListener("click", () => {
      exportPdfToServer();
    });
    // ปิด modal เมื่อคลิกพื้นหลัง หลังจากเสร็จ/error แล้วเท่านั้น (ไม่ปิดระหว่างกำลังประมวลผล)
    $("#pdfExportModal").addEventListener("click", (e) => {
      if (e.target.id === "pdfExportModal" && !activeExportXhr && $("#pdfModalClose").style.display !== "none") {
        closePdfModal();
      }
    });
  }

  // ═══════════════════════════════════════════════════════════
  // VIEW 2: DOCUMENT SIGNING LIST
  // ═══════════════════════════════════════════════════════════
  const selectedDocuments = new Set();
  function renderSelectedSet() {
    const list=$('#selectedSetDocuments');list.replaceChildren();
    const ids=Array.from(selectedDocuments);
    ids.forEach((id,index)=>{
      const doc=DOCS.find(d=>String(d.id)===id);const row=document.createElement('p');
      const label=document.createElement('span');label.textContent=`${index+1}. ${doc ? doc.no+' — '+doc.itemName : 'ไม่พบเอกสาร '+id} `;row.append(label);
      [['↑',-1],['↓',1],['นำออก',0]].forEach(([text,offset])=>{const button=document.createElement('button');button.type='button';button.textContent=text;button.className='row-action';button.disabled=offset!==0&&(index+offset<0||index+offset>=ids.length);button.onclick=()=>{if(offset){[ids[index],ids[index+offset]]=[ids[index+offset],ids[index]];selectedDocuments.clear();ids.forEach(x=>selectedDocuments.add(x));}else selectedDocuments.delete(id);renderDocList();};row.append(button);});list.append(row);
    });
    if(!ids.length)list.textContent='ยังไม่ได้เลือกเอกสาร';
  }
  async function loadDocumentSets() {
    const target=$('#savedDocumentSets');
    try {const response=await fetch('document-sets.php',{credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message);
      target.replaceChildren();
      result.sets.forEach(set=>{const details=document.createElement('details');details.style.cssText='padding:12px;border:1px solid #ddd;border-radius:8px;margin:8px 0';const summary=document.createElement('summary');summary.textContent=`${set.set_name} (${set.ids.length} เอกสาร) — ${set.created_at}`;details.append(summary);
        if(set.ap_code){const ap=document.createElement('p');ap.textContent=`ตรวจรับ ${set.ap_code} | ${set.ap_name||'ไม่ระบุชื่อ'} | สัญญา ${set.contract_code||'—'} | ${set.ap_amount==null?'ไม่ระบุยอด':Number(set.ap_amount).toLocaleString('th-TH',{minimumFractionDigits:2})} บาท`;details.append(ap);}
        set.ids.forEach((id,index)=>{const doc=DOCS.find(d=>String(d.id)===String(id));const p=document.createElement('p');p.textContent=`${index+1}. ${doc?doc.no+' — '+doc.itemName:'เอกสารถูกลบหรือไม่พบ #'+id} `;
          if(doc?.pdfDocId){const a=document.createElement('a');const url=new URL('../pdf/view',location.href);url.searchParams.set('docId',doc.pdfDocId);a.href=url.href;a.target='_blank';a.rel='noopener';a.textContent='เปิด PDF';p.append(a);}details.append(p);});target.append(details);});
      if(!result.sets.length)target.textContent='ยังไม่มีชุดเอกสาร';
    }catch(error){target.textContent=`โหลดชุดเอกสารไม่สำเร็จ: ${error.message}`;}
  }
  async function saveDocumentSet() {
    const button=$('#saveDocumentSet'),message=$('#documentSetMessage');
    const name=$('#documentSetName').value.trim();if(!name||!selectedDocuments.size){message.textContent='กรุณาระบุชื่อชุดและเลือกเอกสาร';return;}
    button.disabled=true;
    try {const response=await fetch('document-sets.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':wfCsrf},body:JSON.stringify({name,ids:Array.from(selectedDocuments,Number),ap_id:$('#selectedApId').value||null})});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message);selectedDocuments.clear();$('#documentSetName').value='';$('#selectedApId').value='';$('#apSearch').value='';$('#apSelectedInfo').textContent='ยังไม่ได้เลือกเลขตรวจรับ';$('#apSearchResults').replaceChildren();message.textContent=`บันทึกชุด ${name} แล้ว`;renderDocList();}
    catch(error){message.textContent=`บันทึกไม่สำเร็จ: ${error.message}`;}finally{button.disabled=false;}
  }

  function initDocList() {
    const head = document.querySelector('#view-list .doc-table thead tr');
    const cell = document.createElement('th'); cell.innerHTML='<input type="checkbox" id="selectAllDocuments" aria-label="เลือกเอกสารทั้งหมดที่แสดง">'; head.prepend(cell);
    cell.firstChild.onchange = event => {filteredDocs().forEach(doc => event.target.checked ? selectedDocuments.add(String(doc.id)) : selectedDocuments.delete(String(doc.id)));renderDocList();};
    $('#saveDocumentSet').onclick = saveDocumentSet;
    $$("#view-list .list-tab").forEach(tab => {
      tab.addEventListener("click", () => {
        state.listTab = tab.dataset.tab;
        $$("#view-list .list-tab").forEach(t => t.classList.toggle("active", t === tab));
        renderDocList();
      });
    });
    $('#closeSignedPdfTab').addEventListener('click', closeSignedPdfTab);
    $('#closeSignedPdfPanel').addEventListener('click', closeSignedPdfTab);
    $('#pendingSelectAll').addEventListener('change', event => {
      pendingDocumentsForLogin().filter(canBulkSign).forEach(doc => {
        if (event.target.checked) selectedPendingDocuments.add(String(doc.id));
        else selectedPendingDocuments.delete(String(doc.id));
      });
      renderPendingDocs();
    });
    $('#bulkSignBtn').addEventListener('click', openBulkSignaturePad);
    $("#docSearch").addEventListener("input", e => { state.search = e.target.value; renderDocList(); });
    $("#docTypeFilter").addEventListener("change", e => { state.typeFilter = e.target.value; renderDocList(); });
    $("#docRefreshBtn").addEventListener("click", loadDocuments);
    $("#docDetailClose").addEventListener("click", () => $("#docDetailDialog").close());
    $("#docDetailDialog").addEventListener("click", event => {
      if (event.target === $("#docDetailDialog")) $("#docDetailDialog").close();
    });
    $$(".pending-subtab").forEach(tab => tab.addEventListener("click", () => switchPendingTab(tab.dataset.pendingTab)));
    $("#pendingDocSearch").addEventListener("input", event => { state.pendingSearch = event.target.value; renderPendingDocs(); });
    $("#pendingDocTypeFilter").addEventListener("change", event => { state.pendingTypeFilter = event.target.value; renderPendingDocs(); });
    $("#pendingRefreshBtn").addEventListener("click", loadDocuments);
    $("#backToPendingQueue").addEventListener("click", async () => { await loadDocuments(); switchPendingTab("queue"); });
    $("#backToPendingQueueReview").addEventListener("click", async () => { await loadDocuments(); switchPendingTab("queue"); });
    loadDocuments();
  }

  async function loadDocuments() {
    docsLoading = true;
    docsError = "";
    renderDocList();
    try {
      const response = await fetch("documents.php", { headers: { Accept: "application/json" }, credentials: "same-origin" });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
      DOCS = (result.documents || []).map(row => ({
        id: row.docs_id, no: row.doc_no, type: row.doc_type, typeName: row.doc_type_name,
        dept: row.department, itemName: row.template_name || row.description || "-", requester: row.requester_name, date: row.doc_date,
        amount: Number(row.amount || 0), status: ({COMPLETED: "done", REJECTED: "rejected"})[String(row.status).toUpperCase()] || "pending",
        signers: Number(row.signer_count || 0), signed: Number(row.signed_count || 0),
        current: row.current_signer || "-", currentUserId: row.current_user_id || "",
        currentWfOrder: row.current_wf_order, currentReviewAction: Boolean(row.current_review_action), currentSignAction: Boolean(row.current_sign_action),
        pdfDocId: row.pdf_doc_id || row.file_path || "", description: row.description || "",
      }));
    } catch (error) {
      DOCS = [];
      docsError = error.message;
    } finally {
      docsLoading = false;
      renderDocList();
      renderPendingDocs();
    }
  }

  function filteredDocs() {
    return DOCS.filter(d => {
      if (state.listTab === "pending" && d.status !== "pending") return false;
      if (state.listTab === "done" && d.status !== "done") return false;
      if (state.typeFilter && d.type !== state.typeFilter) return false;
      if (state.search) {
        const q = state.search.trim().toLowerCase();
        const hay = `${d.no} ${d.itemName} ${d.requester}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }

  function initials(name) {
    const parts = name.replace(/^(นาย|นางสาว|นาง)/, "").trim();
    return parts.charAt(0) || "?";
  }

  function renderDocList() {
    const setsMode = state.listTab === 'sets';
    const signedPdfMode = state.listTab === 'signed-pdf';
    $('#documentSetPanel').hidden = !setsMode;
    $('#signedPdfPanel').hidden = !signedPdfMode;
    document.querySelector('#view-list .list-filter-row').style.display = (setsMode || signedPdfMode) ? 'none' : '';
    document.querySelector('#view-list .doc-table-wrap').style.display = (setsMode || signedPdfMode) ? 'none' : '';
    $('#documentSelectionCount').style.display = signedPdfMode ? 'none' : '';
    $('#documentSelectionCount').textContent = `เลือกแล้ว ${selectedDocuments.size} รายการ — ไปแถบ 2.3 เพื่อจัดชุดเอกสาร`;
    if (setsMode) { renderSelectedSet(); loadDocumentSets(); }
    $("#countAll").textContent = DOCS.length;
    $("#countPending").textContent = DOCS.filter(d => d.status === "pending").length;
    $("#countDone").textContent = DOCS.filter(d => d.status === "done").length;

    const rows = filteredDocs();
    const tbody = $("#docTableBody");
    const empty = $("#docEmptyMsg");
    empty.style.display = rows.length === 0 ? "block" : "none";
    empty.textContent = docsLoading ? "กำลังโหลดข้อมูลเอกสาร..." : (docsError ? `โหลดข้อมูลไม่สำเร็จ: ${docsError}` : "ไม่พบเอกสารที่ตรงกับเงื่อนไข");

    tbody.innerHTML = rows.map(d => {
      const pct = d.signers ? Math.round((d.signed / d.signers) * 100) : 0;
      const statusHtml = d.status === "pending"
        ? `<span class="status-chip pending">⏳ รอเซ็น</span>`
        : d.status === "rejected"
          ? `<span class="status-chip rejected">✕ ไม่ผ่าน</span>`
          : `<span class="status-chip done">✅ เซ็นเรียบร้อย</span>`;
      return `
        <tr>
          <td class="doc-no">${d.no}</td>
          <td><span class="doc-type-chip">${escapeHtml(d.typeName || DOC_TYPE_LABEL[d.type] || d.type)}</span></td>
          <td>${escapeHtml(d.itemName)}</td>
          <td>${escapeHtml(d.requester)}</td>
          <td>${formatThaiDate(d.date)}</td>
          <td>
            <div class="signer-cell">
              ${d.current !== "-" ? `<span class="signer-avatar">${initials(d.current)}</span><span>${escapeHtml(d.current)}</span>` : `<span style="color:var(--text-light)">—</span>`}
            </div>
          </td>
          <td>
            <div class="progress-cell">
              <div class="progress-bar-track"><div class="progress-bar-fill ${pct === 100 ? "full" : ""}" style="width:${pct}%"></div></div>
              <span class="progress-label">${d.signed}/${d.signers}</span>
            </div>
          </td>
          <td>฿${d.amount.toLocaleString("th-TH")}</td>
          <td>${statusHtml}</td>
          <td><div class="doc-row-actions"><button class="row-action js-doc-detail" data-id="${d.id}">ดูรายละเอียด</button>${d.status === 'done' && d.pdfDocId ? `<button class="row-action js-view-signed-pdf" data-id="${d.id}">ดู PDF ที่ลงนาม</button>` : ''}</div></td>
        </tr>`;
    }).join("");
    $$(".js-doc-detail", tbody).forEach(button => button.addEventListener("click", () => openDocumentDetail(button.dataset.id)));
    $$(".js-view-signed-pdf", tbody).forEach(button => button.addEventListener("click", () => {
      const doc = DOCS.find(item => String(item.id) === String(button.dataset.id));
      if (doc) openSignedPdf(doc);
    }));
    Array.from(tbody.rows).forEach((row,index) => {
      const doc=rows[index]; const cell=row.insertCell(0);const input=document.createElement('input');input.type='checkbox';input.checked=selectedDocuments.has(String(doc.id));input.setAttribute('aria-label',`เลือก ${doc.no} ${doc.itemName}`);
      input.onchange=()=>{input.checked?selectedDocuments.add(String(doc.id)):selectedDocuments.delete(String(doc.id));renderDocList();};cell.append(input);
    });
    const all=$('#selectAllDocuments');if(all){const n=rows.filter(doc=>selectedDocuments.has(String(doc.id))).length;all.checked=rows.length>0&&n===rows.length;all.indeterminate=n>0&&n<rows.length;all.disabled=docsLoading||!rows.length;}
  }

  function openSignedPdf(doc) {
    if (!doc.pdfDocId) return;
    const url = new URL('../pdf/view', location.href);
    url.searchParams.set('docId', doc.pdfDocId);
    $('#signedPdfFrame').src = url.href;
    $('#signedPdfTitle').textContent = `${doc.no} — PDF ที่ลงนาม`;
    $('#signedPdfTab').hidden = false;
    state.listTab = 'signed-pdf';
    $$('#view-list .list-tab').forEach(tab => tab.classList.toggle('active', tab.dataset.tab === 'signed-pdf'));
    renderDocList();
  }

  function closeSignedPdfTab() {
    $('#signedPdfFrame').src = 'about:blank';
    $('#signedPdfTab').hidden = true;
    state.listTab = 'all';
    $$('#view-list .list-tab').forEach(tab => tab.classList.toggle('active', tab.dataset.tab === 'all'));
    renderDocList();
  }

  function switchPendingTab(tabName) {
    if (tabName === "review" && $("#pendingReviewTab").disabled) return;
    if (tabName === "sign" && $("#pendingSignTab").disabled) return;
    state.pendingTab = tabName;
    $$(".pending-subtab").forEach(tab => tab.classList.toggle("active", tab.dataset.pendingTab === tabName));
    $$(".pending-panel").forEach(panel => panel.classList.toggle("active", panel.id === `pending-panel-${tabName}`));
  }

  function pendingDocumentsForLogin() {
    const query = state.pendingSearch.trim().toLowerCase();
    return DOCS.filter(doc => (doc.status === "pending" || doc.status === "rejected") && String(doc.currentUserId).trim() === currentLoginUserId)
      .filter(doc => !state.pendingTypeFilter || doc.type === state.pendingTypeFilter)
      .filter(doc => !query || `${doc.no} ${doc.itemName} ${doc.requester} ${doc.current}`.toLowerCase().includes(query));
  }

  function renderPendingDocs() {
    const tbody = $("#pendingDocTableBody");
    if (!tbody) return;
    const rows = pendingDocumentsForLogin();
    $("#countPending").textContent = rows.length;
    const empty = $("#pendingDocEmptyMsg");
    empty.style.display = rows.length ? "none" : "block";
    empty.textContent = docsLoading ? "กำลังโหลดเอกสารที่รอเซ็น..." : (docsError ? `โหลดข้อมูลไม่สำเร็จ: ${docsError}` : "ไม่มีเอกสารที่รอให้บัญชีนี้ดำเนินการ");
    const signableRows = rows.filter(canBulkSign);
    for (const id of [...selectedPendingDocuments]) {
      if (!signableRows.some(doc => String(doc.id) === id)) selectedPendingDocuments.delete(id);
    }
    $('#pendingSelectionCount').textContent = `เลือกเอกสารเพื่อลงนาม ${selectedPendingDocuments.size} รายการ`;
    $('#bulkSignBtn').disabled = selectedPendingDocuments.size === 0;
    const selectAll = $('#pendingSelectAll');
    const selectedSignable = signableRows.filter(doc => selectedPendingDocuments.has(String(doc.id))).length;
    selectAll.checked = signableRows.length > 0 && selectedSignable === signableRows.length;
    selectAll.indeterminate = selectedSignable > 0 && selectedSignable < signableRows.length;
    selectAll.disabled = docsLoading || signableRows.length === 0;
    tbody.innerHTML = rows.map(doc => {
      const pct = doc.signers ? Math.round(doc.signed / doc.signers * 100) : 0;
      const isRevision = doc.status === "rejected" && Number(doc.currentWfOrder) === 1;
      const statusHtml = isRevision
        ? '<span class="status-chip rejected">✕ ทักท้วง — รอแก้ไข</span>'
        : '<span class="status-chip pending">⏳ รอเซ็น</span>';
      const actionButton = isRevision
        ? `<button class="row-action sign-now js-edit-revision" data-id="${doc.id}">แก้ไขเอกสาร</button>`
        : doc.currentReviewAction && !doc.currentSignAction
          ? `<button class="row-action sign-now js-open-sign" data-id="${doc.id}">ตรวจสอบ</button>`
          : doc.currentSignAction
            ? `<button class="row-action sign-now js-open-sign" data-id="${doc.id}">ลงนาม</button>`
            : '';
      return `<tr>
        <td><input class="js-pending-select" type="checkbox" data-id="${doc.id}" aria-label="เลือก ${escapeHtml(doc.no)}" ${canBulkSign(doc) ? '' : 'disabled'} ${selectedPendingDocuments.has(String(doc.id)) ? 'checked' : ''}></td>
        <td class="doc-no">${escapeHtml(doc.no)}</td><td><span class="doc-type-chip">${escapeHtml(doc.typeName || doc.type)}</span></td>
        <td>${escapeHtml(doc.itemName)}</td><td>${escapeHtml(doc.requester)}</td><td>${formatThaiDate(doc.date)}</td>
        <td><div class="signer-cell"><span class="signer-avatar">${initials(doc.current)}</span><span>${escapeHtml(doc.current)}</span></div></td>
        <td><div class="progress-cell"><div class="progress-bar-track"><div class="progress-bar-fill" style="width:${pct}%"></div></div><span class="progress-label">${doc.signed}/${doc.signers}</span></div></td>
        <td>฿${doc.amount.toLocaleString("th-TH")}</td><td>${statusHtml}</td>
        <td><div class="doc-row-actions"><button class="row-action js-pending-detail" data-id="${doc.id}">ดูรายละเอียด</button>${actionButton}</div></td></tr>`;
    }).join("");
    $$(".js-open-sign", tbody).forEach(button => button.addEventListener("click", () => {
      const doc = DOCS.find(item => String(item.id) === String(button.dataset.id));
      if (doc) openSignaturePad(doc);
    }));
    $$('.js-pending-select', tbody).forEach(input => input.addEventListener('change', () => {
      if (input.checked) selectedPendingDocuments.add(String(input.dataset.id));
      else selectedPendingDocuments.delete(String(input.dataset.id));
      renderPendingDocs();
    }));
    $$(".js-edit-revision", tbody).forEach(button => button.addEventListener("click", () => openRevisionEditor(button.dataset.id)));
    $$(".js-pending-detail", tbody).forEach(button => button.addEventListener("click", () => openDocumentDetail(button.dataset.id)));
  }

  function canBulkSign(doc) {
    return doc.status === 'pending' && doc.currentSignAction && Boolean(doc.pdfDocId)
      && String(doc.currentUserId).trim() === currentLoginUserId;
  }

  function openBulkSignaturePad() {
    const documents = pendingDocumentsForLogin().filter(doc => selectedPendingDocuments.has(String(doc.id)) && canBulkSign(doc));
    if (!documents.length) return;
    const params = new URLSearchParams({
      bulk_docs: JSON.stringify(documents.map(doc => ({ docsId: String(doc.id), docId: String(doc.pdfDocId), no: doc.no }))),
      docId: String(documents[0].pdfDocId), user_id: currentLoginUserId, name: documents[0].current,
      csrf: wfCsrf, view: 'sign', v: '20260911-2',
    });
    $('#signaturePadFrame').src = `signature-pad.html?${params}`;
    $('#activeSigningTitle').textContent = `ลงนาม ${documents.length} รายการด้วยลายเซ็นเดียว`;
    $('#reviewPadFrame').src = 'about:blank';
    $('#pendingReviewTab').disabled = true;
    $('#pendingSignTab').disabled = false;
    switchPendingTab('sign');
  }

  let revisionFeedbackRequest = 0;
  async function loadRevisionFeedback(docsId) {
    const request = ++revisionFeedbackRequest;
    document.getElementById('revisionFeedback')?.remove();
    const panel = document.createElement('section');
    panel.id = 'revisionFeedback';
    panel.style.cssText = 'margin:20px;padding:20px;border:1px solid #efb4ac;border-radius:10px;background:#fff7f5';
    panel.textContent = 'กำลังโหลดข้อทักท้วงและหน้าที่ต้องแก้ไข...';
    document.getElementById('view-create').prepend(panel);
    try {
      const response = await fetch(`documents.php?id=${encodeURIComponent(docsId)}`, {credentials:'same-origin',cache:'no-store'});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'โหลดข้อทักท้วงไม่สำเร็จ');
      if (request !== revisionFeedbackRequest || String(state.config.docsId) !== String(docsId)) { panel.remove(); return; }
      const doc = result.document;
      const rejected = (doc.route || []).filter(row => row.decision === 'REJECT');
      if (!rejected.length) {panel.remove(); return;}
      panel.innerHTML = '<h3>ข้อทักท้วง — หน้าที่ WF1 ต้องแก้ไข</h3>';
      rejected.forEach(row => {
        const heading = document.createElement('p');
        heading.textContent = `WF ${row.wf_order} — ${row.signer_name}: ${row.rejection_reason || 'ไม่ผ่าน'}`;
        panel.append(heading);
        const marks = Array.isArray(row.review_marks) ? row.review_marks : [];
        if (!marks.length) {const note=document.createElement('p');note.textContent='ผู้ทักท้วงไม่ได้แนบเลขหน้าหรือรูป กรุณาดูเหตุผลด้านบน';panel.append(note);}
        marks.forEach(mark => {
          const card = document.createElement('div'); card.style.cssText='padding:12px;margin:10px 0;background:white;border:1px solid #ddd;border-radius:8px';
          const title = document.createElement('strong'); title.textContent=`หน้า ${mark.page} ${mark.bookmark || ''}`;
          const description=document.createElement('p');description.textContent=mark.description;
          card.append(title,description);
          if (doc.pdf_doc_id && Number.isInteger(Number(mark.page)) && Number(mark.page)>0) {
            const link=document.createElement('a');const url=new URL('../pdf/view',location.href);url.searchParams.set('docId',doc.pdf_doc_id);url.hash=`page=${Number(mark.page)}`;
            link.href=url.href;link.target='_blank';link.rel='noopener';link.textContent='เปิด PDF หน้านี้';card.append(link);
          }
          if (typeof mark.image==='string' && /^data:image\/jpeg;base64,[A-Za-z0-9+/=]+$/.test(mark.image)) {
            const details=document.createElement('details'); details.open=true;
            const summary=document.createElement('summary');summary.textContent='รูปจุดที่ต้องแก้ไข';
            const img=document.createElement('img');img.src=mark.image;img.alt=`จุดที่ต้องแก้ไข หน้า ${mark.page}`;img.style.cssText='width:100%;max-width:800px;height:auto';
            details.append(summary,img);card.append(details);
          }
          panel.append(card);
        });
      });
    } catch(error) {panel.textContent=`โหลดข้อทักท้วงไม่สำเร็จ: ${error.message}`;}
  }

  async function openRevisionEditor(docsId) {
    state.view = "create";
    $$(".menu-item").forEach(button => button.classList.toggle("active", button.dataset.view === "create"));
    $$(".app-view").forEach(view => view.classList.toggle("active", view.id === "view-create"));
    goToStep(0);
    await loadSavedDocumentOptions(docsId);
    await loadDocumentRecord(docsId);
    setCrudMessage("เอกสารถูกทักท้วง: กรุณาแก้ไขและกดบันทึก ระบบจะล้างความคืบหน้าเดิม จากนั้นสร้าง PDF ใหม่เพื่อเริ่มที่ WF1", "err");
    window.scrollTo({top:0, behavior:"smooth"});
  }

  function openSignaturePad(doc) {
    if (!doc.pdfDocId || String(doc.currentUserId).trim() !== currentLoginUserId || (!doc.currentReviewAction && !doc.currentSignAction)) return;
    const baseParams = {docs_id:String(doc.id), docId:doc.pdfDocId, user_id:currentLoginUserId, name:doc.current, csrf:wfCsrf, v:'20260911-2'};
    const reviewParams = new URLSearchParams({...baseParams, view:'review'});
    const signParams = new URLSearchParams({...baseParams, view:'sign'});
    $("#reviewPadFrame").src = doc.currentReviewAction ? `signature-pad.html?${reviewParams}` : "about:blank";
    $("#signaturePadFrame").src = `signature-pad.html?${signParams}`;
    $("#activeReviewTitle").textContent = `${doc.no} — ${doc.current}`;
    $("#activeSigningTitle").textContent = `${doc.no} — ${doc.current}`;
    $("#pendingSignTab").disabled = false;
    $("#pendingReviewTab").disabled = !doc.currentReviewAction;
    switchPendingTab("sign");
  }

  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.data?.type !== 'EIS_DOCS_REVIEW_MARKS') return;
    const review = document.getElementById('reviewPadFrame');
    const sign = document.getElementById('signaturePadFrame');
    if (event.source !== review?.contentWindow && event.source !== sign?.contentWindow) return;
    const target = event.source === review?.contentWindow ? sign : review;
    target?.contentWindow?.postMessage(event.data,location.origin);
  });

  async function openDocumentDetail(id) {
    const dialog = $("#docDetailDialog");
    $("#docDetailTitle").textContent = "รายละเอียดเอกสาร";
    $("#docDetailSubtitle").textContent = "กำลังโหลดข้อมูล...";
    $("#docDetailBody").innerHTML = '<p class="empty-msg">กำลังโหลดเส้นทาง Workflow...</p>';
    dialog.showModal();
    try {
      const response = await fetch(`documents.php?id=${encodeURIComponent(id)}`, { credentials: "same-origin" });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.success) throw new Error(result.message || `HTTP ${response.status}`);
      const doc = result.document;
      $("#docDetailTitle").textContent = doc.doc_no;
      $("#docDetailSubtitle").textContent = `${doc.doc_type_name} • ${doc.department}`;
      const route = Array.isArray(doc.route) ? doc.route : [];
      $("#docDetailBody").innerHTML = `
        <div class="doc-summary-grid">
          <div><small>ผู้ขอซื้อ</small><strong>${escapeHtml(doc.requester_name)}</strong></div>
          <div><small>วันที่เอกสาร</small><strong>${formatThaiDate(doc.doc_date)}</strong></div>
          <div><small>มูลค่า</small><strong>฿${Number(doc.amount || 0).toLocaleString("th-TH")}</strong></div>
          <div><small>รายละเอียด</small><strong>${escapeHtml(doc.description || "—")}</strong></div>
        </div>
        <h3 class="route-title">เส้นทางการลงนาม</h3>
        <ol class="doc-route-list">${route.map(item => {
          const actions = [item.review_action && "ตรวจสอบ", item.sign_action && "ลงนาม", item.approve_action && "อนุมัติ"].filter(Boolean).join(" • ");
          const done = item.sign_status === "DONE";
          const rejectReason = item.decision === "REJECT" && item.rejection_reason
            ? `<small class="route-reject-reason">เหตุผลที่ไม่ผ่าน: ${escapeHtml(item.rejection_reason)}</small>` : "";
          const reviewNotes = (Array.isArray(item.review_marks) ? item.review_marks : []).map(mark => `<small class="route-reject-reason">หน้า ${escapeHtml(mark.page)} ${escapeHtml(mark.bookmark || "")} : ${escapeHtml(mark.description)}</small>${typeof mark.image === "string" && /^data:image\/jpeg;base64,[A-Za-z0-9+/=]+$/.test(mark.image) ? `<details><summary>ดูรูปจุดที่ต้องแก้ไข หน้า ${escapeHtml(mark.page)}</summary><img src="${escapeHtml(mark.image)}" alt="จุดที่ต้องแก้ไข" style="width:100%;max-width:800px"></details>` : ""}`).join("");
          const routeStatus = item.decision === "REJECT" ? "✕ ไม่ผ่าน — ส่งกลับ WF1" : (done ? "✓ ดำเนินการแล้ว" : "รอดำเนินการ");
          return `<li class="${item.decision === "REJECT" ? "rejected" : (done ? "done" : "pending")}"><span class="route-order">WF ${item.wf_order}</span><div><strong>${escapeHtml(item.signer_name)}</strong><small>user_id: ${escapeHtml(item.user_id)} • ${actions}</small><small>วันที่ทำรายการ: ${formatThaiDateTime(item.signed_at)}</small>${rejectReason}${reviewNotes}</div><span class="route-status">${routeStatus}</span></li>`;
        }).join("")}</ol>`;
    } catch (error) {
      $("#docDetailBody").innerHTML = `<p class="empty-msg">โหลดรายละเอียดไม่สำเร็จ: ${escapeHtml(error.message)}</p>`;
    }
  }

  function formatThaiDate(iso) {
    const d = new Date(iso);
    const months = ["ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear() + 543}`;
  }

  function formatThaiDateTime(value) {
    if (!value) return "ยังไม่ดำเนินการ";
    const d = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(d.getTime())) return "ไม่ระบุ";
    const months = ["ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear() + 543} ${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")} น.`;
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>"']/g, m => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[m]));
  }

  // ═══════════════════════════════════════════════════════════
  // INIT
  // ═══════════════════════════════════════════════════════════
  document.addEventListener("DOMContentLoaded", () => {
    window.addEventListener("message", event => {
      if (event.origin === window.location.origin && event.data?.type === "EIS_DOCS_PDF_CREATED") registerGeneratedPdf(event.data);
      if (event.origin === window.location.origin && event.data?.type === "EIS_DOCS_LAYOUT_TEMPLATE_SAVED") {
        setJsonMemoryHint(`✅ บันทึก "${event.data.name}" ใน Memories และ Template ที่บันทึกไว้แล้ว (${Number(event.data.fieldCount || 0)} บล็อก)`, "ok");
      }
      if (event.origin === window.location.origin && event.data?.type === "EIS_DOCS_WORKFLOW_COMPLETED") {
        const rejected = String(event.data.decision || "").toUpperCase() === "REJECT";
        if (rejected) {
          // ไม่ผ่าน — ส่งกลับ WF1 แล้ว: ปิดหน้าเซ็นเอกสารก่อน แล้วบังคับ reload หน้าทั้งหมด
          // เพื่อให้แน่ใจว่ารายการรอเซ็นเป็นข้อมูลล่าสุดจากเซิร์ฟเวอร์เสมอ (ไม่ต้องกด Ctrl+F5 เอง)
          $("#signaturePadFrame").src = "about:blank";
          $("#reviewPadFrame").src = "about:blank";
          $("#activeSigningTitle").textContent = "ยังไม่ได้เลือกเอกสาร";
          $("#activeReviewTitle").textContent = "ยังไม่ได้เลือกเอกสาร";
          $("#pendingSignTab").disabled = true;
          $("#pendingReviewTab").disabled = true;
          window.location.reload();
          return;
        }
        loadDocuments().then(() => {
          switchPendingTab("queue");
          renderPendingDocs();
        });
      }
    });
    initMainMenu();
    initResponsiveSidebar();
    initUserMenu();
    initNotifications();
    initWizardNav();
    initStep0();
    initStep1();
    initStep3();
    initSignDateOption();
    initJsonMemories();
    initPdfExportModal();
    initDocList();
    goToStep(0);
    renderGroups();
  });
})();

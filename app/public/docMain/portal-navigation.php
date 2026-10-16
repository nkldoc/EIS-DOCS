<?php
// Only known deployment hosts determine the return destination.
$navigationHost = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$portalNavigationBase = in_array($navigationHost, ['doc-eis.nmu.ac.th','eis.nmu.ac.th'], true) ? 'https://eis.nmu.ac.th' : '';
?>
<link rel="stylesheet" href="portal-navigation.css?v=20261012-back">
<nav class="portal-navigation" aria-label="กลับหน้าเลือกระบบ">
  <a class="portal-navigation-back" href="<?=htmlspecialchars($portalNavigationBase, ENT_QUOTES, 'UTF-8')?>/NMU_permission/entrance"><span aria-hidden="true">←</span> กลับหน้าเลือกระบบ</a>
  <span class="portal-navigation-context">EIS <span aria-hidden="true">/</span> <?=htmlspecialchars($portalNavigationContext ?? 'เครื่องมือผู้ดูแลระบบ', ENT_QUOTES, 'UTF-8')?></span>
</nav>

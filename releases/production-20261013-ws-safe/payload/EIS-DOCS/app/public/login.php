<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

function docsLoginDestination(): string
{
    $target = $_SESSION['docs_login_return'] ?? '';
    unset($_SESSION['docs_login_return']);
    return appUrl(in_array($target, ['docMain/message-inbox.php','docMain/user-online.php','docMain/message-report.php'], true) ? $target : '');
}

if (Auth::check()) {
    header('Location: ' . docsLoginDestination());
    exit;
}

$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['login_attempts'] ??= [];
$error = '';
if (!empty($_SESSION['login_replaced'])) $error = 'เซสชันนี้ถูกแทนที่จากการเข้าสู่ระบบใหม่ กรุณาเข้าสู่ระบบอีกครั้ง';
if (isset($_SESSION['docs_login_pending']) && $_SESSION['docs_login_pending']['expires'] < time()) unset($_SESSION['docs_login_pending']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $now = time();
    $_SESSION['login_attempts'] = array_values(array_filter(
        $_SESSION['login_attempts'],
        static fn (int $attempt): bool => $attempt > $now - 300
    ));

    $csrf = (string) ($_POST['csrf'] ?? '');
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!safeCmp($_SESSION['login_csrf'], $csrf)) {
        $error = 'คำขอหมดอายุ กรุณาลองใหม่';
    } elseif (count($_SESSION['login_attempts']) >= 5) {
        $error = 'ลองเข้าสู่ระบบหลายครั้งเกินไป กรุณารอ 5 นาที';
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        unset($_SESSION['docs_login_pending']);
        $error = 'ยกเลิกการเข้าสู่ระบบแล้ว เซสชันเดิมยังใช้งานได้';
    } elseif (($_POST['action'] ?? '') === 'replace') {
        try { Auth::confirmDuplicate((string)($_POST['confirmation'] ?? ''), (string)($_POST['admin_operator'] ?? '')); unset($_SESSION['login_csrf'], $_SESSION['login_attempts']); header('Location: ' . docsLoginDestination()); exit; }
        catch (RuntimeException $exception) { $error = $exception->getMessage(); }
    } elseif ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        try {
            if (Auth::attempt($username, $password)) {
                unset($_SESSION['login_csrf'], $_SESSION['login_attempts']);
                header('Location: ' . docsLoginDestination());
                exit;
            }
            $_SESSION['login_attempts'][] = $now;
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log(sprintf('Login failed: %s in %s:%d', $exception->getMessage(), $exception->getFile(), $exception->getLine()));
            $error = 'ระบบไม่สามารถเข้าสู่ระบบได้ กรุณาติดต่อผู้ดูแล';
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ | EIS-DOCS</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="login-page">
    <main class="login-card">
        <div class="brand-mark" style="background:transparent;padding:0"><img src="<?= htmlspecialchars(appUrl('docMain/eis-docs-logo.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:100%;height:100%;display:block"></div>
        <h1>EIS-DOCS</h1>
        <p class="login-subtitle">ระบบสนับสนุนเอกสารและลงนาม</p>

        <?php if ($error !== ''): ?>
            <div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['docs_login_pending'])): ?>
        <?php $namedAdmin = !empty($_SESSION['docs_login_pending']['admin']); $replacing = !empty($_SESSION['docs_login_pending']['owner']); ?>
        <p><?php if ($namedAdmin): ?>ระบุชื่อผู้ดูแลเพื่อแยกผู้รับผิดชอบ สามารถใช้งานบัญชี Admin พร้อมกันหลายคนได้<?php else: ?>บัญชีมีเซสชันใช้งานอยู่ การยืนยันจะนำเซสชันเดิมออกจากระบบ<?php endif ?></p>
        <?php if ($namedAdmin && $replacing): ?><p class="alert">ชื่อผู้ดูแลนี้ใช้งานอยู่ ยืนยันเพื่อแทนที่เฉพาะเซสชันชื่อนี้ หรือเปลี่ยนเป็นชื่ออื่น</p><?php endif ?>
        <form method="post" action="<?=htmlspecialchars(appUrl('login'), ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="confirmation" value="<?=htmlspecialchars($_SESSION['docs_login_pending']['nonce'], ENT_QUOTES, 'UTF-8')?>">
            <?php if ($namedAdmin): ?><label for="admin_operator">ชื่อผู้ดูแล</label><input id="admin_operator" name="admin_operator" placeholder="admin-eak หรือ admin-moo" pattern="admin-[a-z0-9][a-z0-9_-]{1,31}" maxlength="38" required value="<?=htmlspecialchars((string)($_POST['admin_operator'] ?? $_SESSION['docs_login_pending']['operator'] ?? ''), ENT_QUOTES, 'UTF-8')?>"><p>ชื่อนี้จะแสดงในรายชื่อออนไลน์ ข้อความ และประวัติการรับงาน</p><?php endif ?>
            <button name="action" value="replace" type="submit"><?= $namedAdmin && !$replacing ? 'เข้าสู่ระบบด้วยชื่อผู้ดูแล' : 'ยืนยันเข้าสู่ระบบแทนที่เซสชันเดิม' ?></button>
            <button name="action" value="cancel" type="submit" formnovalidate style="background:#64748b">ยกเลิก</button>
        </form>
        <p class="login-note">คำยืนยันมีอายุ 2 นาที</p>
        <?php else: ?>
        <form method="post" action="<?= htmlspecialchars(appUrl('login'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="username">ชื่อผู้ใช้</label>
            <input id="username" name="username" type="text" maxlength="150" autocomplete="username" required autofocus
                   value="<?= htmlspecialchars((string) ($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

            <label for="password">รหัสผ่าน</label>
            <input id="password" name="password" type="password" maxlength="255" autocomplete="current-password" required>

            <button type="submit">เข้าสู่ระบบ</button>
        </form>
        <?php endif; ?>
        <p class="login-note">ใช้บัญชีผู้ใช้ NMU Datacenter</p>
    </main>
</body>
</html>

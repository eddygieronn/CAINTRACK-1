<?php
// Top of every barangay page: head, header, sidebar, opening <main>.
// Each page sets these before including this file:
//   $pageTitle, $activePage ('dashboard' | 'documents' | 'submit' | 'compliance'),
//   $fullName, $barangayName, and optionally $extraCss (array of CSS file names).
$systemName   = $systemName   ?? 'CAINTRACK';
$pageTitle    = $pageTitle    ?? 'Barangay Portal';
$activePage   = $activePage   ?? '';
$fullName     = $fullName     ?? ($_SESSION['full_name'] ?? 'Barangay User');
$barangayName = $barangayName ?? '';
$extraCss     = $extraCss     ?? [];

$navItems = [
    'dashboard'  => ['userDashboard.php',        '▦', 'Dashboard'],
    'documents'  => ['documents_barangay.php',   '▤', 'My Documents'],
    'submit'     => ['submit_document.php',      '＋', 'Submit a Document'],
    'compliance' => ['compliance_barangay.php',  '✓', 'Compliance Status'],
];
// Notifications for the popup: latest status updates on this barangay's documents
$notifications  = [];
$notifAvailable = true;
try {
    require_once __DIR__ . '/db.php';
    $stmt = db()->prepare(
        "SELECT title, status, remarks, updated_at
         FROM documents
         WHERE barangay_id = ? AND status <> 'pending'
         ORDER BY updated_at DESC
         LIMIT 8"
    );
    $stmt->execute([(int) ($_SESSION['barangay_id'] ?? 0)]);
    $notifications = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('CAINTRACK notifications error: ' . $e->getMessage());
    $notifAvailable = false;
}

$notifText = [
    'approved'     => 'was approved by DILG.',
    'under_review' => 'is now under review.',
    'revision'     => 'was returned for revision.',
    'incomplete'   => 'was marked incomplete.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars($systemName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
    <link rel="stylesheet" href="../CSS/dbuser.css">
    <?php foreach ($extraCss as $css): ?>
    <link rel="stylesheet" href="../CSS/<?= htmlspecialchars($css) ?>">
    <link rel="stylesheet" href="../CSS/notifications.css">
    <?php endforeach; ?>
</head>
<body class="dashboard-page">

<header class="dashboard-header">
    <div class="dashboard-brand">
        <a class="brand" href="userDashboard.php">
            <img src="../IMAGES/dilglogo.png" alt="DILG seal" class="logo">
            <img src="../IMAGES/caintalogo.png" alt="Cainta seal" class="logo">
            <span class="brand-name"><?= htmlspecialchars($systemName) ?></span>
        </a>
        <span class="portal-label">
            Barangay Portal<?= $barangayName !== '' ? ' · ' . htmlspecialchars($barangayName) : '' ?>
        </span>
    </div>

    <div class="account-area">
        <span class="account-name"><?= htmlspecialchars($fullName) ?></span>
        <form method="post" action="logout.php">
            <button class="btn btn-dark logout-btn" type="submit">Log out</button>
        </form>
    </div>
</header>

<div class="dashboard-layout">
    <aside class="dashboard-sidebar">
        <p class="sidebar-label">WORKSPACE</p>
        <?php foreach ($navItems as $key => [$href, $icon, $label]): ?>
            <a class="sidebar-link<?= $key === $activePage ? ' active' : '' ?>" href="<?= $href ?>">
                <span><?= $icon ?></span> <?= $label ?>
            </a>
        <?php endforeach; ?>
        <button type="button" class="sidebar-link sidebar-button" id="open-notifications"
        aria-haspopup="dialog">
    <span>✉</span> Notifications
</button>
<a class="sidebar-link<?= $activePage === 'profile' ? ' active' : '' ?>" href="profile.php">
    <span>☺</span> Profile
</a>
        <div class="sidebar-note">
            <strong>CAINTRACK</strong>
            <p>Barangay Document Monitoring and Tracking System</p>
        </div>
    </aside>
    
    <dialog class="notif-dialog" id="notif-dialog" aria-labelledby="notif-title">
    <div class="notif-head">
        <h2 id="notif-title">Notifications</h2>
        <button type="button" class="notif-close" id="close-notifications" aria-label="Close">×</button>
    </div>

    <?php if (!$notifAvailable): ?>
        <p class="notif-empty">Notifications are not available right now.</p>
    <?php elseif (!$notifications): ?>
        <p class="notif-empty">No notifications yet. Updates on your documents will appear here.</p>
    <?php else: ?>
        <ul class="notif-list">
            <?php foreach ($notifications as $n): ?>
            <li class="notif-item">
                <p><strong><?= htmlspecialchars($n['title']) ?></strong>
                   <?= htmlspecialchars($notifText[$n['status']] ?? 'was updated.') ?></p>
                <?php if (!empty($n['remarks'])): ?>
                    <p class="notif-remarks">DILG remarks: <?= htmlspecialchars($n['remarks']) ?></p>
                <?php endif; ?>
                <small><?= htmlspecialchars(date('M j, Y g:i A', strtotime($n['updated_at']))) ?></small>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</dialog>
<script>
    (function () {
        var dialog = document.getElementById('notif-dialog');
        document.getElementById('open-notifications').addEventListener('click', function () {
            dialog.showModal();
        });
        document.getElementById('close-notifications').addEventListener('click', function () {
            dialog.close();
        });
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) { dialog.close(); }
        });
    })();
</script>
    <main class="dashboard-main">
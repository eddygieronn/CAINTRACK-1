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
        <div class="sidebar-note">
            <strong>CAINTRACK</strong>
            <p>Barangay Document Monitoring and Tracking System</p>
        </div>
    </aside>

    <main class="dashboard-main"></main>
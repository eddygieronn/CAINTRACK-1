
<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    header('Location: signin.php');
    exit;
}

if ($_SESSION['role'] === 'dilg') {
    header('Location: dashboard_dilg.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid request. Please refresh and try again.');
    }

    $_SESSION = [];
    session_destroy();
    header('Location: signin.php');
    exit;
}

$systemName = 'CAINTRACK';
$fullName = $_SESSION['full_name'] ?? 'Barangay User';
$username = $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Barangay Dashboard | <?= $systemName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
    <link rel="stylesheet" href="../CSS/dbuser.css">
</head>
<body class="dashboard-page">

<header class="dashboard-header">
    <div class="dashboard-brand">
        <a class="brand" href="dashboard_barangay.php">
            <img src="../IMAGES/dilglogo.png" alt="DILG seal" class="logo">
            <img src="../IMAGES/caintalogo.png" alt="Cainta seal" class="logo">
            <span class="brand-name"><?= $systemName ?></span>
        </a>
        <span class="portal-label">Barangay Portal</span>
    </div>

    <div class="account-area">
        <span class="account-name"><?= htmlspecialchars($fullName) ?></span>
        <form method="post" action="dashboard_barangay.php">
            <input type="hidden" name="csrf"
                   value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
            <button class="btn btn-dark logout-btn" type="submit" name="logout" value="1">
                Log out
            </button>
        </form>
    </div>
</header>

<div class="dashboard-layout">
    <aside class="dashboard-sidebar">
        <p class="sidebar-label">WORKSPACE</p>
        <a class="sidebar-link active" href="dashboard_barangay.php">
            <span>▦</span> Dashboard
        </a>
        <a class="sidebar-link" href="documents_barangay.php">
            <span>▤</span> My Documents
        </a>
        <a class="sidebar-link" href="submit_document.php">
            <span>＋</span> Submit a Document
        </a>
        <a class="sidebar-link" href="compliance_barangay.php">
            <span>✓</span> Compliance Status
        </a>
        <div class="sidebar-note">
            <strong>CAINTRACK</strong>
            <p>Barangay Document Monitoring and Tracking System</p>
        </div>
    </aside>

    <main class="dashboard-main">
        <div class="welcome-section">
            <div>
                <p class="eyebrow">BARANGAY WORKSPACE</p>
                <h1>Welcome, <?= htmlspecialchars($fullName) ?>!</h1>
                <p>Keep track of your document submissions and requirements.</p>
            </div>
            <a class="btn btn-dark" href="submit_document.php">＋ Submit Document</a>
        </div>

        <section class="summary-grid" aria-label="Document summary">
            <article class="summary-card">
                <div class="summary-icon">▤</div>
                <p>Total Documents</p>
                <h2>—</h2>
                <span>Awaiting database connection</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">◷</div>
                <p>Pending Review</p>
                <h2>—</h2>
                <span>Awaiting database connection</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">✓</div>
                <p>Approved</p>
                <h2>—</h2>
                <span>Awaiting database connection</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">!</div>
                <p>Needs Revision</p>
                <h2>—</h2>
                <span>Awaiting database connection</span>
            </article>
        </section>

        <section class="dashboard-panel">
            <div class="panel-heading">
                <div>
                    <h2>My Recent Submissions</h2>
                    <p>Review the latest documents submitted by your barangay.</p>
                </div>
                <a href="documents_barangay.php">View all</a>
            </div>

            <div class="empty-state">
                <div class="empty-icon">▤</div>
                <h3>Your submissions will appear here</h3>
                <p>Once connected to the database, you can view your document history and submission status.</p>
                <a class="btn btn-outline" href="submit_document.php">Submit your first document</a>
            </div>
        </section>

        <footer class="dashboard-footer">
            <p>CAINTRACK · DILG Cainta, Rizal</p>
        </footer>
    </main>
</div>

</body>
</html>

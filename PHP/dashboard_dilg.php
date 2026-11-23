<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_role('dilg');

$fullName = $_SESSION['full_name'] ?? 'DILG User';

// Approve or reject a pending barangay account
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $id     = (int) ($_POST['user_id'] ?? 0);
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'approve') {
                $st = db()->prepare("UPDATE users SET is_active = 1 WHERE id = ? AND role = 'barangay' AND is_active = 0");
                $st->execute([$id]);
                $_SESSION['flash'] = 'Account approved.';
            } elseif ($action === 'reject') {
                $st = db()->prepare("DELETE FROM users WHERE id = ? AND role = 'barangay' AND is_active = 0");
                $st->execute([$id]);
                $_SESSION['flash'] = 'Account request rejected and removed.';
            }
        } catch (PDOException $e) {
            error_log('CAINTRACK account review error: ' . $e->getMessage());
            $_SESSION['flash'] = 'That request could not be processed.';
        }
    }
    header('Location: dashboard_dilg.php');
    exit;
}

$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Status label and the style class from style.css
$statusMap = [
    'pending'      => ['Pending',      'status-pending'],
    'under_review' => ['Under review', 'status-review'],
];

$pending      = [];
$accountsOk   = true;
$toReview     = 0;
$reviewList   = [];
$documentsOk  = true;
$overdue      = 0;
$barangayRows = [];
$overallRate  = 0;
$complianceOk = true;

// Pending barangay account requests
try {
    $pending = db()->query(
        "SELECT u.id, u.full_name, u.username, u.email, u.created_at, b.name AS barangay
         FROM users u LEFT JOIN barangays b ON b.id = u.barangay_id
         WHERE u.role = 'barangay' AND u.is_active = 0
         ORDER BY u.created_at"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('CAINTRACK dilg accounts error: ' . $e->getMessage());
    $accountsOk = false;
}

// Documents waiting for DILG review
try {
    $toReview = (int) db()->query(
        "SELECT COUNT(*) FROM documents WHERE status IN ('pending','under_review')"
    )->fetchColumn();

    $reviewList = db()->query(
        "SELECT d.title, d.document_type, d.status, d.submitted_at, b.name AS barangay
         FROM documents d JOIN barangays b ON b.id = d.barangay_id
         WHERE d.status IN ('pending','under_review')
         ORDER BY d.submitted_at
         LIMIT 5"
    )->fetchAll();
} catch (PDOException $e) {
    error_log('CAINTRACK dilg documents error: ' . $e->getMessage());
    $documentsOk = false;
}

// Compliance across all barangays
try {
    // Required documents past their deadline with nothing submitted yet
    $overdue = (int) db()->query(
        "SELECT COUNT(*)
         FROM required_documents r
         CROSS JOIN barangays b
         WHERE r.is_active = 1 AND r.deadline < CURDATE()
           AND NOT EXISTS (
               SELECT 1 FROM documents d
               WHERE d.requirement_id = r.id AND d.barangay_id = b.id
           )"
    )->fetchColumn();

    // Per barangay: approved requirements out of all active requirements
    $barangayRows = db()->query(
        "SELECT b.name,
                (SELECT COUNT(*) FROM required_documents r WHERE r.is_active = 1) AS total,
                (SELECT COUNT(*) FROM required_documents r
                 WHERE r.is_active = 1
                   AND (SELECT d.status FROM documents d
                        WHERE d.requirement_id = r.id AND d.barangay_id = b.id
                        ORDER BY d.submitted_at DESC, d.id DESC LIMIT 1) = 'approved') AS compliant
         FROM barangays b
         ORDER BY b.name"
    )->fetchAll();

    $sumTotal = array_sum(array_column($barangayRows, 'total'));
    $sumDone  = array_sum(array_column($barangayRows, 'compliant'));
    $overallRate = $sumTotal > 0 ? (int) round($sumDone / $sumTotal * 100) : 0;
} catch (PDOException $e) {
    error_log('CAINTRACK dilg compliance error: ' . $e->getMessage());
    $complianceOk = false;
}

// Show a dash when a section could not be loaded
$dash = fn(bool $ok, $value): string => $ok ? (string) $value : '—';

// Layout settings, then the shared top of the page
$pageTitle  = 'DILG Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/includes/dash_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">DILG CAINTA, RIZAL</p>
                <h1>Welcome, <?= htmlspecialchars($fullName) ?>!</h1>
                <p>Review account requests, check submissions, and monitor all barangays.</p>
            </div>
        </div>

        <?php if ($notice !== ''): ?>
            <p class="notice" role="status"><?= htmlspecialchars($notice) ?></p>
        <?php endif; ?>

        <?php if (!$documentsOk || !$complianceOk): ?>
            <p class="db-alert" role="alert">
                Some figures could not be loaded. Make sure the <strong>documents</strong> and
                <strong>required_documents</strong> tables exist in the database.
            </p>
        <?php endif; ?>

        <section class="summary-grid" aria-label="Overview">
            <article class="summary-card">
                <div class="summary-icon">◷</div>
                <p>Documents to Review</p>
                <h2><?= $dash($documentsOk, $toReview) ?></h2>
                <span>Pending or under review</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">!</div>
                <p>Overdue Requirements</p>
                <h2><?= $dash($complianceOk, $overdue) ?></h2>
                <span>Past deadline, not submitted</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">%</div>
                <p>Overall Compliance</p>
                <h2><?= $complianceOk ? $overallRate . '%' : '—' ?></h2>
                <span>Approved across all barangays</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">＋</div>
                <p>Account Requests</p>
                <h2><?= $dash($accountsOk, count($pending)) ?></h2>
                <span>Waiting for approval</span>
            </article>
        </section>

        <section class="dashboard-panel">
            <div class="panel-heading">
                <div>
                    <h2>Documents Awaiting Review</h2>
                    <p>The oldest submissions are listed first.</p>
                </div>
                <a href="submissions_dilg.php">View all</a>
            </div>

            <?php if ($documentsOk && $reviewList): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Barangay</th>
                                <th scope="col">Document</th>
                                <th scope="col">Type</th>
                                <th scope="col">Status</th>
                                <th scope="col">Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reviewList as $doc):
                                [$label, $class] = $statusMap[$doc['status']] ?? [$doc['status'], 'status-pending'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($doc['barangay']) ?></td>
                                <td><?= htmlspecialchars($doc['title']) ?></td>
                                <td><?= htmlspecialchars($doc['document_type'] ?? '—') ?></td>
                                <td><span class="status <?= $class ?>"><?= htmlspecialchars($label) ?></span></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($doc['submitted_at']))) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($documentsOk): ?>
                <p class="empty-note">No documents are waiting for review.</p>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel stack-gap">
            <div class="panel-heading">
                <div>
                    <h2>Compliance by Barangay</h2>
                    <p>Approved required documents out of all active requirements.</p>
                </div>
                <a href="compliance_dilg.php">View details</a>
            </div>

            <?php if ($complianceOk && $barangayRows && (int) $barangayRows[0]['total'] > 0): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Barangay</th>
                                <th scope="col">Compliant</th>
                                <th scope="col">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($barangayRows as $b):
                                $rate = (int) $b['total'] > 0
                                    ? (int) round($b['compliant'] / $b['total'] * 100)
                                    : 0;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($b['name']) ?></td>
                                <td><?= (int) $b['compliant'] ?> of <?= (int) $b['total'] ?></td>
                                <td class="rate-cell">
                                    <div class="progress-sm" role="progressbar" aria-valuemin="0"
                                         aria-valuemax="100" aria-valuenow="<?= $rate ?>">
                                        <div class="progress-sm-bar" style="width: <?= $rate ?>%"></div>
                                    </div>
                                    <span><?= $rate ?>%</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($complianceOk): ?>
                <p class="empty-note">No required documents are set yet.</p>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel stack-gap" aria-labelledby="pending-title">
            <div class="panel-heading">
                <div>
                    <h2 id="pending-title">Pending Account Requests</h2>
                    <p>Barangay representatives who registered and are waiting for approval.</p>
                </div>
            </div>

            <?php if ($accountsOk && $pending): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Barangay</th>
                                <th scope="col">Username</th>
                                <th scope="col">Email</th>
                                <th scope="col">Requested</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['full_name']) ?></td>
                                <td><?= htmlspecialchars($p['barangay'] ?? '') ?></td>
                                <td><?= htmlspecialchars($p['username']) ?></td>
                                <td><?= htmlspecialchars($p['email']) ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($p['created_at']))) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <?php foreach (['approve' => ['Approve', 'btn-approve'], 'reject' => ['Reject', 'btn-reject']] as $act => [$label, $cls]): ?>
                                        <form method="post" action="dashboard_dilg.php"
                                              <?= $act === 'reject' ? 'onsubmit="return confirm(\'Reject and remove this request?\');"' : '' ?>>
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                                            <input type="hidden" name="action" value="<?= $act ?>">
                                            <button type="submit" class="btn-sm <?= $cls ?>"><?= $label ?></button>
                                        </form>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($accountsOk): ?>
                <p class="empty-note">No accounts are waiting for approval.</p>
            <?php else: ?>
                <p class="empty-note">Account requests could not be loaded.</p>
            <?php endif; ?>
        </section>

<?php require __DIR__ . '/includes/dash_bottom.php'; ?>
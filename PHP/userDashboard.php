<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_role('barangay');

// A barangay account must be linked to a barangay
if (empty($_SESSION['barangay_id'])) {
    header('Location: logout.php');
    exit;
}

$fullName   = $_SESSION['full_name'] ?? 'Barangay User';
$barangayId = (int) $_SESSION['barangay_id'];

$barangayName = '';
$counts       = ['total' => 0, 'pending' => 0, 'approved' => 0, 'revision' => 0];
$recent       = [];
$dbError      = false;

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT name FROM barangays WHERE id = ?');
    $stmt->execute([$barangayId]);
    $barangayName = (string) ($stmt->fetchColumn() ?: '');

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)                                        AS total,
                SUM(status IN ('pending','under_review'))       AS pending,
                SUM(status = 'approved')                        AS approved,
                SUM(status IN ('revision','incomplete'))        AS revision
         FROM documents
         WHERE barangay_id = ?"
    );
    $stmt->execute([$barangayId]);
    $row = $stmt->fetch();
    foreach ($counts as $key => $_) {
        $counts[$key] = (int) ($row[$key] ?? 0);
    }

    $stmt = $pdo->prepare(
        'SELECT title, document_type, status, submitted_at
         FROM documents
         WHERE barangay_id = ?
         ORDER BY submitted_at DESC
         LIMIT 5'
    );
    $stmt->execute([$barangayId]);
    $recent = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('CAINTRACK barangay dashboard error: ' . $e->getMessage());
    $dbError = true;
}

// Status label and the style class from style.css
$statusMap = [
    'pending'      => ['Pending',      'status-pending'],
    'under_review' => ['Under review', 'status-review'],
    'approved'     => ['Approved',     'status-approved'],
    'incomplete'   => ['Incomplete',   'status-revision'],
    'revision'     => ['For revision', 'status-revision'],
];

// Show a dash instead of 0 when the data could not be loaded
$show = fn(int $n): string => $dbError ? '—' : (string) $n;

// Layout settings, then the shared top of the page
$pageTitle  = 'Barangay Dashboard';
$activePage = 'dashboard';
$extraCss   = ['dbuser.css'];
require __DIR__ . '/includes/user_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">BARANGAY WORKSPACE</p>
                <h1>Welcome, <?= htmlspecialchars($fullName) ?>!</h1>
                <p>Keep track of your document submissions and requirements.</p>
            </div>
            <a class="btn btn-dark" href="submit_document.php">＋ Submit Document</a>
        </div>

        <?php if ($dbError): ?>
            <p class="db-alert" role="alert">
                We could not load your documents right now. Please try again later.
            </p>
        <?php endif; ?>

        <section class="summary-grid" aria-label="Document summary">
            <article class="summary-card">
                <div class="summary-icon">▤</div>
                <p>Total Documents</p>
                <h2><?= $show($counts['total']) ?></h2>
                <span>All submissions</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">◷</div>
                <p>Pending Review</p>
                <h2><?= $show($counts['pending']) ?></h2>
                <span>Waiting for DILG</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">✓</div>
                <p>Approved</p>
                <h2><?= $show($counts['approved']) ?></h2>
                <span>Accepted by DILG</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">!</div>
                <p>Needs Revision</p>
                <h2><?= $show($counts['revision']) ?></h2>
                <span>Update and resubmit</span>
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

            <?php if (!$dbError && $recent): ?>
                <div class="table-wrap">
                    <table class="submission-table">
                        <thead>
                            <tr>
                                <th scope="col">Document</th>
                                <th scope="col">Type</th>
                                <th scope="col">Status</th>
                                <th scope="col">Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $doc):
                                [$label, $class] = $statusMap[$doc['status']] ?? [$doc['status'], 'status-pending'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($doc['title']) ?></td>
                                <td><?= htmlspecialchars($doc['document_type'] ?? '—') ?></td>
                                <td><span class="status <?= $class ?>"><?= htmlspecialchars($label) ?></span></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($doc['submitted_at']))) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (!$dbError): ?>
                <div class="empty-state">
                    <div class="empty-icon">▤</div>
                    <h3>No submissions yet</h3>
                    <p>Documents your barangay submits will appear here with their status.</p>
                    <a class="btn btn-outline" href="submit_document.php">Submit your first document</a>
                </div>
            <?php endif; ?>
        </section>

<?php require __DIR__ . '/includes/user_bottom.php'; ?>
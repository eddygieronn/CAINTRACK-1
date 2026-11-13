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

// Compliance state -> label and the style class from style.css
$stateMap = [
    'compliant' => ['Compliant',       'status-approved'],
    'review'    => ['Awaiting review', 'status-review'],
    'revision'  => ['For revision',    'status-revision'],
    'overdue'   => ['Overdue',         'status-revision'],
    'missing'   => ['Not submitted',   'status-pending'],
];

$today        = new DateTimeImmutable('today');
$barangayName = '';
$rows         = [];
$summary      = ['total' => 0, 'compliant' => 0, 'review' => 0, 'action' => 0];
$rate         = 0;
$dbError      = false;

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT name FROM barangays WHERE id = ?');
    $stmt->execute([$barangayId]);
    $barangayName = (string) ($stmt->fetchColumn() ?: '');

    // Each active requirement with this barangay's latest linked submission
    $stmt = $pdo->prepare(
        'SELECT r.id, r.title, r.document_type, r.deadline,
                d.status AS doc_status
         FROM required_documents r
         LEFT JOIN documents d ON d.id = (
             SELECT d2.id
             FROM documents d2
             WHERE d2.requirement_id = r.id AND d2.barangay_id = ?
             ORDER BY d2.submitted_at DESC, d2.id DESC
             LIMIT 1
         )
         WHERE r.is_active = 1
         ORDER BY r.deadline IS NULL, r.deadline, r.title'
    );
    $stmt->execute([$barangayId]);

    foreach ($stmt->fetchAll() as $r) {
        $deadline = $r['deadline'] ? new DateTimeImmutable($r['deadline']) : null;
        $days     = $deadline ? (int) $today->diff($deadline)->format('%r%a') : null;
        $ds       = $r['doc_status'];

        if ($ds === 'approved') {
            $state = 'compliant';
        } elseif (in_array($ds, ['pending', 'under_review'], true)) {
            $state = 'review';
        } elseif (in_array($ds, ['revision', 'incomplete'], true)) {
            $state = 'revision';
        } elseif ($days !== null && $days < 0) {
            $state = 'overdue';
        } else {
            $state = 'missing';
        }

        $r['state'] = $state;
        $r['days']  = $days;
        $rows[]     = $r;
    }

    $summary['total'] = count($rows);
    foreach ($rows as $r) {
        if ($r['state'] === 'compliant') {
            $summary['compliant']++;
        } elseif ($r['state'] === 'review') {
            $summary['review']++;
        } else {
            $summary['action']++;
        }
    }
    $rate = $summary['total'] > 0
        ? (int) round($summary['compliant'] / $summary['total'] * 100)
        : 0;
} catch (PDOException $e) {
    error_log('CAINTRACK compliance error: ' . $e->getMessage());
    $dbError = true;
}

// Show a dash instead of 0 when the data could not be loaded
$show = fn(int $n): string => $dbError ? '—' : (string) $n;

// Layout settings, then the shared top of the page
$pageTitle  = 'Compliance Status';
$activePage = 'compliance';
$extraCss   = ['compliance.css'];
require __DIR__ . '/includes/user_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">BARANGAY WORKSPACE</p>
                <h1>Compliance Status</h1>
                <p>Track the documents DILG requires from your barangay and their deadlines.</p>
            </div>
            <a class="btn btn-dark" href="submit_document.php">＋ Submit Document</a>
        </div>

        <?php if ($dbError): ?>
            <p class="db-alert" role="alert">
                We could not load your compliance status right now. Please try again later.
            </p>
        <?php endif; ?>

        <section class="summary-grid" aria-label="Compliance summary">
            <article class="summary-card">
                <div class="summary-icon">%</div>
                <p>Compliance Rate</p>
                <h2><?= $dbError ? '—' : $rate . '%' ?></h2>
                <span>Approved requirements</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">✓</div>
                <p>Compliant</p>
                <h2><?= $show($summary['compliant']) ?></h2>
                <span>Out of <?= $show($summary['total']) ?> required</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">◷</div>
                <p>Awaiting Review</p>
                <h2><?= $show($summary['review']) ?></h2>
                <span>Submitted to DILG</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">!</div>
                <p>Needs Action</p>
                <h2><?= $show($summary['action']) ?></h2>
                <span>Missing, overdue, or for revision</span>
            </article>
        </section>

        <section class="dashboard-panel">
            <div class="panel-heading">
                <div>
                    <h2>Required Documents</h2>
                    <p>Your latest submission for each requirement is the one that counts.</p>
                </div>
            </div>

            <?php if (!$dbError && $rows): ?>
                <div class="progress" role="progressbar" aria-label="Compliance rate"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $rate ?>">
                    <div class="progress-bar" style="width: <?= $rate ?>%"></div>
                </div>

                <div class="table-wrap">
                    <table class="submission-table">
                        <thead>
                            <tr>
                                <th scope="col">Requirement</th>
                                <th scope="col">Type</th>
                                <th scope="col">Deadline</th>
                                <th scope="col">Status</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                [$label, $class] = $stateMap[$r['state']];
                                $needsAction = in_array($r['state'], ['missing', 'overdue', 'revision'], true);

                                $note = '';
                                if ($needsAction && $r['days'] !== null) {
                                    if ($r['days'] < 0) {
                                        $n    = abs($r['days']);
                                        $note = $n . ' day' . ($n === 1 ? '' : 's') . ' overdue';
                                    } elseif ($r['days'] === 0) {
                                        $note = 'Due today';
                                    } elseif ($r['days'] <= 7) {
                                        $note = 'Due in ' . $r['days'] . ' day' . ($r['days'] === 1 ? '' : 's');
                                    }
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($r['title']) ?></td>
                                <td><?= htmlspecialchars($r['document_type'] ?? '—') ?></td>
                                <td>
                                    <?= $r['deadline']
                                        ? htmlspecialchars(date('M j, Y', strtotime($r['deadline'])))
                                        : 'No deadline' ?>
                                    <?php if ($note !== ''): ?>
                                        <span class="deadline-note"><?= htmlspecialchars($note) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="status <?= $class ?>"><?= htmlspecialchars($label) ?></span></td>
                                <td>
                                    <?php if ($needsAction): ?>
                                        <a class="action-link"
                                           href="submit_document.php?requirement=<?= (int) $r['id'] ?>">
                                            <?= $r['state'] === 'revision' ? 'Resubmit' : 'Submit' ?>
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (!$dbError): ?>
                <div class="empty-state">
                    <div class="empty-icon">✓</div>
                    <h3>No required documents yet</h3>
                    <p>When DILG Cainta sets required documents and deadlines, they will appear here.</p>
                </div>
            <?php endif; ?>
        </section>

<?php require __DIR__ . '/includes/user_bottom.php'; ?>
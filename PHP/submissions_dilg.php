<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_role('dilg');

$fullName = $_SESSION['full_name'] ?? 'DILG User';

// Status label and the style class from style.css
$statusMap = [
    'pending'      => ['Pending',      'status-pending'],
    'under_review' => ['Under review', 'status-review'],
    'approved'     => ['Approved',     'status-approved'],
    'incomplete'   => ['Incomplete',   'status-revision'],
    'revision'     => ['For revision', 'status-revision'],
];

$statusFilters = ['awaiting' => 'Awaiting review', 'all' => 'All statuses']
               + array_map(fn($v) => $v[0], $statusMap);

// Search, filters, and page from the address bar
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);

$status = $_GET['status'] ?? 'awaiting';
if (!isset($statusFilters[$status])) {
    $status = 'awaiting';
}

$barangayFilter = (int) ($_GET['barangay'] ?? 0);
$page           = max(1, (int) ($_GET['page'] ?? 1));
$perPage        = 10;

// Message from the review page
$notice = $_SESSION['review_ok'] ?? '';
unset($_SESSION['review_ok']);

$barangays  = [];
$documents  = [];
$total      = 0;
$allCount   = 0;
$totalPages = 1;
$hasReq     = false;
$dbError    = false;

try {
    $pdo = db();

    $barangays = $pdo->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
    if ($barangayFilter && !in_array($barangayFilter, array_map('intval', array_column($barangays, 'id')), true)) {
        $barangayFilter = 0;
    }

    // Requirement info is optional (needs the compliance update)
    $hasReq    = (bool) $pdo->query("SHOW COLUMNS FROM documents LIKE 'requirement_id'")->fetch();
    $reqSelect = $hasReq ? ', r.title AS requirement' : ', NULL AS requirement';
    $reqJoin   = $hasReq ? 'LEFT JOIN required_documents r ON r.id = d.requirement_id' : '';

    $where  = '1 = 1';
    $params = [];

    if ($q !== '') {
        $like     = '%' . addcslashes($q, '%_\\') . '%';
        $where   .= ' AND (d.title LIKE ? OR d.document_type LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status === 'awaiting') {
        $where .= " AND d.status IN ('pending','under_review')";
    } elseif ($status !== 'all') {
        $where   .= ' AND d.status = ?';
        $params[] = $status;
    }
    if ($barangayFilter) {
        $where   .= ' AND d.barangay_id = ?';
        $params[] = $barangayFilter;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM documents d JOIN barangays b ON b.id = d.barangay_id WHERE $where"
    );
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;
    $order      = $status === 'awaiting' ? 'd.submitted_at ASC' : 'd.submitted_at DESC';

    $stmt = $pdo->prepare(
        "SELECT d.id, d.title, d.document_type, d.status, d.submitted_at,
                b.name AS barangay $reqSelect
         FROM documents d
         JOIN barangays b ON b.id = d.barangay_id
         $reqJoin
         WHERE $where
         ORDER BY $order
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $documents = $stmt->fetchAll();

    $allCount = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
} catch (PDOException $e) {
    error_log('CAINTRACK review list error: ' . $e->getMessage());
    $dbError = true;
}

// Link to a page number while keeping the search and filters
$pageUrl = function (int $p) use ($q, $status, $barangayFilter): string {
    $args = array_filter(
        ['q' => $q, 'status' => $status, 'barangay' => $barangayFilter ?: null, 'page' => $p > 1 ? $p : null],
        fn($v) => $v !== null && $v !== ''
    );
    return 'submissions_dilg.php' . ($args ? '?' . http_build_query($args) : '');
};

// Layout settings, then the shared top of the page
$pageTitle  = 'Review Submissions';
$activePage = 'submissions';
$extraCss   = ['submissions.css'];
require __DIR__ . '/includes/dash_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">DILG CAINTA, RIZAL</p>
                <h1>Review Submissions</h1>
                <p>Documents submitted by the barangays. Open one to approve it or send it back.</p>
            </div>
        </div>

        <?php if ($notice !== ''): ?>
            <p class="notice" role="status"><?= htmlspecialchars($notice) ?></p>
        <?php endif; ?>

        <?php if ($dbError): ?>
            <p class="db-alert" role="alert">
                We could not load the submissions right now. Make sure the
                <strong>documents</strong> table exists in the database.
            </p>
        <?php endif; ?>

        <section class="dashboard-panel">
            <form class="filter-bar" method="get" action="submissions_dilg.php">
                <div class="filter-field filter-search">
                    <label for="q">Search</label>
                    <input type="search" id="q" name="q" maxlength="100"
                           placeholder="Document title or type"
                           value="<?= htmlspecialchars($q) ?>">
                </div>

                <div class="filter-field">
                    <label for="barangay">Barangay</label>
                    <select id="barangay" name="barangay">
                        <option value="">All barangays</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === $barangayFilter ? ' selected' : '' ?>>
                                <?= htmlspecialchars($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach ($statusFilters as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>"<?= $key === $status ? ' selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-dark">Filter</button>
                <?php if ($q !== '' || $barangayFilter || $status !== 'awaiting'): ?>
                    <a class="btn btn-outline" href="submissions_dilg.php">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (!$dbError && $documents): ?>
                <p class="result-count">
                    Showing <?= count($documents) ?> of <?= $total ?>
                    document<?= $total === 1 ? '' : 's' ?>
                </p>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Barangay</th>
                                <th scope="col">Document</th>
                                <?php if ($hasReq): ?><th scope="col">Requirement</th><?php endif; ?>
                                <th scope="col">Type</th>
                                <th scope="col">Status</th>
                                <th scope="col">Submitted</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc):
                                [$label, $class] = $statusMap[$doc['status']] ?? [$doc['status'], 'status-pending'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($doc['barangay']) ?></td>
                                <td class="wrap-cell"><?= htmlspecialchars($doc['title']) ?></td>
                                <?php if ($hasReq): ?>
                                    <td class="wrap-cell muted-cell"><?= htmlspecialchars($doc['requirement'] ?? '—') ?></td>
                                <?php endif; ?>
                                <td><?= htmlspecialchars($doc['document_type'] ?? '—') ?></td>
                                <td><span class="status <?= $class ?>"><?= htmlspecialchars($label) ?></span></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($doc['submitted_at']))) ?></td>
                                <td>
                                    <a class="action-link" href="review_submission.php?id=<?= (int) $doc['id'] ?>">Review</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination" aria-label="Pages">
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>">‹ Previous</a>
                        <?php else: ?>
                            <span class="disabled">‹ Previous</span>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="current" aria-current="page"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($pageUrl($p)) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>">Next ›</a>
                        <?php else: ?>
                            <span class="disabled">Next ›</span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>

            <?php elseif (!$dbError && $allCount > 0): ?>
                <p class="empty-note">
                    <?= $status === 'awaiting' && $q === '' && !$barangayFilter
                        ? 'No documents are waiting for review.'
                        : 'No documents match your search or filters.' ?>
                </p>
            <?php elseif (!$dbError): ?>
                <p class="empty-note">No barangay has submitted a document yet.</p>
            <?php endif; ?>
        </section>

<?php require __DIR__ . '/includes/dash_bottom.php'; ?>
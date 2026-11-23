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

// Status label and the style class from style.css
$statusMap = [
    'pending'      => ['Pending',      'status-pending'],
    'under_review' => ['Under review', 'status-review'],
    'approved'     => ['Approved',     'status-approved'],
    'incomplete'   => ['Incomplete',   'status-revision'],
    'revision'     => ['For revision', 'status-revision'],
];

// Search, filter, and page from the address bar
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);

$status = $_GET['status'] ?? '';
if (!isset($statusMap[$status])) {
    $status = '';
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

$barangayName = '';
$documents    = [];
$total        = 0;
$allCount     = 0;
$totalPages   = 1;
$dbError      = false;

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT name FROM barangays WHERE id = ?');
    $stmt->execute([$barangayId]);
    $barangayName = (string) ($stmt->fetchColumn() ?: '');

    // Only this barangay's documents, narrowed by the search and filter
    $where  = 'barangay_id = ?';
    $params = [$barangayId];

    if ($q !== '') {
        $like    = '%' . addcslashes($q, '%_\\') . '%';
        $where  .= ' AND (title LIKE ? OR document_type LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== '') {
        $where  .= ' AND status = ?';
        $params[] = $status;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT id, title, document_type, status, remarks, submitted_at, updated_at
         FROM documents
         WHERE $where
         ORDER BY submitted_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $documents = $stmt->fetchAll();

    // Does this barangay have any documents at all? (for the empty message)
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE barangay_id = ?');
    $stmt->execute([$barangayId]);
    $allCount = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('CAINTRACK my documents error: ' . $e->getMessage());
    $dbError = true;
}

// Link to a page number while keeping the search and filter
$pageUrl = function (int $p) use ($q, $status): string {
    $args = array_filter(
        ['q' => $q, 'status' => $status, 'page' => $p > 1 ? $p : null],
        fn($v) => $v !== null && $v !== ''
    );
    return 'documents_barangay.php' . ($args ? '?' . http_build_query($args) : '');
};

// Layout settings, then the shared top of the page
$pageTitle  = 'My Documents';
$activePage = 'documents';
$extraCss   = ['documents.css'];
require __DIR__ . '/includes/user_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">BARANGAY WORKSPACE</p>
                <h1>My Documents</h1>
                <p>All documents submitted by your barangay and their current status.</p>
            </div>
            <a class="btn btn-dark" href="submit_document.php">＋ Submit Document</a>
        </div>

        <?php if ($dbError): ?>
            <p class="db-alert" role="alert">
                We could not load your documents right now. Please try again later.
            </p>
        <?php endif; ?>

        <section class="dashboard-panel">
            <form class="filter-bar" method="get" action="documents_barangay.php">
                <div class="filter-field filter-search">
                    <label for="q">Search</label>
                    <input type="search" id="q" name="q" maxlength="100"
                           placeholder="Document title or type"
                           value="<?= htmlspecialchars($q) ?>">
                </div>

                <div class="filter-field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach ($statusMap as $key => [$label]): ?>
                            <option value="<?= $key ?>"<?= $key === $status ? ' selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-dark">Filter</button>
                <?php if ($q !== '' || $status !== ''): ?>
                    <a class="btn btn-outline" href="documents_barangay.php">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (!$dbError && $documents): ?>
                <p class="result-count">
                    Showing <?= count($documents) ?> of <?= $total ?>
                    document<?= $total === 1 ? '' : 's' ?>
                </p>

                <div class="table-wrap">
                    <table class="submission-table">
                        <thead>
                            <tr>
                                <th scope="col">Document</th>
                                <th scope="col">Type</th>
                                <th scope="col">Status</th>
                                <th scope="col">DILG remarks</th>
                                <th scope="col">Submitted</th>
                                <th scope="col">Last updated</th>
                                <th scope="col">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc):
                                [$label, $class] = $statusMap[$doc['status']] ?? [$doc['status'], 'status-pending'];
                                $remarks = trim((string) ($doc['remarks'] ?? ''));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($doc['title']) ?></td>
                                <td><?= htmlspecialchars($doc['document_type'] ?? '—') ?></td>
                                <td><span class="status <?= $class ?>"><?= htmlspecialchars($label) ?></span></td>
                                <td class="remarks-cell">
                                    <?= $remarks !== '' ? htmlspecialchars(mb_strimwidth($remarks, 0, 80, '…')) : '—' ?>
                                </td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($doc['submitted_at']))) ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($doc['updated_at']))) ?></td>
                                <td><a class="action-link" href="document_details.php?id=<?= (int) $doc['id'] ?>">View</a></td>
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
                <div class="empty-state">
                    <div class="empty-icon">▤</div>
                    <h3>No documents match your search</h3>
                    <p>Try a different title or status, or clear the filter.</p>
                    <a class="btn btn-outline" href="documents_barangay.php">Clear filter</a>
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
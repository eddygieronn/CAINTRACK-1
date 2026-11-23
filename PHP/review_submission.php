<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_role('dilg');

$fullName = $_SESSION['full_name'] ?? 'DILG User';
$userId   = (int) $_SESSION['user_id'];
$id       = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Status label and the style class from style.css
$statusMap = [
    'pending'      => ['Pending',      'status-pending'],
    'under_review' => ['Under review', 'status-review'],
    'approved'     => ['Approved',     'status-approved'],
    'incomplete'   => ['Incomplete',   'status-revision'],
    'revision'     => ['For revision', 'status-revision'],
];

// Decisions DILG can choose
$decisions = [
    'under_review' => 'Under review (still checking)',
    'approved'     => 'Approved',
    'revision'     => 'For revision (send back to the barangay)',
    'incomplete'   => 'Incomplete (missing parts)',
];

$doc        = null;
$versions   = [];
$versionsOk = true;
$errors     = [];
$dbError  = false;
$form     = ['status' => '', 'remarks' => ''];

try {
    $pdo = db();

    $hasReq    = (bool) $pdo->query("SHOW COLUMNS FROM documents LIKE 'requirement_id'")->fetch();
    $reqSelect = $hasReq ? ', r.title AS requirement' : ', NULL AS requirement';
    $reqJoin   = $hasReq ? 'LEFT JOIN required_documents r ON r.id = d.requirement_id' : '';

    $stmt = $pdo->prepare(
        "SELECT d.id, d.title, d.document_type, d.file_path, d.status, d.remarks,
                d.submitted_at, d.updated_at,
                b.name AS barangay, u.full_name AS submitter $reqSelect
         FROM documents d
         JOIN barangays b ON b.id = d.barangay_id
         JOIN users u ON u.id = d.submitted_by
         $reqJoin
         WHERE d.id = ?"
    );
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if (!$doc) {
        header('Location: submissions_dilg.php');
        exit;
    }

    $form['status']  = isset($decisions[$doc['status']]) ? $doc['status'] : '';
    $form['remarks'] = (string) ($doc['remarks'] ?? '');

    // Version history (needs the document_versions table)
    try {
        $stmt = $pdo->prepare(
            'SELECT v.version_no, v.uploaded_at, v.dilg_status, v.dilg_remarks, u.full_name AS uploader
             FROM document_versions v
             JOIN users u ON u.id = v.uploaded_by
             WHERE v.document_id = ?
             ORDER BY v.version_no DESC'
        );
        $stmt->execute([$id]);
        $versions = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('CAINTRACK review version history error: ' . $e->getMessage());
        $versionsOk = false;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['status']  = $_POST['status'] ?? '';
        $form['remarks'] = trim($_POST['remarks'] ?? '');

        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            $errors[] = 'Your session expired. Please try again.';
        }
        if (!isset($decisions[$form['status']])) {
            $errors[] = 'Choose a decision.';
        }
        if (in_array($form['status'], ['revision', 'incomplete'], true) && $form['remarks'] === '') {
            $errors[] = 'Write remarks so the barangay knows what to fix.';
        }
        if (mb_strlen($form['remarks']) > 1000) {
            $errors[] = 'Remarks can be up to 1000 characters.';
        }

        if (!$errors) {
            $remarks = $form['remarks'] !== '' ? $form['remarks'] : null;

            try {
                $stmt = $pdo->prepare(
                    'UPDATE documents
                     SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW()
                     WHERE id = ?'
                );
                $stmt->execute([$form['status'], $remarks, $userId, $id]);
            } catch (PDOException $e) {
                // The reviewed_by / reviewed_at columns are not added yet: save without them
                if ($e->getCode() === '42S22') {
                    $stmt = $pdo->prepare('UPDATE documents SET status = ?, remarks = ? WHERE id = ?');
                    $stmt->execute([$form['status'], $remarks, $id]);
                } else {
                    throw $e;
                }
            }

            // Keep the outcome and remarks on the version DILG just reviewed
            try {
                $stmt = $pdo->prepare('SELECT MAX(version_no) FROM document_versions WHERE document_id = ?');
                $stmt->execute([$id]);
                $latest = (int) $stmt->fetchColumn();

                if ($latest > 0) {
                    $pdo->prepare(
                        'UPDATE document_versions SET dilg_status = ?, dilg_remarks = ?
                         WHERE document_id = ? AND version_no = ?'
                    )->execute([$form['status'], $remarks, $id, $latest]);
                }
            } catch (PDOException $e) {
                error_log('CAINTRACK version outcome not saved: ' . $e->getMessage());
            }

            $_SESSION['review_ok'] = '“' . $doc['title'] . '” (' . $doc['barangay'] . ') was marked '
                . strtolower($statusMap[$form['status']][0]) . '.';
            header('Location: submissions_dilg.php');
            exit;
        }
    }
} catch (PDOException $e) {
    error_log('CAINTRACK review error: ' . $e->getMessage());
    $dbError = true;
}

// Layout settings, then the shared top of the page
$pageTitle  = 'Review Document';
$activePage = 'submissions';
$extraCss   = ['submissions.css', 'versions.css'];
require __DIR__ . '/includes/dash_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">DILG CAINTA, RIZAL</p>
                <h1>Review Document</h1>
                <p>Check the file, then approve it or send it back with remarks.</p>
            </div>
            <a class="btn btn-outline" href="submissions_dilg.php">← Back to submissions</a>
        </div>

        <?php if ($dbError): ?>
            <p class="db-alert" role="alert">
                We could not load this document right now. Please try again later.
            </p>
        <?php elseif ($doc): ?>

        <?php if ($errors): ?>
            <div class="db-alert" role="alert">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="review-grid">
            <section class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h2><?= htmlspecialchars($doc['title']) ?></h2>
                        <p>Submitted by <?= htmlspecialchars($doc['submitter']) ?></p>
                    </div>
                    <?php [$label, $class] = $statusMap[$doc['status']] ?? [$doc['status'], 'status-pending']; ?>
                    <span class="status <?= $class ?>"><?= htmlspecialchars($label) ?></span>
                </div>

                <dl class="detail-list">
                    <div><dt>Barangay</dt><dd><?= htmlspecialchars($doc['barangay']) ?></dd></div>
                    <div><dt>Document type</dt><dd><?= htmlspecialchars($doc['document_type'] ?? '—') ?></dd></div>
                    <div><dt>Required document</dt><dd><?= htmlspecialchars($doc['requirement'] ?? 'None (general document)') ?></dd></div>
                    <div><dt>Submitted</dt>
                        <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['submitted_at']))) ?></dd></div>
                    <div><dt>Last updated</dt>
                        <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['updated_at']))) ?></dd></div>
                    <div><dt>File</dt>
                        <dd>
                            <?php if (!empty($doc['file_path'])): ?>
                                <a class="action-link" href="download.php?id=<?= (int) $doc['id'] ?>" target="_blank" rel="noopener">
                                    Open file ↗
                                </a>
                            <?php else: ?>
                                No file attached
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h2>Your decision</h2>
                        <p>The barangay will see this in its notifications.</p>
                    </div>
                </div>

                <form class="review-form" method="post" action="review_submission.php?id=<?= (int) $doc['id'] ?>" novalidate>
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">

                    <div class="form-field">
                        <label for="status">Decision</label>
                        <select id="status" name="status" required>
                            <option value="">Choose a decision</option>
                            <?php foreach ($decisions as $key => $label): ?>
                                <option value="<?= $key ?>"<?= $form['status'] === $key ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" rows="5" maxlength="1000"
                                  placeholder="Required when sending a document back"><?= htmlspecialchars($form['remarks']) ?></textarea>
                        <p class="form-hint">Up to 1000 characters.</p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-dark">Save decision</button>
                        <a class="btn btn-outline" href="submissions_dilg.php">Cancel</a>
                    </div>
                </form>
            </section>
        </div>

        <section class="dashboard-panel stack-gap">
            <div class="panel-heading">
                <div>
                    <h2>Version history</h2>
                    <p>Every file the barangay uploaded for this document, newest first. DILG reviews the current version.</p>
                </div>
            </div>

            <?php if (!$versionsOk): ?>
                <p class="muted">Version history is not set up yet. Run <strong>schema_update_versions.sql</strong>.</p>
            <?php elseif (!$versions): ?>
                <p class="muted">No versions are recorded for this document yet.</p>
            <?php else: ?>
                <?php $latestNo = (int) $versions[0]['version_no']; ?>
                <div class="table-wrap">
                    <table class="version-table">
                        <thead>
                            <tr>
                                <th scope="col">Version</th>
                                <th scope="col">Uploaded</th>
                                <th scope="col">File</th>
                                <th scope="col">DILG outcome</th>
                                <th scope="col">DILG remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($versions as $v):
                                $isLatest = (int) $v['version_no'] === $latestNo;
                            ?>
                            <tr>
                                <td>
                                    v<?= (int) $v['version_no'] ?>
                                    <?php if ($isLatest): ?><span class="current-tag">Current</span><?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?= htmlspecialchars(date('M j, Y g:i A', strtotime($v['uploaded_at']))) ?>
                                    <span class="muted"><br>by <?= htmlspecialchars($v['uploader']) ?></span>
                                </td>
                                <td class="nowrap">
                                    <a class="action-link"
                                       href="download.php?id=<?= (int) $doc['id'] ?>&amp;version=<?= (int) $v['version_no'] ?>"
                                       target="_blank" rel="noopener">Open ↗</a>
                                </td>
                                <td class="nowrap">
                                    <?php if ($v['dilg_status']):
                                        [$vl, $vc] = $statusMap[$v['dilg_status']] ?? [$v['dilg_status'], 'status-pending']; ?>
                                        <span class="status <?= $vc ?>"><?= htmlspecialchars($vl) ?></span>
                                    <?php elseif ($isLatest): ?>
                                        <span class="muted">Awaiting review</span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="remarks"><?= !empty($v['dilg_remarks']) ? htmlspecialchars($v['dilg_remarks']) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php endif; ?>

<?php require __DIR__ . '/includes/dash_bottom.php'; ?>
<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/upload_helpers.php';
require_role('barangay');

// A barangay account must be linked to a barangay
if (empty($_SESSION['barangay_id'])) {
    header('Location: logout.php');
    exit;
}

$fullName   = $_SESSION['full_name'] ?? 'Barangay User';
$barangayId = (int) $_SESSION['barangay_id'];
$userId     = (int) $_SESSION['user_id'];
$id         = (int) ($_GET['id'] ?? 0);

// Status label and the style class from style.css
$statusMap = [
    'pending'      => ['Pending',      'status-pending'],
    'under_review' => ['Under review', 'status-review'],
    'approved'     => ['Approved',     'status-approved'],
    'incomplete'   => ['Incomplete',   'status-revision'],
    'revision'     => ['For revision', 'status-revision'],
];

$doc          = null;
$versions     = [];
$versionsOk   = true;
$errors       = [];
$dbError      = false;
$barangayName = '';

// Message from the previous upload
$success = $_SESSION['version_ok'] ?? '';
unset($_SESSION['version_ok']);

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT name FROM barangays WHERE id = ?');
    $stmt->execute([$barangayId]);
    $barangayName = (string) ($stmt->fetchColumn() ?: '');

    $hasReq    = (bool) $pdo->query("SHOW COLUMNS FROM documents LIKE 'requirement_id'")->fetch();
    $reqSelect = $hasReq ? ', r.title AS requirement' : ', NULL AS requirement';
    $reqJoin   = $hasReq ? 'LEFT JOIN required_documents r ON r.id = d.requirement_id' : '';

    // Only documents that belong to this barangay
    $stmt = $pdo->prepare(
        "SELECT d.id, d.title, d.document_type, d.file_path, d.status, d.remarks,
                d.submitted_by, d.submitted_at, d.updated_at $reqSelect
         FROM documents d
         $reqJoin
         WHERE d.id = ? AND d.barangay_id = ?"
    );
    $stmt->execute([$id, $barangayId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        header('Location: documents_barangay.php');
        exit;
    }

    $isReturned = in_array($doc['status'], ['revision', 'incomplete'], true);

    // Upload a revised version
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newPath = null;

        // A file bigger than the server's post_max_size arrives with an empty form
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $errors[] = 'That file is too large for the server. Choose a smaller file.';
        } else {
            if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
                $errors[] = 'Your session expired. Please try again.';
            }
            if (!$isReturned) {
                $errors[] = 'This document is not waiting for a revision.';
            }

            $file = $_FILES['document'] ?? null;
            $ext  = validate_upload($file, $errors);

            if (!$errors && $ext !== null) {
                $newPath = save_upload($file, $ext);
                if ($newPath === null) {
                    $errors[] = 'The server could not save your file. Please contact the administrator.';
                }
            }
        }

        if (!$errors && $newPath !== null) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM document_versions WHERE document_id = ?');
                $stmt->execute([$id]);
                $max = (int) $stmt->fetchColumn();

                // Older documents may not have a version 1 yet: record it first
                if ($max === 0 && !empty($doc['file_path'])) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO document_versions
                            (document_id, version_no, file_path, uploaded_by, uploaded_at, dilg_status, dilg_remarks)
                         VALUES (?, 1, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $id,
                        $doc['file_path'],
                        (int) $doc['submitted_by'],
                        $doc['submitted_at'],
                        $doc['status'],
                        $doc['remarks'],
                    ]);
                    $max = 1;
                }

                $newVersion = $max + 1;

                $stmt = $pdo->prepare(
                    'INSERT INTO document_versions (document_id, version_no, file_path, uploaded_by)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$id, $newVersion, $newPath, $userId]);

                // The document now points to the newest file and goes back to DILG for review
                $stmt = $pdo->prepare(
                    "UPDATE documents
                     SET file_path = ?, status = 'pending', remarks = NULL
                     WHERE id = ? AND barangay_id = ?"
                );
                $stmt->execute([$newPath, $id, $barangayId]);

                $pdo->commit();

                $_SESSION['version_ok'] = 'Version ' . $newVersion . ' was uploaded. DILG will review it again.';
                header('Location: document_details.php?id=' . $id);
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                @unlink(upload_full_path($newPath));
                error_log('CAINTRACK revision upload error: ' . $e->getMessage());

                $errors[] = $e->getCode() === '42S02'
                    ? 'Version history is not set up yet. Ask the administrator to run schema_update_versions.sql.'
                    : 'Something went wrong on our side. Please try again later.';
            }
        }
    }

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
        error_log('CAINTRACK version history error: ' . $e->getMessage());
        $versionsOk = false;
    }
} catch (PDOException $e) {
    error_log('CAINTRACK document details error: ' . $e->getMessage());
    $dbError = true;
}

$isReturned = $doc && in_array($doc['status'], ['revision', 'incomplete'], true);
$latestNo   = $versions ? (int) $versions[0]['version_no'] : 0;

// Layout settings, then the shared top of the page
$pageTitle  = 'Document Details';
$activePage = 'documents';
$extraCss   = ['versions.css'];
require __DIR__ . '/includes/user_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">BARANGAY WORKSPACE</p>
                <h1>Document Details</h1>
                <p>View the status and version history of this document.</p>
            </div>
            <a class="btn btn-outline" href="documents_barangay.php">← My Documents</a>
        </div>

        <?php if ($success !== ''): ?>
            <p class="form-success-box" role="status"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="form-error-box" role="alert">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($dbError): ?>
            <p class="form-error-box" role="alert">
                We could not load this document right now. Please try again later.
            </p>
        <?php elseif ($doc): ?>
            <?php [$label, $class] = $statusMap[$doc['status']] ?? [$doc['status'], 'status-pending']; ?>

        <section class="dashboard-panel">
            <div class="panel-heading">
                <div>
                    <h2><?= htmlspecialchars($doc['title']) ?></h2>
                    <p><?= htmlspecialchars($doc['document_type'] ?? 'Document') ?></p>
                </div>
                <span class="status <?= $class ?>"><?= htmlspecialchars($label) ?></span>
            </div>

            <?php if ($isReturned): ?>
                <div class="returned-note" role="status">
                    <strong>DILG sent this document back</strong>
                    <p><?= !empty($doc['remarks'])
                        ? htmlspecialchars($doc['remarks'])
                        : 'No remarks were given. Please check the document and upload a corrected version.' ?></p>
                </div>
            <?php endif; ?>

            <dl class="detail-list">
                <div><dt>Required document</dt>
                    <dd><?= htmlspecialchars($doc['requirement'] ?? 'None (general document)') ?></dd></div>
                <div><dt>First submitted</dt>
                    <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['submitted_at']))) ?></dd></div>
                <div><dt>Last updated</dt>
                    <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['updated_at']))) ?></dd></div>
                <div><dt>Current version</dt>
                    <dd><?= $latestNo > 0 ? 'Version ' . $latestNo : '—' ?></dd></div>
                <div><dt>Current file</dt>
                    <dd>
                        <?php if (!empty($doc['file_path'])): ?>
                            <a class="action-link" href="download.php?id=<?= (int) $doc['id'] ?>" target="_blank" rel="noopener">Open file ↗</a>
                        <?php else: ?>
                            No file attached
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
        </section>

        <?php if ($isReturned): ?>
        <section class="dashboard-panel stack-gap">
            <div class="panel-heading">
                <div>
                    <h2>Upload a revised version</h2>
                    <p>Your earlier versions are kept in the history below.</p>
                </div>
            </div>

            <form class="revision-form" method="post" action="document_details.php?id=<?= (int) $doc['id'] ?>"
                  enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

                <div class="form-field">
                    <label for="document">Corrected file</label>
                    <input type="file" id="document" name="document"
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                    <p class="form-hint">PDF, Word (DOC or DOCX), JPG, or PNG. Up to 5 MB.</p>
                </div>

                <button type="submit" class="btn btn-dark">Upload revised version</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="dashboard-panel stack-gap">
            <div class="panel-heading">
                <div>
                    <h2>Version history</h2>
                    <p>Every file uploaded for this document, newest first.</p>
                </div>
            </div>

            <?php if (!$versionsOk): ?>
                <p class="muted">Version history is not set up yet. Ask the administrator to run
                    <strong>schema_update_versions.sql</strong>.</p>
            <?php elseif (!$versions): ?>
                <p class="muted">No versions are recorded for this document yet.</p>
            <?php else: ?>
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

<?php require __DIR__ . '/includes/user_bottom.php'; ?>
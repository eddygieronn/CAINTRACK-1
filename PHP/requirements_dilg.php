<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_role('dilg');

$fullName = $_SESSION['full_name'] ?? 'DILG User';

// Same document types the barangays choose from on the submit page
$types = ['Report', 'Certification', 'Financial', 'Resolution', 'Ordinance', 'Other'];

$form = ['id' => 0, 'title' => '', 'document_type' => '', 'description' => '', 'deadline' => ''];

$errors       = [];
$dbError      = false;
$tableMissing = false;
$requirements = [];
$brgyCount    = 0;

// Messages from the previous action
$success = $_SESSION['req_ok'] ?? '';
$warning = $_SESSION['req_err'] ?? '';
unset($_SESSION['req_ok'], $_SESSION['req_err']);

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $id     = (int) ($_POST['id'] ?? 0);

        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            $errors[] = 'Your session expired. Please try again.';

        } elseif ($action === 'save') {
            $form = [
                'id'            => $id,
                'title'         => trim($_POST['title'] ?? ''),
                'document_type' => $_POST['document_type'] ?? '',
                'description'   => trim($_POST['description'] ?? ''),
                'deadline'      => trim($_POST['deadline'] ?? ''),
            ];

            if ($form['title'] === '' || mb_strlen($form['title']) > 200) {
                $errors[] = 'Enter the document title (up to 200 characters).';
            }
            if ($form['document_type'] !== '' && !in_array($form['document_type'], $types, true)) {
                $errors[] = 'Choose a valid document type.';
            }
            if (mb_strlen($form['description']) > 1000) {
                $errors[] = 'The description can be up to 1000 characters.';
            }
            if ($form['deadline'] !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $form['deadline']);
                if (!$d || $d->format('Y-m-d') !== $form['deadline']) {
                    $errors[] = 'Enter a valid deadline date.';
                }
            }

            if (!$errors) {
                $type     = $form['document_type'] !== '' ? $form['document_type'] : null;
                $desc     = $form['description'] !== '' ? $form['description'] : null;
                $deadline = $form['deadline'] !== '' ? $form['deadline'] : null;

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE required_documents
                         SET title = ?, document_type = ?, description = ?, deadline = ?
                         WHERE id = ?'
                    );
                    $stmt->execute([$form['title'], $type, $desc, $deadline, $id]);
                    $_SESSION['req_ok'] = '“' . $form['title'] . '” was updated.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO required_documents (title, document_type, description, deadline)
                         VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$form['title'], $type, $desc, $deadline]);
                    $_SESSION['req_ok'] = '“' . $form['title'] . '” was added. Barangays can see it now.';
                }
                header('Location: requirements_dilg.php');
                exit;
            }

        } elseif ($action === 'toggle' && $id > 0) {
            $pdo->prepare('UPDATE required_documents SET is_active = 1 - is_active WHERE id = ?')
                ->execute([$id]);
            $_SESSION['req_ok'] = 'The requirement was updated.';
            header('Location: requirements_dilg.php');
            exit;

        } elseif ($action === 'delete' && $id > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE requirement_id = ?');
            $stmt->execute([$id]);

            if ((int) $stmt->fetchColumn() > 0) {
                $_SESSION['req_err'] = 'That requirement already has submissions, so it cannot be deleted. Deactivate it instead.';
            } else {
                $pdo->prepare('DELETE FROM required_documents WHERE id = ?')->execute([$id]);
                $_SESSION['req_ok'] = 'The requirement was deleted.';
            }
            header('Location: requirements_dilg.php');
            exit;
        }
    }

    // Edit mode: load the requirement into the form
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
        $stmt = $pdo->prepare(
            'SELECT id, title, document_type, description, deadline
             FROM required_documents WHERE id = ?'
        );
        $stmt->execute([(int) $_GET['edit']]);
        if ($row = $stmt->fetch()) {
            $form = [
                'id'            => (int) $row['id'],
                'title'         => $row['title'],
                'document_type' => (string) ($row['document_type'] ?? ''),
                'description'   => (string) ($row['description'] ?? ''),
                'deadline'      => (string) ($row['deadline'] ?? ''),
            ];
        }
    }

    $brgyCount = (int) $pdo->query('SELECT COUNT(*) FROM barangays')->fetchColumn();

    $requirements = $pdo->query(
        'SELECT r.id, r.title, r.document_type, r.description, r.deadline, r.is_active,
                (SELECT COUNT(DISTINCT d.barangay_id) FROM documents d WHERE d.requirement_id = r.id) AS submitted,
                (SELECT COUNT(*) FROM documents d WHERE d.requirement_id = r.id) AS doc_count
         FROM required_documents r
         ORDER BY r.is_active DESC, r.deadline IS NULL, r.deadline, r.title'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('CAINTRACK requirements error: ' . $e->getMessage());
    $dbError      = true;
    $tableMissing = in_array($e->getCode(), ['42S02', '42S22'], true);
}

$today    = new DateTimeImmutable('today');
$editing  = $form['id'] > 0;

// Layout settings, then the shared top of the page
$pageTitle  = 'Required Documents';
$activePage = 'requirements';
$extraCss   = ['requirements.css'];
require __DIR__ . '/includes/dash_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">DILG CAINTA, RIZAL</p>
                <h1>Required Documents</h1>
                <p>Set the documents every barangay must submit and their deadlines.</p>
            </div>
        </div>

        <?php if ($success !== ''): ?>
            <p class="notice" role="status"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <?php if ($warning !== ''): ?>
            <p class="db-alert" role="alert"><?= htmlspecialchars($warning) ?></p>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="db-alert" role="alert">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($dbError): ?>
            <p class="db-alert" role="alert">
                <?php if ($tableMissing): ?>
                    The required documents tables are not set up yet. Run
                    <strong>schema_update_compliance.sql</strong> in phpMyAdmin, then reload this page.
                <?php else: ?>
                    We could not load the required documents right now. Please try again later.
                <?php endif; ?>
            </p>
        <?php else: ?>

        <section class="dashboard-panel" id="form">
            <div class="panel-heading">
                <div>
                    <h2><?= $editing ? 'Edit required document' : 'Add a required document' ?></h2>
                    <p>Barangays see active requirements on their Compliance Status page.</p>
                </div>
            </div>

            <form class="req-form" method="post" action="requirements_dilg.php" novalidate>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

                <div class="form-field field-title">
                    <label for="title">Document title</label>
                    <input type="text" id="title" name="title" maxlength="200"
                           value="<?= htmlspecialchars($form['title']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="document_type">Document type</label>
                    <select id="document_type" name="document_type">
                        <option value="">Any type</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>"<?= $form['document_type'] === $t ? ' selected' : '' ?>>
                                <?= htmlspecialchars($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="deadline">Deadline</label>
                    <input type="date" id="deadline" name="deadline"
                           value="<?= htmlspecialchars($form['deadline']) ?>">
                    <p class="form-hint">Leave empty for no deadline.</p>
                </div>

                <div class="form-field field-wide">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3" maxlength="1000"
                              placeholder="What the barangay should submit (optional)"><?= htmlspecialchars($form['description']) ?></textarea>
                </div>

                <div class="form-actions field-wide">
                    <button type="submit" class="btn btn-dark">
                        <?= $editing ? 'Save changes' : 'Add requirement' ?>
                    </button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline" href="requirements_dilg.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="dashboard-panel stack-gap">
            <div class="panel-heading">
                <div>
                    <h2>All requirements</h2>
                    <p>Deactivate a requirement to hide it from barangays without losing its history.</p>
                </div>
            </div>

            <?php if ($requirements): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Requirement</th>
                                <th scope="col">Type</th>
                                <th scope="col">Deadline</th>
                                <th scope="col">Submitted by</th>
                                <th scope="col">Status</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requirements as $r):
                                $active   = (int) $r['is_active'] === 1;
                                $deadline = $r['deadline'] ? new DateTimeImmutable($r['deadline']) : null;
                                $days     = $deadline ? (int) $today->diff($deadline)->format('%r%a') : null;

                                $note = '';
                                if ($active && $days !== null) {
                                    if ($days < 0) {
                                        $n    = abs($days);
                                        $note = $n . ' day' . ($n === 1 ? '' : 's') . ' past due';
                                    } elseif ($days === 0) {
                                        $note = 'Due today';
                                    } elseif ($days <= 7) {
                                        $note = 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's');
                                    }
                                }
                            ?>
                            <tr class="<?= $active ? '' : 'row-inactive' ?>">
                                <td class="wrap-cell">
                                    <?= htmlspecialchars($r['title']) ?>
                                    <?php if (!empty($r['description'])): ?>
                                        <span class="row-desc"><?= htmlspecialchars(mb_strimwidth($r['description'], 0, 90, '…')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['document_type'] ?? 'Any') ?></td>
                                <td>
                                    <?= $deadline ? htmlspecialchars($deadline->format('M j, Y')) : 'No deadline' ?>
                                    <?php if ($note !== ''): ?>
                                        <span class="deadline-note"><?= htmlspecialchars($note) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $r['submitted'] ?> of <?= $brgyCount ?> barangays</td>
                                <td>
                                    <span class="status <?= $active ? 'status-approved' : 'status-pending' ?>">
                                        <?= $active ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn-sm btn-neutral" href="requirements_dilg.php?edit=<?= (int) $r['id'] ?>#form">Edit</a>

                                        <form method="post" action="requirements_dilg.php">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <button type="submit" class="btn-sm btn-neutral">
                                                <?= $active ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>

                                        <?php if ((int) $r['doc_count'] === 0): ?>
                                        <form method="post" action="requirements_dilg.php"
                                              onsubmit="return confirm('Delete this requirement?');">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <button type="submit" class="btn-sm btn-reject">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-note">No required documents yet. Add the first one above.</p>
            <?php endif; ?>
        </section>

        <?php endif; ?>

<?php require __DIR__ . '/includes/dash_bottom.php'; ?>
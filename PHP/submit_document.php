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
$userId     = (int) $_SESSION['user_id'];

// Upload rules
$maxBytes  = 5 * 1024 * 1024; // 5 MB
$types     = ['Report', 'Certification', 'Financial', 'Resolution', 'Ordinance', 'Other'];
$allowed   = [
    'pdf'  => ['application/pdf'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-cfb',
               'application/CDFV2', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
               'application/zip', 'application/octet-stream'],
];
$uploadDir = dirname(__DIR__) . '/uploads';   // project-root/uploads

$errors = [];
$old    = ['title' => '', 'document_type' => '', 'requirement_id' => ''];

// Success message from the previous submit
$success = $_SESSION['submit_ok'] ?? '';
unset($_SESSION['submit_ok']);

// Barangay name for the header
$barangayName = '';
try {
    $stmt = db()->prepare('SELECT name FROM barangays WHERE id = ?');
    $stmt->execute([$barangayId]);
    $barangayName = (string) ($stmt->fetchColumn() ?: '');
} catch (PDOException $e) {
    error_log('CAINTRACK submit page error: ' . $e->getMessage());
}

// Active requirements the document can be linked to (empty if not set up yet)
$requirements = [];
try {
    $stmt = db()->query(
        'SELECT id, title, document_type FROM required_documents
         WHERE is_active = 1 ORDER BY deadline IS NULL, deadline, title'
    );
    foreach ($stmt->fetchAll() as $r) {
        $requirements[(int) $r['id']] = $r;
    }
} catch (PDOException $e) {
    error_log('CAINTRACK requirements not available: ' . $e->getMessage());
}

// Arriving from the Compliance page: preselect the requirement and prefill the form
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $wanted = (int) ($_GET['requirement'] ?? 0);
    if ($wanted && isset($requirements[$wanted])) {
        $old['requirement_id'] = (string) $wanted;
        $old['title']          = $requirements[$wanted]['title'];
        if (in_array($requirements[$wanted]['document_type'], $types, true)) {
            $old['document_type'] = $requirements[$wanted]['document_type'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // A file bigger than the server's post_max_size arrives with an empty form
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'That file is too large for the server. Choose a smaller file.';
    } else {
        $old['title']         = trim($_POST['title'] ?? '');
        $old['document_type'] = $_POST['document_type'] ?? '';
        $old['requirement_id'] = $_POST['requirement_id'] ?? '';
        $requirementId        = null;
        $file                 = $_FILES['document'] ?? null;
        $ext                  = '';

        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            $errors[] = 'Your session expired. Please try again.';
        }

        if ($old['title'] === '' || mb_strlen($old['title']) > 200) {
            $errors[] = 'Enter the document title (up to 200 characters).';
        }

        if (!in_array($old['document_type'], $types, true)) {
            $errors[] = 'Select the document type.';
        }

        if ($old['requirement_id'] !== '') {
            if (isset($requirements[(int) $old['requirement_id']])) {
                $requirementId = (int) $old['requirement_id'];
            } else {
                $errors[] = 'Select a valid required document, or choose none.';
            }
        }

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose a file to upload.';
        } elseif (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $errors[] = 'That file is too large. The limit is 5 MB.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The file could not be uploaded. Please try again.';
        } else {
            if ($file['size'] > $maxBytes) {
                $errors[] = 'That file is too large. The limit is 5 MB.';
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!isset($allowed[$ext])) {
                $errors[] = 'Upload a PDF, Word (DOC or DOCX), JPG, or PNG file.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                if (!in_array($mime, $allowed[$ext], true)) {
                    $errors[] = 'The file does not look like a valid ' . strtoupper($ext) . ' file.';
                }
            }
        }

        if (!$errors) {
            if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
                error_log('CAINTRACK could not create upload folder: ' . $uploadDir);
                $errors[] = 'The server could not save your file. Please contact the administrator.';
            } else {
                // Block direct web access where Apache allows .htaccess
                $htaccess = $uploadDir . '/.htaccess';
                if (!file_exists($htaccess)) {
                    @file_put_contents($htaccess, "Require all denied\n");
                }

                // Random name so files cannot be guessed or overwritten
                $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                $target = $uploadDir . '/' . $stored;

                if (!move_uploaded_file($file['tmp_name'], $target)) {
                    error_log('CAINTRACK could not move upload to: ' . $target);
                    $errors[] = 'The server could not save your file. Please contact the administrator.';
                } else {
                    try {
                        if ($requirementId !== null) {
                            $stmt = db()->prepare(
                                "INSERT INTO documents
                                    (barangay_id, requirement_id, submitted_by, title, document_type, file_path, status)
                                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                            );
                            $stmt->execute([
                                $barangayId,
                                $requirementId,
                                $userId,
                                $old['title'],
                                $old['document_type'],
                                'uploads/' . $stored,
                            ]);
                        } else {
                            $stmt = db()->prepare(
                                "INSERT INTO documents
                                    (barangay_id, submitted_by, title, document_type, file_path, status)
                                 VALUES (?, ?, ?, ?, ?, 'pending')"
                            );
                            $stmt->execute([
                                $barangayId,
                                $userId,
                                $old['title'],
                                $old['document_type'],
                                'uploads/' . $stored,
                            ]);
                        }

                        $_SESSION['submit_ok'] = '“' . $old['title'] . '” was submitted. DILG will review it.';
                        header('Location: submit_document.php');
                        exit;
                    } catch (PDOException $e) {
                        @unlink($target);
                        error_log('CAINTRACK submit error: ' . $e->getMessage());
                        $errors[] = 'Something went wrong on our side. Please try again later.';
                    }
                }
            }
        }
    }
}

// Layout settings, then the shared top of the page
$pageTitle  = 'Submit a Document';
$activePage = 'submit';
$extraCss   = ['submit.css'];
require __DIR__ . '/includes/user_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">BARANGAY WORKSPACE</p>
                <h1>Submit a Document</h1>
                <p>Upload a document for DILG Cainta to review.</p>
            </div>
            <a class="btn btn-outline" href="documents_barangay.php">My Documents</a>
        </div>

        <?php if ($success !== ''): ?>
            <p class="form-success-box" role="status">
                <?= htmlspecialchars($success) ?>
                <a href="documents_barangay.php">View my documents</a>
            </p>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="form-error-box" role="alert">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="dashboard-panel">
            <form class="submit-form" method="post" action="submit_document.php"
                  enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

                <?php if ($requirements): ?>
                <div class="form-field">
                    <label for="requirement_id">This document is for</label>
                    <select id="requirement_id" name="requirement_id">
                        <option value="">None (general document)</option>
                        <?php foreach ($requirements as $rid => $r): ?>
                            <option value="<?= $rid ?>"
                                <?= (string) $rid === (string) $old['requirement_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Pick the DILG requirement this answers so it counts toward your compliance.</p>
                </div>
                <?php endif; ?>

                <div class="form-field">
                    <label for="title">Document title</label>
                    <input type="text" id="title" name="title" maxlength="200"
                           value="<?= htmlspecialchars($old['title']) ?>" required>
                </div>

                <div class="form-field">
                    <label for="document_type">Document type</label>
                    <select id="document_type" name="document_type" required>
                        <option value="">Select type</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>"
                                <?= $old['document_type'] === $t ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="document">File</label>
                    <input type="file" id="document" name="document"
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                    <p class="form-hint">PDF, Word (DOC or DOCX), JPG, or PNG. Up to 5 MB.</p>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-dark">Submit document</button>
                    <a class="btn btn-outline" href="userDashboard.php">Cancel</a>
                </div>
            </form>
        </section>

<?php require __DIR__ . '/includes/user_bottom.php'; ?>
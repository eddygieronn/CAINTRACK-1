<?php
// Serves an uploaded document, but only to people allowed to see it:
// any DILG user, or a barangay user from the barangay that owns the document.
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_login();

function fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    fail(404, 'File not found.');
}

try {
    $stmt = db()->prepare('SELECT title, file_path, barangay_id FROM documents WHERE id = ?');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
} catch (PDOException $e) {
    error_log('CAINTRACK download error: ' . $e->getMessage());
    fail(500, 'Something went wrong. Please try again later.');
}

if (!$doc || empty($doc['file_path'])) {
    fail(404, 'File not found.');
}

$isDilg  = $_SESSION['role'] === 'dilg';
$isOwner = $_SESSION['role'] === 'barangay'
    && (int) ($_SESSION['barangay_id'] ?? 0) === (int) $doc['barangay_id'];

if (!$isDilg && !$isOwner) {
    fail(403, 'You do not have access to this file.');
}

// The file must really be inside the uploads folder
$base = realpath(dirname(__DIR__) . '/uploads');
$path = realpath(dirname(__DIR__) . '/' . $doc['file_path']);

if ($base === false || $path === false
    || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    fail(404, 'File not found.');
}

$ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
if (!isset($types[$ext])) {
    fail(404, 'File not found.');
}

// PDFs and images open in the browser; Word files download
$inline   = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
$safeName = trim(preg_replace('/[^A-Za-z0-9._ -]+/', '_', $doc['title']), ' ._') ?: 'document';

session_write_close();

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($path);
exit;
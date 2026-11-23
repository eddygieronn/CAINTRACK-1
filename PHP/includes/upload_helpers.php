<?php
// Shared rules for uploaded files (used by the revision upload).

const UPLOAD_MAX_BYTES = 5 * 1024 * 1024; // 5 MB

function upload_allowed_types(): array
{
    return [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-cfb',
                   'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                   'application/zip', 'application/octet-stream'],
    ];
}

// Checks the uploaded file. Adds messages to $errors and returns the extension when valid.
function validate_upload(?array $file, array &$errors): ?string
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Choose a file to upload.';
        return null;
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $errors[] = 'That file is too large. The limit is 5 MB.';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'The file could not be uploaded. Please try again.';
        return null;
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        $errors[] = 'That file is too large. The limit is 5 MB.';
        return null;
    }

    $allowed = upload_allowed_types();
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        $errors[] = 'Upload a PDF, Word (DOC or DOCX), JPG, or PNG file.';
        return null;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) {
        $errors[] = 'The file does not look like a valid ' . strtoupper($ext) . ' file.';
        return null;
    }

    return $ext;
}

// Saves the file in the uploads folder with a random name.
// Returns the path stored in the database (for example "uploads/ab12.pdf"), or null on failure.
function save_upload(array $file, string $ext): ?string
{
    $dir = dirname(__DIR__, 2) . '/uploads';   // project-root/uploads

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        error_log('CAINTRACK could not create upload folder: ' . $dir);
        return null;
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        error_log('CAINTRACK could not move upload to: ' . $dir . '/' . $stored);
        return null;
    }

    return 'uploads/' . $stored;
}

// Full path on disk for a stored path
function upload_full_path(string $relative): string
{
    return dirname(__DIR__, 2) . '/' . $relative;
}
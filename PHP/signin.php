<?php

// Start PHP session.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/includes/db.php';

$systemName  = 'CAINTRACK';
$error       = '';
$identifier  = '';
$maxAttempts = 5;
$lockSeconds = 300;

// Redirect users according to their role.
function redirectByRole(string $role): void
{
    if ($role === 'dilg') {
        $target = 'dashboard_dilg.php';
    } else {
        $target = 'userDashboard.php';
    }

    header('Location: ' . $target);
    exit;
}

// Redirect if already logged in.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    redirectByRole($_SESSION['role']);
}

// Generate CSRF token if missing.
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Flash message.
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Process login form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $identifier = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $token      = $_POST['csrf'] ?? '';

    $lockedUntil = $_SESSION['locked_until'] ?? 0;

    // Validate CSRF token.
    if (
        empty($_SESSION['csrf']) ||
        empty($token) ||
        !hash_equals($_SESSION['csrf'], $token)
    ) {

        // Generate a fresh token.
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        $error = 'Your session expired. Please refresh the page and try again.';

    } elseif ($lockedUntil > time()) {

        $minutes = (int) ceil(($lockedUntil - time()) / 60);

        $error = "Too many failed attempts. Try again in $minutes minute(s).";

    } elseif ($identifier === '' || $password === '') {

        $error = 'Enter your username or email and your password.';

    } else {

        try {

            // Connect to database.
            $pdo = db();

            // Find existing account.
            $stmt = $pdo->prepare(
                'SELECT id, username, full_name, password_hash, role, barangay_id, is_active
                 FROM users
                 WHERE username = :username OR email = :email
                 LIMIT 1'
            );

            $stmt->execute([
                ':username' => $identifier,
                ':email'    => $identifier
            ]);

            // Fetch account only once.
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify password.
            $hash = $user
                ? $user['password_hash']
                : password_hash('dummy', PASSWORD_DEFAULT);

            $valid = password_verify($password, $hash) && $user !== false;

            // Successful login.
            if ($valid && (int) $user['is_active'] === 1) {

                // Regenerate session ID.
                session_regenerate_id(true);

                // Store account information.
                $_SESSION['user_id']     = (int) $user['id'];
                $_SESSION['username']    = $user['username'];
                $_SESSION['full_name']   = $user['full_name'];
                $_SESSION['role']        = $user['role'];
                $_SESSION['barangay_id'] = $user['barangay_id'];

                // Reset failed attempts.
                unset(
                    $_SESSION['failed_attempts'],
                    $_SESSION['locked_until']
                );

                // Generate new CSRF token.
                $_SESSION['csrf'] = bin2hex(random_bytes(32));

                // Rehash password only if needed.
                if (
                    password_needs_rehash(
                        $user['password_hash'],
                        PASSWORD_DEFAULT
                    )
                ) {

                    $up = $pdo->prepare(
                        'UPDATE users SET password_hash = ? WHERE id = ?'
                    );

                    $up->execute([
                        password_hash($password, PASSWORD_DEFAULT),
                        $user['id']
                    ]);
                }

                // Update last login.
                $log = $pdo->prepare(
                    'UPDATE users SET last_login = NOW() WHERE id = ?'
                );

                $log->execute([$user['id']]);

                // Redirect to dashboard.
                redirectByRole($user['role']);

            }

            // Failed login attempt.
            $_SESSION['failed_attempts'] =
                ($_SESSION['failed_attempts'] ?? 0) + 1;

            // Lock after 5 failed attempts.
            if ($_SESSION['failed_attempts'] >= $maxAttempts) {

                $_SESSION['locked_until'] = time() + $lockSeconds;
                $_SESSION['failed_attempts'] = 0;

            }

            // Display appropriate error.
            if ($valid && (int) $user['is_active'] !== 1) {

                $error = 'This account is waiting for DILG approval or has been disabled. Contact your DILG administrator.';

            } else {

                $error = 'Incorrect username/email or password.';

            }

        } catch (Throwable $e) {

            error_log('CAINTRACK sign-in error: ' . $e->getMessage());

            $error = 'Something went wrong on our side. Please try again later.';

        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Sign in | <?= htmlspecialchars($systemName) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../CSS/style.css">
    <link rel="stylesheet" href="../CSS/login.css">

</head>

<body class="login-page">

<main class="login-wrap">

    <div class="login-card">

        <a class="brand login-brand"
           href="index.php"
           aria-label="Back to <?= htmlspecialchars($systemName) ?> home">

            <img src="../IMAGES/dilglogo.png"
                 alt="DILG seal"
                 class="logo">

            <img src="../IMAGES/caintalogo.png"
                 alt="Municipality of Cainta, Rizal seal"
                 class="logo">

            <span class="brand-name">
                <?= htmlspecialchars($systemName) ?>
            </span>

        </a>

        <h1>Sign in</h1>

        <p class="login-sub">
            Use the account given to you by DILG Cainta.
        </p>

        <?php if ($notice !== ''): ?>

            <p class="form-success" role="status">
                <?= htmlspecialchars($notice) ?>
            </p>

        <?php endif; ?>

        <?php if ($error !== ''): ?>

            <p class="form-error" role="alert">
                <?= htmlspecialchars($error) ?>
            </p>

        <?php endif; ?>

        <form method="post" action="signin.php" novalidate>

            <input
                type="hidden"
                name="csrf"
                value="<?= htmlspecialchars($_SESSION['csrf']) ?>"
            >

            <div class="field">

                <label for="username">Username or email</label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars($identifier) ?>"
                    autocomplete="username"
                    required
                    autofocus
                >

            </div>

            <div class="field">

                <label for="password">Password</label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >

            </div>

            <button type="submit" class="btn btn-dark btn-block">
                Sign in
            </button>

        </form>

        <p class="login-help">

            No account yet?
            <a href="register.php">Create an account</a>.

            <br>

            Forgot your password?
            Contact your DILG administrator.

        </p>

        <p class="login-back">
            <a href="index.php">Back to home page</a>
        </p>

    </div>

</main>

</body>
</html>

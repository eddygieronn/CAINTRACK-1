<?php
session_start();
require __DIR__ . '/includes/db.php';

$systemName = 'CAINTRACK';
$errors     = [];
$old        = ['full_name' => '', 'username' => '', 'email' => '', 'barangay_id' => ''];

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Barangays for the dropdown
try {
    $barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();

    // Make sure the seven barangays of Cainta exist (safe to run repeatedly)
    if (count($barangays) < 7) {
        $caintaBarangays = [
            'San Andres', 'San Isidro', 'San Juan', 'San Roque',
            'Santa Rosa', 'Santo Domingo', 'Santo Niño',
        ];
        $seed = db()->prepare('INSERT IGNORE INTO barangays (name) VALUES (?)');
        foreach ($caintaBarangays as $name) {
            $seed->execute([$name]);
        }
        $barangays = db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
    }
} catch (PDOException $e) {
    error_log('CAINTRACK register error: ' . $e->getMessage());
    $barangays = [];
    $errors[]  = 'Something went wrong on our side. Please try again later.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $old['full_name']   = trim($_POST['full_name'] ?? '');
    $old['username']    = trim($_POST['username'] ?? '');
    $old['email']       = trim($_POST['email'] ?? '');
    $old['barangay_id'] = $_POST['barangay_id'] ?? '';
    $password           = $_POST['password'] ?? '';
    $confirm            = $_POST['confirm_password'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if ($old['full_name'] === '' || mb_strlen($old['full_name']) > 120) {
        $errors[] = 'Enter your full name.';
    }
    if (!preg_match('/^[A-Za-z0-9_.]{4,30}$/', $old['username'])) {
        $errors[] = 'Username must be 4 to 30 characters: letters, numbers, dots, or underscores.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 120) {
        $errors[] = 'Enter a valid email address.';
    }
    $validIds = array_column($barangays, 'id');
    if (!in_array((int) $old['barangay_id'], array_map('intval', $validIds), true)) {
        $errors[] = 'Select your barangay.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        try {
            // Role is always "barangay" and the account starts inactive until
            // DILG approves it. Never take the role from the form.
            $stmt = db()->prepare(
                'INSERT INTO users (username, email, full_name, password_hash, role, barangay_id, is_active)
                 VALUES (?, ?, ?, ?, "barangay", ?, 0)'
            );
            $stmt->execute([
                $old['username'],
                $old['email'],
                $old['full_name'],
                password_hash($password, PASSWORD_DEFAULT),
                (int) $old['barangay_id'],
            ]);

            $_SESSION['flash'] = 'Account created. DILG Cainta must approve it before you can sign in.';
            $_SESSION['csrf']  = bin2hex(random_bytes(32));
            header('Location: signin.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'That username or email is already registered.';
            } else {
                error_log('CAINTRACK register error: ' . $e->getMessage());
                $errors[] = 'Something went wrong on our side. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create an account | <?= $systemName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
    <link rel="stylesheet" href="../CSS/login.css">
    <style>
        /* Barangay dropdown: matches the text inputs */
        .field select {
            width: 100%;
            padding: 0.7rem 2.75rem 0.7rem 0.85rem;
            font: inherit;
            color: var(--ink);
            background-color: var(--white);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath d='M3 6l5 5 5-5' fill='none' stroke='%232A1708' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.9rem center;
            background-size: 1rem;
            border: 2px solid var(--ink);
            border-radius: 6px;
            cursor: pointer;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            transition: background-color 0.15s;
        }
        .field select:hover { background-color: var(--orange-tint); }
        .field select:focus-visible {
            outline: 3px solid var(--orange);
            outline-offset: 2px;
        }
        /* Placeholder looks muted until a barangay is chosen */
        .field select:invalid { color: var(--muted); }
        .field select option { color: var(--ink); padding: 0.5rem; }
        .field select option[value=""] { color: var(--muted); }
    </style>
</head>
<body class="login-page">

<main class="login-wrap">
    <div class="login-card">
        <a class="brand login-brand" href="index.php" aria-label="Back to <?= $systemName ?> home">
            <img src="../IMAGES/dilglogo.png" alt="DILG seal" class="logo">
            <img src="../IMAGES/caintalogo.png" alt="Municipality of Cainta, Rizal seal" class="logo">
            <span class="brand-name"><?= $systemName ?></span>
        </a>

        <h1>Create an account</h1>
        <p class="login-sub">For barangay secretaries and authorized representatives. DILG Cainta approves new accounts.</p>

        <?php if ($errors): ?>
            <div class="form-error" role="alert">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= htmlspecialchars($old['full_name']) ?>"
                       autocomplete="name" required autofocus>
            </div>

            <div class="field">
                <label for="barangay_id">Barangay</label>
                <select id="barangay_id" name="barangay_id" required>
                    <option value="">Select barangay</option>
                    <?php foreach ($barangays as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"
                            <?= (string) $b['id'] === (string) $old['barangay_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($old['username']) ?>"
                       autocomplete="username" required>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($old['email']) ?>"
                       autocomplete="email" required>
            </div>

            <div class="field">
                <label for="password">Password (at least 8 characters)</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm password</label>
                <input type="password" id="confirm_password" name="confirm_password"
                       autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn btn-dark btn-block">Create account</button>
        </form>

        <p class="login-help">Already have an account? <a href="signin.php">Sign in</a></p>
        <p class="login-back"><a href="index.php">Back to home page</a></p>
    </div>
</main>

</body>
</html>
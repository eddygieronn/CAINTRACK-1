<?php
// Shared session and access-control helpers.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function require_login(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        header('Location: signin.php');
        exit;
    }
}

// Allow only the given role; send everyone else to their own dashboard.
function require_role(string $role): void
{
    require_login();

    if ($_SESSION['role'] !== $role) {
        $target = $_SESSION['role'] === 'dilg' ? 'dashboard_dilg.php' : 'userDashboard.php';
        header('Location: ' . $target);
        exit;
    }
}
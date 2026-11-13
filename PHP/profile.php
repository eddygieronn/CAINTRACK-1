<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_role('barangay');

// A barangay account must be linked to a barangay
if (empty($_SESSION['barangay_id'])) {
    header('Location: logout.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$pageError      = '';
$detailErrors   = [];
$passwordErrors = [];
$dbError        = false;
$account        = null;

// Success message from the previous save
$success = $_SESSION['profile_ok'] ?? '';
unset($_SESSION['profile_ok']);

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT u.username, u.email, u.full_name, u.last_login, u.created_at,
                b.name AS barangay
         FROM users u
         LEFT JOIN barangays b ON b.id = u.barangay_id
         WHERE u.id = ?'
    );
    $stmt->execute([$userId]);
    $account = $stmt->fetch();

    // Account no longer exists: sign out
    if (!$account) {
        header('Location: logout.php');
        exit;
    }

    $form = ['full_name' => $account['full_name'], 'email' => $account['email']];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            $pageError = 'Your session expired. Please try again.';

        } elseif ($action === 'update_details') {
            $form['full_name'] = trim($_POST['full_name'] ?? '');
            $form['email']     = trim($_POST['email'] ?? '');

            if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 120) {
                $detailErrors[] = 'Enter your full name (up to 120 characters).';
            }
            if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 120) {
                $detailErrors[] = 'Enter a valid email address.';
            }

            if (!$detailErrors) {
                try {
                    $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
                    $stmt->execute([$form['full_name'], $form['email'], $userId]);

                    $_SESSION['full_name'] = $form['full_name'];
                    $_SESSION['profile_ok'] = 'Your details were updated.';
                    header('Location: profile.php');
                    exit;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $detailErrors[] = 'That email is already registered to another account.';
                    } else {
                        throw $e;
                    }
                }
            }

        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $hash = (string) $stmt->fetchColumn();

            if ($current === '' || !password_verify($current, $hash)) {
                $passwordErrors[] = 'Your current password is incorrect.';
            }
            if (strlen($new) < 8) {
                $passwordErrors[] = 'The new password must be at least 8 characters.';
            }
            if ($new !== $confirm) {
                $passwordErrors[] = 'The new passwords do not match.';
            }
            if ($current !== '' && $new === $current) {
                $passwordErrors[] = 'The new password must be different from the current one.';
            }

            if (!$passwordErrors) {
                $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

                session_regenerate_id(true);
                $_SESSION['profile_ok'] = 'Your password was changed.';
                header('Location: profile.php');
                exit;
            }
        }
    }
} catch (PDOException $e) {
    error_log('CAINTRACK profile error: ' . $e->getMessage());
    $dbError = true;
}

$fullName     = $account['full_name'] ?? ($_SESSION['full_name'] ?? 'Barangay User');
$barangayName = $account['barangay'] ?? '';
$form         = $form ?? ['full_name' => $fullName, 'email' => ''];

// Layout settings, then the shared top of the page
$pageTitle  = 'Profile';
$activePage = 'profile';
$extraCss   = ['profile.css'];
require __DIR__ . '/includes/user_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">BARANGAY WORKSPACE</p>
                <h1>Profile</h1>
                <p>View your account details and keep your sign-in secure.</p>
            </div>
        </div>

        <?php if ($success !== ''): ?>
            <p class="form-success-box" role="status"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <?php if ($pageError !== ''): ?>
            <p class="form-error-box" role="alert"><?= htmlspecialchars($pageError) ?></p>
        <?php endif; ?>

        <?php if ($dbError): ?>
            <p class="form-error-box" role="alert">
                We could not load your profile right now. Please try again later.
            </p>
        <?php elseif ($account): ?>

        <div class="profile-grid">
            <section class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h2>Account details</h2>
                        <p>Your name and email can be changed. Your username and barangay are set by DILG.</p>
                    </div>
                </div>

                <dl class="info-list">
                    <div><dt>Username</dt><dd><?= htmlspecialchars($account['username']) ?></dd></div>
                    <div><dt>Barangay</dt><dd><?= htmlspecialchars($account['barangay'] ?? '—') ?></dd></div>
                    <div><dt>Role</dt><dd>Barangay Secretary / Authorized Representative</dd></div>
                    <div><dt>Member since</dt>
                        <dd><?= htmlspecialchars(date('M j, Y', strtotime($account['created_at']))) ?></dd></div>
                    <div><dt>Last sign-in</dt>
                        <dd><?= $account['last_login']
                            ? htmlspecialchars(date('M j, Y g:i A', strtotime($account['last_login'])))
                            : '—' ?></dd></div>
                </dl>

                <?php if ($detailErrors): ?>
                    <div class="form-error-box" role="alert">
                        <?php foreach ($detailErrors as $e): ?>
                            <div><?= htmlspecialchars($e) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="profile-form" method="post" action="profile.php" novalidate>
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="update_details">

                    <div class="form-field">
                        <label for="full_name">Full name</label>
                        <input type="text" id="full_name" name="full_name" maxlength="120"
                               value="<?= htmlspecialchars($form['full_name']) ?>"
                               autocomplete="name" required>
                    </div>

                    <div class="form-field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" maxlength="120"
                               value="<?= htmlspecialchars($form['email']) ?>"
                               autocomplete="email" required>
                    </div>

                    <button type="submit" class="btn btn-dark">Save details</button>
                </form>
            </section>

            <section class="dashboard-panel">
                <div class="panel-heading">
                    <div>
                        <h2>Change password</h2>
                        <p>Use at least 8 characters.</p>
                    </div>
                </div>

                <?php if ($passwordErrors): ?>
                    <div class="form-error-box" role="alert">
                        <?php foreach ($passwordErrors as $e): ?>
                            <div><?= htmlspecialchars($e) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="profile-form" method="post" action="profile.php" novalidate>
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-field">
                        <label for="current_password">Current password</label>
                        <input type="password" id="current_password" name="current_password"
                               autocomplete="current-password" required>
                    </div>

                    <div class="form-field">
                        <label for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password"
                               autocomplete="new-password" required>
                    </div>

                    <div class="form-field">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn btn-dark">Change password</button>
                </form>
            </section>
        </div>

        <?php endif; ?>

<?php require __DIR__ . '/includes/user_bottom.php'; ?>
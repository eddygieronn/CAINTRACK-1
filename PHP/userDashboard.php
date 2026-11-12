<?php
require _DIR_ . '/includes/auth.php';
require _DIR_ . '/includes/db.php';
require_role('dilg');

$pdo = db();

// Approve or reject a pending barangay account
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $id     = (int) ($_POST['user_id'] ?? 0);
        $action = $_POST['action'] ?? '';

        if ($action === 'approve') {
            $st = $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ? AND role = "barangay" AND is_active = 0');
            $st->execute([$id]);
            $_SESSION['flash'] = 'Account approved.';
        } elseif ($action === 'reject') {
            $st = $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "barangay" AND is_active = 0');
            $st->execute([$id]);
            $_SESSION['flash'] = 'Account request rejected and removed.';
        }
    }
    header('Location: dashboard_dilg.php');
    exit;
}

$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$pending = $pdo->query(
    'SELECT u.id, u.full_name, u.username, u.email, u.created_at, b.name AS barangay
     FROM users u LEFT JOIN barangays b ON b.id = u.barangay_id
     WHERE u.role = "barangay" AND u.is_active = 0
     ORDER BY u.created_at'
)->fetchAll();

$activeCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "barangay" AND is_active = 1')->fetchColumn();
$brgyCount   = (int) $pdo->query('SELECT COUNT(*) FROM barangays')->fetchColumn();

$pageTitle = 'DILG dashboard';
require _DIR_ . '/includes/dash_top.php';
?>
<h1>DILG dashboard</h1>
<p class="dash-sub">Review account requests and monitor all barangays.</p>

<?php if ($notice !== ''): ?>
    <p class="notice" role="status"><?= htmlspecialchars($notice) ?></p>
<?php endif; ?>

<div class="stats">
    <div class="stat"><strong><?= count($pending) ?></strong><span>Pending account requests</span></div>
    <div class="stat"><strong><?= $activeCount ?></strong><span>Active barangay accounts</span></div>
    <div class="stat"><strong><?= $brgyCount ?></strong><span>Barangays</span></div>
</div>

<section class="panel" aria-labelledby="pending-title">
    <h2 id="pending-title">Pending account requests</h2>
    <?php if (!$pending): ?>
        <p class="empty">No accounts are waiting for approval.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Name</th><th>Barangay</th><th>Username</th><th>Email</th><th>Requested</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($pending as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['full_name']) ?></td>
                <td><?= htmlspecialchars($p['barangay'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['username']) ?></td>
                <td><?= htmlspecialchars($p['email']) ?></td>
                <td><?= htmlspecialchars(date('M j, Y', strtotime($p['created_at']))) ?></td>
                <td>
                    <div class="row-actions">
                        <?php foreach (['approve' => ['Approve', 'btn-approve'], 'reject' => ['Reject', 'btn-reject']] as $act => [$label, $cls]): ?>
                        <form method="post" action="dashboard_dilg.php"
                              <?= $act === 'reject' ? 'onsubmit="return confirm(\'Reject and remove this request?\');"' : '' ?>>
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="action" value="<?= $act ?>">
                            <button type="submit" class="btn-sm <?= $cls ?>"><?= $label ?></button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php require _DIR_ . '/includes/dash_bottom.php'; ?>
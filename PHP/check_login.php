<?php
require __DIR__ . '/includes/db.php';

$out = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = trim($_POST['u'] ?? '');
    $pw = $_POST['p'] ?? '';

    $s = db()->prepare(
        'SELECT username, email, password_hash, is_active, role, barangay_id
         FROM users WHERE username = ? OR email = ? LIMIT 1'
    );
    $s->execute([$id, $id]);
    $u = $s->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        $out = 'No account found for: ' . htmlspecialchars($id);
    } else {
        $out = implode("\n", [
            'Account found: ' . $u['username'] . ' / ' . $u['email'],
            'role: ' . $u['role'],
            'is_active: ' . $u['is_active'],
            'barangay_id: ' . var_export($u['barangay_id'], true),
            'hash length: ' . strlen($u['password_hash']) . ' (should be 60)',
            'hash starts with: ' . substr($u['password_hash'], 0, 4) . ' (should be $2y$)',
            'password you typed has ' . strlen($pw) . ' characters',
            'password_verify: ' . (password_verify($pw, $u['password_hash']) ? 'MATCH' : 'NO MATCH'),
        ]);
    }
}
?>
<form method="post">
    <input name="u" placeholder="username or email"><br>
    <input name="p" type="password" placeholder="password"><br>
    <button>Test</button>
</form>
<pre><?= $out ?></pre>
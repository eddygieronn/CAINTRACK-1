<?php
require __DIR__ . '/includes/dbtest.php';
header('Content-Type: text/plain; charset=utf-8');
print_r(db()->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll());
<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_role('dilg');

$fullName = $_SESSION['full_name'] ?? 'DILG User';

// Compliance state -> label, symbol, and style class
$stateMap = [
    'compliant' => ['Compliant',       '✓', 'cell-compliant'],
    'review'    => ['Awaiting review', '◷', 'cell-review'],
    'revision'  => ['For revision',    '↺', 'cell-revision'],
    'overdue'   => ['Overdue',         '!', 'cell-overdue'],
    'missing'   => ['Not submitted',   '–', 'cell-missing'],
];

$view  = ($_GET['view'] ?? 'all') === 'attention' ? 'attention' : 'all';
$today = new DateTimeImmutable('today');

$requirements = [];   // requirement id => details and one cell per barangay
$barangays    = [];   // barangay id => counts
$totals       = ['pairs' => 0, 'compliant' => 0, 'review' => 0, 'action' => 0];
$overallRate  = 0;
$dbError      = false;
$tableMissing = false;

try {
    // Every active requirement against every barangay, with the latest submission
    $rows = db()->query(
        'SELECT r.id AS req_id, r.title, r.document_type, r.deadline,
                b.id AS brgy_id, b.name AS barangay,
                d.id AS doc_id, d.status AS doc_status
         FROM required_documents r
         CROSS JOIN barangays b
         LEFT JOIN documents d ON d.id = (
             SELECT d2.id
             FROM documents d2
             WHERE d2.requirement_id = r.id AND d2.barangay_id = b.id
             ORDER BY d2.submitted_at DESC, d2.id DESC
             LIMIT 1
         )
         WHERE r.is_active = 1
         ORDER BY r.deadline IS NULL, r.deadline, r.title, b.name'
    )->fetchAll();

    foreach ($rows as $r) {
        $rid = (int) $r['req_id'];
        $bid = (int) $r['brgy_id'];

        $deadline = $r['deadline'] ? new DateTimeImmutable($r['deadline']) : null;
        $days     = $deadline ? (int) $today->diff($deadline)->format('%r%a') : null;
        $ds       = $r['doc_status'];

        if ($ds === 'approved') {
            $state = 'compliant';
        } elseif (in_array($ds, ['pending', 'under_review'], true)) {
            $state = 'review';
        } elseif (in_array($ds, ['revision', 'incomplete'], true)) {
            $state = 'revision';
        } elseif ($days !== null && $days < 0) {
            $state = 'overdue';
        } else {
            $state = 'missing';
        }

        if (!isset($requirements[$rid])) {
            $requirements[$rid] = [
                'title'     => $r['title'],
                'type'      => $r['document_type'],
                'deadline'  => $deadline,
                'days'      => $days,
                'cells'     => [],
                'compliant' => 0,
            ];
        }
        if (!isset($barangays[$bid])) {
            $barangays[$bid] = [
                'name' => $r['barangay'], 'total' => 0,
                'compliant' => 0, 'review' => 0, 'revision' => 0, 'overdue' => 0, 'missing' => 0,
            ];
        }

        $requirements[$rid]['cells'][$bid] = ['state' => $state, 'doc_id' => $r['doc_id']];
        if ($state === 'compliant') {
            $requirements[$rid]['compliant']++;
        }

        $barangays[$bid]['total']++;
        $barangays[$bid][$state]++;

        $totals['pairs']++;
        if ($state === 'compliant') {
            $totals['compliant']++;
        } elseif ($state === 'review') {
            $totals['review']++;
        } else {
            $totals['action']++;
        }
    }

    $overallRate = $totals['pairs'] > 0
        ? (int) round($totals['compliant'] / $totals['pairs'] * 100)
        : 0;
} catch (PDOException $e) {
    error_log('CAINTRACK compliance monitoring error: ' . $e->getMessage());
    $dbError      = true;
    $tableMissing = in_array($e->getCode(), ['42S02', '42S22'], true);
}

$brgyCount = count($barangays);

// Rate for each barangay, lowest first so the ones that need help are on top
foreach ($barangays as &$b) {
    $b['rate'] = $b['total'] > 0 ? (int) round($b['compliant'] / $b['total'] * 100) : 0;
}
unset($b);
uasort($barangays, fn($a, $b) => [$a['rate'], $a['name']] <=> [$b['rate'], $b['name']]);

// "Needs attention" view: only requirements that not every barangay has completed
$shown = $view === 'attention'
    ? array_filter($requirements, fn($r) => $r['compliant'] < $brgyCount)
    : $requirements;

// Layout settings, then the shared top of the page
$pageTitle  = 'Compliance Monitoring';
$activePage = 'compliance';
$extraCss   = ['compliance_dilg.css'];
require __DIR__ . '/includes/dash_top.php';
?>
        <div class="welcome-section">
            <div>
                <p class="eyebrow">DILG CAINTA, RIZAL</p>
                <h1>Compliance Monitoring</h1>
                <p>See which barangays have completed each required document.</p>
            </div>
        </div>

        <?php if ($dbError): ?>
            <p class="db-alert" role="alert">
                <?php if ($tableMissing): ?>
                    The required documents tables are not set up yet. Run
                    <strong>schema_update_compliance.sql</strong> in phpMyAdmin, then reload this page.
                <?php else: ?>
                    We could not load the compliance data right now. Please try again later.
                <?php endif; ?>
            </p>
        <?php elseif (!$requirements): ?>
            <section class="dashboard-panel">
                <p class="empty-note">
                    There are no active required documents yet.
                    <a class="action-link" href="requirements_dilg.php">Add the first requirement</a>
                    to start monitoring compliance.
                </p>
            </section>
        <?php else: ?>

        <section class="summary-grid" aria-label="Compliance summary">
            <article class="summary-card">
                <div class="summary-icon">%</div>
                <p>Overall Compliance</p>
                <h2><?= $overallRate ?>%</h2>
                <span><?= count($requirements) ?> requirements, <?= $brgyCount ?> barangays</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">✓</div>
                <p>Compliant</p>
                <h2><?= $totals['compliant'] ?></h2>
                <span>Out of <?= $totals['pairs'] ?> required submissions</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">◷</div>
                <p>Awaiting Review</p>
                <h2><?= $totals['review'] ?></h2>
                <span>Submitted, not yet decided</span>
            </article>
            <article class="summary-card">
                <div class="summary-icon">!</div>
                <p>Needs Action</p>
                <h2><?= $totals['action'] ?></h2>
                <span>Missing, overdue, or for revision</span>
            </article>
        </section>

        <section class="dashboard-panel">
            <div class="panel-heading">
                <div>
                    <h2>Compliance by Barangay</h2>
                    <p>Lowest compliance first, so barangays that need follow-up are on top.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Barangay</th>
                            <th scope="col">Compliant</th>
                            <th scope="col">Awaiting review</th>
                            <th scope="col">Needs action</th>
                            <th scope="col">Overdue</th>
                            <th scope="col">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($barangays as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['name']) ?></td>
                            <td><?= $b['compliant'] ?> of <?= $b['total'] ?></td>
                            <td><?= $b['review'] ?></td>
                            <td><?= $b['revision'] + $b['overdue'] + $b['missing'] ?></td>
                            <td class="<?= $b['overdue'] > 0 ? 'text-alert' : '' ?>"><?= $b['overdue'] ?></td>
                            <td class="rate-cell">
                                <div class="progress-sm" role="progressbar" aria-valuemin="0"
                                     aria-valuemax="100" aria-valuenow="<?= $b['rate'] ?>">
                                    <div class="progress-sm-bar" style="width: <?= $b['rate'] ?>%"></div>
                                </div>
                                <span><?= $b['rate'] ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-panel stack-gap">
            <div class="panel-heading">
                <div>
                    <h2>Requirements by Barangay</h2>
                    <p>Select a cell to open that barangay's latest submission for review.</p>
                </div>
                <form class="view-form" method="get" action="compliance_dilg.php">
                    <label for="view">Show</label>
                    <select id="view" name="view" onchange="this.form.submit()">
                        <option value="all"<?= $view === 'all' ? ' selected' : '' ?>>All requirements</option>
                        <option value="attention"<?= $view === 'attention' ? ' selected' : '' ?>>Needing attention</option>
                    </select>
                    <noscript><button type="submit" class="btn btn-dark">Apply</button></noscript>
                </form>
            </div>

            <ul class="legend" aria-label="Legend">
                <?php foreach ($stateMap as [$label, $symbol, $class]): ?>
                    <li><span class="cell <?= $class ?>" aria-hidden="true"><?= $symbol ?></span> <?= htmlspecialchars($label) ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($shown): ?>
                <div class="table-wrap">
                    <table class="matrix-table">
                        <thead>
                            <tr>
                                <th scope="col" class="req-col">Requirement</th>
                                <?php foreach ($barangays as $bid => $b): ?>
                                    <th scope="col" class="brgy-col"><?= htmlspecialchars($b['name']) ?></th>
                                <?php endforeach; ?>
                                <th scope="col" class="brgy-col">Done</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shown as $req):
                                $note = '';
                                if ($req['days'] !== null) {
                                    if ($req['days'] < 0) {
                                        $n    = abs($req['days']);
                                        $note = $n . ' day' . ($n === 1 ? '' : 's') . ' past due';
                                    } elseif ($req['days'] === 0) {
                                        $note = 'Due today';
                                    } elseif ($req['days'] <= 7) {
                                        $note = 'Due in ' . $req['days'] . ' day' . ($req['days'] === 1 ? '' : 's');
                                    }
                                }
                            ?>
                            <tr>
                                <th scope="row" class="req-col">
                                    <?= htmlspecialchars($req['title']) ?>
                                    <span class="req-meta">
                                        <?= htmlspecialchars($req['type'] ?? 'Any type') ?>
                                        ·
                                        <?= $req['deadline'] ? htmlspecialchars($req['deadline']->format('M j, Y')) : 'No deadline' ?>
                                        <?php if ($note !== ''): ?>
                                            <span class="req-note"><?= htmlspecialchars($note) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </th>
                                <?php foreach ($barangays as $bid => $b):
                                    $cell = $req['cells'][$bid] ?? ['state' => 'missing', 'doc_id' => null];
                                    [$label, $symbol, $class] = $stateMap[$cell['state']];
                                    $tip = $b['name'] . ': ' . $label;
                                ?>
                                <td class="brgy-col">
                                    <?php if (!empty($cell['doc_id'])): ?>
                                        <a class="cell <?= $class ?>" title="<?= htmlspecialchars($tip) ?>"
                                           href="review_submission.php?id=<?= (int) $cell['doc_id'] ?>">
                                            <?= $symbol ?><span class="sr-only"><?= htmlspecialchars($tip) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span class="cell <?= $class ?>" title="<?= htmlspecialchars($tip) ?>">
                                            <?= $symbol ?><span class="sr-only"><?= htmlspecialchars($tip) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                                <td class="brgy-col done-col"><?= $req['compliant'] ?>/<?= $brgyCount ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-note">Every requirement has been completed by all barangays. Nothing needs attention.</p>
            <?php endif; ?>
        </section>

        <?php endif; ?>

<?php require __DIR__ . '/includes/dash_bottom.php'; ?>
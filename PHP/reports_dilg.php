<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require_role('dilg');

$fullName = $_SESSION['full_name'] ?? 'DILG User';

// Submission statuses: label and style class from style.css
$statusMap = [
    'pending'      => ['Pending',      'status-pending'],
    'under_review' => ['Under review', 'status-review'],
    'approved'     => ['Approved',     'status-approved'],
    'incomplete'   => ['Incomplete',   'status-revision'],
    'revision'     => ['For revision', 'status-revision'],
];

// Compliance states: label and style class
$stateMap = [
    'compliant' => ['Compliant',       'status-approved'],
    'review'    => ['Awaiting review', 'status-review'],
    'revision'  => ['For revision',    'status-revision'],
    'overdue'   => ['Overdue',         'status-revision'],
    'missing'   => ['Not submitted',   'status-pending'],
];

$types = ['Report', 'Certification', 'Financial', 'Resolution', 'Ordinance', 'Other'];

// A date from the address bar, or an empty string when it is not valid
$cleanDate = function (?string $v): string {
    $v = trim((string) $v);
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
};

// Stop spreadsheet programs from running text that starts with = + - @ as a formula
$csvSafe = function ($v): string {
    $v = (string) $v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
};

// What the user asked for
$report   = ($_GET['report'] ?? 'submissions') === 'compliance' ? 'compliance' : 'submissions';
$export   = ($_GET['export'] ?? '') === 'csv';
$showAll  = ($_GET['show'] ?? '') === 'all';

$barangayFilter = (int) ($_GET['barangay'] ?? 0);

$status = $_GET['status'] ?? '';
if ($report === 'submissions' && !isset($statusMap[$status])) {
    $status = '';
}
if ($report === 'compliance' && !isset($stateMap[$status])) {
    $status = '';
}

$type = $_GET['type'] ?? '';
if (!in_array($type, $types, true)) {
    $type = '';
}

$from = $cleanDate($_GET['from'] ?? '');
$to   = $cleanDate($_GET['to'] ?? '');
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$dateFmt = $export ? 'Y-m-d H:i' : 'M j, Y g:i A';
$today   = new DateTimeImmutable('today');

$barangays     = [];
$headers       = [];
$rows          = [];     // plain text cells, used for the page and for the CSV
$rowClass      = [];     // style class for the status column of each row
$rowKey        = [];     // status or state key of each row
$statusCol     = 0;
$summary       = [];     // compliance report: totals per barangay
$dbError       = false;
$tableMissing  = false;

try {
    $pdo = db();

    $barangays = $pdo->query('SELECT id, name FROM barangays ORDER BY name')->fetchAll();
    if ($barangayFilter && !in_array($barangayFilter, array_map('intval', array_column($barangays, 'id')), true)) {
        $barangayFilter = 0;
    }

    if ($report === 'submissions') {
        // Optional columns from the later updates
        $cols        = array_column($pdo->query('SHOW COLUMNS FROM documents')->fetchAll(), 'Field');
        $hasReq      = in_array('requirement_id', $cols, true);
        $hasReview   = in_array('reviewed_by', $cols, true);
        $hasVersions = (bool) $pdo->query("SHOW TABLES LIKE 'document_versions'")->fetch();

        $select = 'b.name AS barangay, d.title, d.document_type, d.status, d.submitted_at, d.updated_at,
                   u.full_name AS submitter';
        $joins  = 'JOIN barangays b ON b.id = d.barangay_id JOIN users u ON u.id = d.submitted_by';

        $headers = ['Barangay', 'Document', 'Type'];
        if ($hasReq) {
            $select .= ', r.title AS requirement';
            $joins  .= ' LEFT JOIN required_documents r ON r.id = d.requirement_id';
            $headers[] = 'Required document';
        }
        $headers = array_merge($headers, ['Status', 'Submitted', 'Last updated', 'Submitted by']);
        if ($hasVersions) {
            $select .= ', (SELECT COUNT(*) FROM document_versions v WHERE v.document_id = d.id) AS versions';
            $headers[] = 'Versions';
        }
        if ($hasReview) {
            $select .= ', ru.full_name AS reviewer, d.reviewed_at';
            $joins  .= ' LEFT JOIN users ru ON ru.id = d.reviewed_by';
            $headers[] = 'Reviewed by';
            $headers[] = 'Reviewed on';
        }
        $statusCol = $hasReq ? 4 : 3;

        $where  = '1 = 1';
        $params = [];
        if ($barangayFilter) {
            $where .= ' AND d.barangay_id = ?';
            $params[] = $barangayFilter;
        }
        if ($status !== '') {
            $where .= ' AND d.status = ?';
            $params[] = $status;
        }
        if ($type !== '') {
            $where .= ' AND d.document_type = ?';
            $params[] = $type;
        }
        if ($from !== '') {
            $where .= ' AND DATE(d.submitted_at) >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $where .= ' AND DATE(d.submitted_at) <= ?';
            $params[] = $to;
        }

        $stmt = $pdo->prepare(
            "SELECT $select FROM documents d $joins WHERE $where ORDER BY d.submitted_at DESC, d.id DESC"
        );
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $d) {
            $row = [$d['barangay'], $d['title'], $d['document_type'] ?? ''];
            if ($hasReq) {
                $row[] = $d['requirement'] ?? '';
            }
            $row[] = $statusMap[$d['status']][0] ?? $d['status'];
            $row[] = date($dateFmt, strtotime($d['submitted_at']));
            $row[] = date($dateFmt, strtotime($d['updated_at']));
            $row[] = $d['submitter'];
            if ($hasVersions) {
                $row[] = (string) $d['versions'];
            }
            if ($hasReview) {
                $row[] = $d['reviewer'] ?? '';
                $row[] = $d['reviewed_at'] ? date($dateFmt, strtotime($d['reviewed_at'])) : '';
            }

            $rows[]     = $row;
            $rowClass[] = $statusMap[$d['status']][1] ?? 'status-pending';
            $rowKey[]   = $d['status'];
        }
    } else {
        // Compliance: every active requirement against every barangay
        $headers   = ['Barangay', 'Requirement', 'Type', 'Deadline', 'Status', 'Latest document',
                      'Submitted on', 'Days overdue'];
        $statusCol = 4;

        $where  = 'r.is_active = 1';
        $params = [];
        if ($barangayFilter) {
            $where .= ' AND b.id = ?';
            $params[] = $barangayFilter;
        }

        $stmt = $pdo->prepare(
            "SELECT b.id AS brgy_id, b.name AS barangay, r.title, r.document_type, r.deadline,
                    d.title AS doc_title, d.status AS doc_status, d.submitted_at
             FROM required_documents r
             CROSS JOIN barangays b
             LEFT JOIN documents d ON d.id = (
                 SELECT d2.id FROM documents d2
                 WHERE d2.requirement_id = r.id AND d2.barangay_id = b.id
                 ORDER BY d2.submitted_at DESC, d2.id DESC LIMIT 1
             )
             WHERE $where
             ORDER BY b.name, r.deadline IS NULL, r.deadline, r.title"
        );
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $r) {
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

            // Totals per barangay count every state, before the status filter
            $bid = (int) $r['brgy_id'];
            if (!isset($summary[$bid])) {
                $summary[$bid] = ['name' => $r['barangay'], 'total' => 0, 'compliant' => 0,
                                  'review' => 0, 'revision' => 0, 'overdue' => 0, 'missing' => 0];
            }
            $summary[$bid]['total']++;
            $summary[$bid][$state]++;

            if ($status !== '' && $status !== $state) {
                continue;
            }

            $rows[] = [
                $r['barangay'],
                $r['title'],
                $r['document_type'] ?? '',
                $deadline ? $deadline->format($export ? 'Y-m-d' : 'M j, Y') : 'No deadline',
                $stateMap[$state][0],
                $r['doc_title'] ?? '',
                $r['submitted_at'] ? date($dateFmt, strtotime($r['submitted_at'])) : '',
                ($state === 'overdue' && $days !== null) ? (string) abs($days) : '',
            ];
            $rowClass[] = $stateMap[$state][1];
            $rowKey[]   = $state;
        }
    }
} catch (PDOException $e) {
    error_log('CAINTRACK reports error: ' . $e->getMessage());
    $dbError      = true;
    $tableMissing = in_array($e->getCode(), ['42S02', '42S22'], true);
}

// Download as a CSV file (opens in Excel)
if ($export && !$dbError) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="caintrack_' . $report . '_' . date('Ymd') . '.csv"');
    header('X-Content-Type-Options: nosniff');

    echo "\xEF\xBB\xBF";   // lets Excel read letters like ñ correctly
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map($csvSafe, $row), ',', '"', '');
    }
    fclose($out);
    exit;
}

// Compliance rates per barangay for the summary table
foreach ($summary as &$s) {
    $s['rate'] = $s['total'] > 0 ? (int) round($s['compliant'] / $s['total'] * 100) : 0;
}
unset($s);

// Counts by status for the summary chips
$counts = array_count_values($rowKey);
$total  = count($rows);

// What is shown on the page (the CSV always has every row)
$previewLimit = 50;
$visible      = $showAll ? $rows : array_slice($rows, 0, $previewLimit);

// Links that keep the current filters
$current = array_filter([
    'report'   => $report,
    'barangay' => $barangayFilter ?: null,
    'status'   => $status,
    'type'     => $report === 'submissions' ? $type : '',
    'from'     => $report === 'submissions' ? $from : '',
    'to'       => $report === 'submissions' ? $to : '',
], fn($v) => $v !== null && $v !== '');

$csvUrl     = 'reports_dilg.php?' . http_build_query($current + ['export' => 'csv']);
$showAllUrl = 'reports_dilg.php?' . http_build_query($current + ['show' => 'all']);

// A short description of the filters, for the printed report
$barangayName = '';
foreach ($barangays as $b) {
    if ((int) $b['id'] === $barangayFilter) {
        $barangayName = $b['name'];
    }
}
$filterText = [];
$filterText[] = 'Barangay: ' . ($barangayName !== '' ? $barangayName : 'All');
if ($status !== '') {
    $filterText[] = 'Status: ' . ($report === 'submissions' ? $statusMap[$status][0] : $stateMap[$status][0]);
}
if ($report === 'submissions') {
    if ($type !== '') {
        $filterText[] = 'Type: ' . $type;
    }
    if ($from !== '' || $to !== '') {
        $filterText[] = 'Submitted: ' . ($from !== '' ? $from : 'any time') . ' to ' . ($to !== '' ? $to : 'today');
    }
}

$reportTitle = $report === 'submissions' ? 'Document Submissions Report' : 'Compliance Report';

// Layout settings, then the shared top of the page
$pageTitle  = 'Reports';
$activePage = 'reports';
$extraCss   = ['reports.css'];
require __DIR__ . '/includes/dash_top.php';
?>
        <div class="welcome-section no-print">
            <div>
                <p class="eyebrow">DILG CAINTA, RIZAL</p>
                <h1>Reports</h1>
                <p>Generate a report, then download it as a CSV file or print it.</p>
            </div>
        </div>

        <?php if ($dbError): ?>
            <p class="db-alert" role="alert">
                <?php if ($tableMissing): ?>
                    Some tables are not set up yet. Run <strong>schema_update_compliance.sql</strong>
                    in phpMyAdmin, then reload this page.
                <?php else: ?>
                    We could not generate the report right now. Please try again later.
                <?php endif; ?>
            </p>
        <?php else: ?>

        <nav class="report-tabs no-print" aria-label="Report type">
            <a href="reports_dilg.php?report=submissions" class="<?= $report === 'submissions' ? 'active' : '' ?>">
                Document submissions
            </a>
            <a href="reports_dilg.php?report=compliance" class="<?= $report === 'compliance' ? 'active' : '' ?>">
                Compliance
            </a>
        </nav>

        <section class="dashboard-panel report-controls no-print">
            <form class="filter-bar" method="get" action="reports_dilg.php">
                <input type="hidden" name="report" value="<?= $report ?>">

                <div class="filter-field">
                    <label for="barangay">Barangay</label>
                    <select id="barangay" name="barangay">
                        <option value="">All barangays</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === $barangayFilter ? ' selected' : '' ?>>
                                <?= htmlspecialchars($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach (($report === 'submissions' ? $statusMap : $stateMap) as $key => [$label]): ?>
                            <option value="<?= $key ?>"<?= $key === $status ? ' selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($report === 'submissions'): ?>
                <div class="filter-field">
                    <label for="type">Document type</label>
                    <select id="type" name="type">
                        <option value="">All types</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>"<?= $t === $type ? ' selected' : '' ?>>
                                <?= htmlspecialchars($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="from">Submitted from</label>
                    <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
                </div>

                <div class="filter-field">
                    <label for="to">Submitted to</label>
                    <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-dark">Generate</button>
                <a class="btn btn-outline" href="reports_dilg.php?report=<?= $report ?>">Reset</a>
            </form>
        </section>

        <section class="dashboard-panel stack-gap report-output">
            <div class="print-only report-print-head">
                <strong>CAINTRACK · DILG Cainta, Rizal</strong>
            </div>

            <div class="panel-heading">
                <div>
                    <h2><?= htmlspecialchars($reportTitle) ?></h2>
                    <p><?= htmlspecialchars(implode(' · ', $filterText)) ?></p>
                    <p class="generated">Generated <?= htmlspecialchars(date('M j, Y g:i A')) ?> by <?= htmlspecialchars($fullName) ?></p>
                </div>
                <div class="report-actions no-print">
                    <a class="btn btn-dark" href="<?= htmlspecialchars($csvUrl) ?>">Download CSV</a>
                    <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
                </div>
            </div>

            <?php if ($report === 'submissions'): ?>
                <ul class="chips">
                    <li><strong><?= $total ?></strong> total</li>
                    <?php foreach ($statusMap as $key => [$label, $class]): ?>
                        <?php if (!empty($counts[$key])): ?>
                            <li><span class="status <?= $class ?>"><?= htmlspecialchars($label) ?></span> <?= (int) $counts[$key] ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            <?php elseif ($summary): ?>
                <div class="table-wrap summary-table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Barangay</th>
                                <th scope="col">Compliant</th>
                                <th scope="col">Awaiting review</th>
                                <th scope="col">For revision</th>
                                <th scope="col">Overdue</th>
                                <th scope="col">Not submitted</th>
                                <th scope="col">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['name']) ?></td>
                                <td><?= $s['compliant'] ?> of <?= $s['total'] ?></td>
                                <td><?= $s['review'] ?></td>
                                <td><?= $s['revision'] ?></td>
                                <td><?= $s['overdue'] ?></td>
                                <td><?= $s['missing'] ?></td>
                                <td><strong><?= $s['rate'] ?>%</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <h3 class="detail-heading">Details</h3>
            <?php endif; ?>

            <?php if ($rows): ?>
                <div class="table-wrap">
                    <table class="data-table report-table">
                        <thead>
                            <tr>
                                <?php foreach ($headers as $h): ?>
                                    <th scope="col"><?= htmlspecialchars($h) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visible as $i => $row): ?>
                            <tr>
                                <?php foreach ($row as $c => $cell): ?>
                                    <?php if ($c === $statusCol): ?>
                                        <td><span class="status <?= $rowClass[$i] ?>"><?= htmlspecialchars($cell) ?></span></td>
                                    <?php else: ?>
                                        <td><?= htmlspecialchars($cell) ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!$showAll && $total > $previewLimit): ?>
                    <p class="result-count no-print">
                        Showing the first <?= $previewLimit ?> of <?= $total ?> rows.
                        <a class="action-link" href="<?= htmlspecialchars($showAllUrl) ?>">Show all rows</a>
                        or download the CSV file for the full report.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="empty-note">No records match these filters.</p>
            <?php endif; ?>
        </section>

        <?php endif; ?>

<?php require __DIR__ . '/includes/dash_bottom.php'; ?>
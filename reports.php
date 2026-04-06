<?php
include 'db.php';
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$currentUsername = $_SESSION['username'];

$usageLast30 = 0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(used_quantity), 0) FROM usage_log WHERE used_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$stmt->execute();
$stmt->bind_result($usageLast30);
$stmt->fetch();
$stmt->close();

$inventoryValue = 0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(quantity * unit_price), 0) FROM stock");
$stmt->execute();
$stmt->bind_result($inventoryValue);
$stmt->fetch();
$stmt->close();

$expiringSoon = 0;
$stmt = $conn->prepare("SELECT COUNT(*) FROM stock WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)");
$stmt->execute();
$stmt->bind_result($expiringSoon);
$stmt->fetch();
$stmt->close();

$trendData = [];
$trendResult = $conn->query("SELECT s.category, COALESCE(SUM(u.used_quantity),0) AS used_total
                             FROM usage_log u
                             JOIN stock s ON u.stock_id = s.id
                             WHERE u.used_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                             GROUP BY s.category
                             ORDER BY used_total DESC
                             LIMIT 3");
if ($trendResult) {
    while ($row = $trendResult->fetch_assoc()) {
        $trendData[] = $row;
    }
}
$maxUsed = 0;
foreach ($trendData as $row) {
    $maxUsed = max($maxUsed, (int)$row['used_total']);
}
if ($maxUsed === 0) {
    $maxUsed = 1;
}

$usageRows = [];
$usageResult = $conn->query("SELECT s.name, u.used_quantity, u.used_by, u.used_at
                             FROM usage_log u
                             JOIN stock s ON u.stock_id = s.id
                             ORDER BY u.used_at DESC
                             LIMIT 5");
if ($usageResult) {
    while ($row = $usageResult->fetch_assoc()) {
        $usageRows[] = $row;
    }
}

$orderRows = [];
if ($isAdmin) {
    $orderResult = $conn->query("SELECT order_number, supplier_name, requested_by, total_items, total_units, status, follow_up_date, created_at, received_by, received_at
                                 FROM purchase_orders
                                 ORDER BY created_at DESC
                                 LIMIT 10");
    if ($orderResult) {
        while ($row = $orderResult->fetch_assoc()) {
            $orderRows[] = $row;
        }
    }
} else {
    $orderStmt = $conn->prepare("SELECT order_number, supplier_name, requested_by, total_items, total_units, status, follow_up_date, created_at, received_by, received_at
                                 FROM purchase_orders
                                 WHERE requested_by = ?
                                 ORDER BY created_at DESC
                                 LIMIT 10");
    $orderStmt->bind_param("s", $currentUsername);
    $orderStmt->execute();
    $orderResult = $orderStmt->get_result();
    if ($orderResult) {
        while ($row = $orderResult->fetch_assoc()) {
            $orderRows[] = $row;
        }
    }
    $orderStmt->close();
}

$supplierRows = [];
if ($isAdmin) {
    $supplierHasContact = clinic_column_exists($conn, 'suppliers', 'contact');
    $supplierSelect = $supplierHasContact
        ? "name, contact, created_at"
        : "name, NULL AS contact, created_at";
    $supplierResult = $conn->query("SELECT {$supplierSelect} FROM suppliers ORDER BY created_at DESC LIMIT 10");
    if ($supplierResult) {
        while ($row = $supplierResult->fetch_assoc()) {
            $supplierRows[] = $row;
        }
    }
}

function report_pdf_escape(string $text): string
{
    $text = str_replace(["\\", "(", ")", "\r"], ["\\\\", "\\(", "\\)", ""], $text);
    $text = str_replace("\n", "\\n", $text);
    return $text;
}

function report_pdf_encode(string $text): string
{
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $text;
}

function report_pdf_line(array &$lines, float $y, string $text, int $size = 11, string $font = 'F1'): void
{
    $lines[] = [
        'y' => $y,
        'text' => report_pdf_encode($text),
        'size' => $size,
        'font' => $font,
    ];
}

function report_pdf_page_content(array $lines): string
{
    $content = "BT\n";
    foreach ($lines as $line) {
        $content .= sprintf("1 0 0 1 72 %.2f Tm /%s %d Tf (%s) Tj\n", $line['y'], $line['font'], $line['size'], report_pdf_escape($line['text']));
    }
    $content .= "ET";
    return $content;
}

function generate_report_pdf(array $summary, array $trendData, array $usageRows, array $orderRows, array $supplierRows, bool $isAdmin): void
{
    $pages = [];
    $lines = [];

    $y = 770;
    report_pdf_line($lines, $y, 'City Clinic Inventory Report', 18, 'F2');
    $y -= 26;
    report_pdf_line($lines, $y, 'Generated: ' . date('F j, Y g:i A'), 10);
    $y -= 20;
    report_pdf_line($lines, $y, 'Summary', 14, 'F2');
    $y -= 18;
    report_pdf_line($lines, $y, 'Items Used This Month: ' . number_format((float)$summary['usageLast30']), 11);
    $y -= 15;
    report_pdf_line($lines, $y, 'Inventory Value: KSh ' . number_format((float)$summary['inventoryValue'], 2), 11);
    $y -= 15;
    report_pdf_line($lines, $y, 'Expiring Soon: ' . number_format((float)$summary['expiringSoon']), 11);
    $y -= 22;
    report_pdf_line($lines, $y, 'Usage Trends', 14, 'F2');
    $y -= 18;

    if (count($trendData) === 0) {
        report_pdf_line($lines, $y, '- No usage data yet', 11);
        $y -= 15;
    } else {
        foreach ($trendData as $row) {
            report_pdf_line($lines, $y, '- ' . ($row['category'] ?: 'Uncategorized') . ': ' . (int)$row['used_total'] . ' used', 11);
            $y -= 15;
        }
    }

    $y -= 10;
    report_pdf_line($lines, $y, 'Recent Usage', 14, 'F2');
    $y -= 18;

    if (count($usageRows) === 0) {
        report_pdf_line($lines, $y, '- No usage recorded yet', 11);
    } else {
        foreach ($usageRows as $row) {
            $line = '- ' . $row['name'] . ' | Qty: ' . (int)$row['used_quantity'] . ' | By: ' . $row['used_by'] . ' | ' . date('M j, Y', strtotime($row['used_at']));
            report_pdf_line($lines, $y, $line, 10);
            $y -= 14;
            if ($y < 90) {
                $pages[] = $lines;
                $lines = [];
                $y = 770;
            }
        }
    }

    $y -= 10;
    report_pdf_line($lines, $y, $isAdmin ? 'System Orders' : 'My Orders', 14, 'F2');
    $y -= 18;
    if (count($orderRows) === 0) {
        report_pdf_line($lines, $y, '- No orders found', 11);
        $y -= 15;
    } else {
        foreach ($orderRows as $row) {
            $receivedText = !empty($row['received_at'])
                ? ' | Received: ' . date('M j, Y g:i A', strtotime($row['received_at'])) . (!empty($row['received_by']) ? ' by ' . $row['received_by'] : '')
                : '';
            $line = '- ' . $row['order_number'] . ' | ' . $row['supplier_name'] . ' | By: ' . $row['requested_by'] . ' | Units: ' . (int)$row['total_units'] . ' | ' . date('M j, Y g:i A', strtotime($row['created_at'])) . $receivedText;
            report_pdf_line($lines, $y, $line, 9);
            $y -= 14;
            if ($y < 90) {
                $pages[] = $lines;
                $lines = [];
                $y = 770;
            }
        }
    }

    if ($isAdmin) {
        $y -= 8;
        report_pdf_line($lines, $y, 'Recent Supplier Activity', 14, 'F2');
        $y -= 18;
        if (count($supplierRows) === 0) {
            report_pdf_line($lines, $y, '- No supplier records found', 11);
        } else {
            foreach ($supplierRows as $row) {
                $line = '- ' . $row['name'] . ' | ' . ($row['contact'] ?: 'No contact') . ' | ' . date('M j, Y', strtotime($row['created_at']));
                report_pdf_line($lines, $y, $line, 9);
                $y -= 14;
                if ($y < 90) {
                    $pages[] = $lines;
                    $lines = [];
                    $y = 770;
                }
            }
        }
    }

    $pages[] = $lines;
    if (count($pages) === 0) {
        $pages[] = [];
    }

    $objects = [];
    $pageObjects = [];
    $pageRefs = [];
    $objectNumber = 5;

    foreach ($pages as $pageLines) {
        $content = report_pdf_page_content($pageLines);
        $pageRefs[] = $objectNumber . ' 0 R';
        $pageObjects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . ($objectNumber + 1) . " 0 R >>";
        $objects[] = $pageObjects[count($pageObjects) - 1];
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";
        $objectNumber += 2;
    }

    $objects = array_merge(
        [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [" . implode(' ', $pageRefs) . "] /Count " . count($pageRefs) . " >>",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>",
        ],
        $objects
    );

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefPos}\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="city-clinic-report.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
    generate_report_pdf([
        'usageLast30' => $usageLast30,
        'inventoryValue' => $inventoryValue,
        'expiringSoon' => $expiringSoon,
    ], $trendData, $usageRows, $orderRows, $supplierRows, $isAdmin);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reports | City Clinic</title>
<link rel="stylesheet" href="ui.css">
</head>

<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-badge"></span>
      City Clinic
    </div>
    <div class="role-pill">Role: <?= ucfirst($_SESSION['role']) ?></div>

    <nav class="nav">
      <a class="nav-item" href="dashboard.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 12l9-9 9 9"></path>
          <path d="M9 21V9h6v12"></path>
        </svg>
        Dashboard
      </a>

      <?php if($_SESSION['role'] != 'pharmacist'): ?>
        <a class="nav-item" href="record stock.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 5v14"></path>
            <path d="M5 12h14"></path>
          </svg>
          Record Stock
        </a>
      <?php endif; ?>

      <a class="nav-item" href="view stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 4h18v6H3z"></path>
          <path d="M3 14h18v6H3z"></path>
        </svg>
        View Stock
      </a>

      <a class="nav-item" href="order stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M6 6h15l-2 9H8L6 6z"></path>
          <path d="M6 6l-2-3H1"></path>
          <circle cx="9" cy="20" r="1.5"></circle>
          <circle cx="18" cy="20" r="1.5"></circle>
        </svg>
        Order Stock
      </a>

      <a class="nav-item" href="receive stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 3v18"></path>
          <path d="M8 7l4-4 4 4"></path>
          <path d="M8 17l4 4 4-4"></path>
        </svg>
        Receive Stock
      </a>

      <a class="nav-item active" href="reports.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 19h16"></path>
          <path d="M6 16V8"></path>
          <path d="M12 16V5"></path>
          <path d="M18 16v-6"></path>
        </svg>
        Reports
      </a>

      <?php if($_SESSION['role'] == 'admin'): ?>
        <a class="nav-item" href="user management.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M16 11a4 4 0 1 0-8 0"></path>
            <path d="M12 15c-4 0-7 2-7 4v2h14v-2c0-2-3-4-7-4z"></path>
          </svg>
          User Management
        </a>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <a class="logout" href="logout.php" onclick="return confirm('Do you want to sign out now?')">Logout</a>
    </div>
  </aside>

  <main class="main">
    <header class="page-header">
      <div>
        <h1>Reports</h1>
        <p>Track trends, costs, and movement across the inventory.</p>
      </div>
      <div class="header-actions">
        <a class="chip no-print" href="reports.php?download=pdf">Generate Report</a>
        <a class="chip no-print" href="#" onclick="window.print(); return false;">Print View</a>
        <div class="chip">Monthly snapshot</div>
        <div class="chip"><?= date("F j, Y") ?></div>
      </div>
    </header>

    <section class="stats">
      <div class="stat-card">
        <div class="stat-label">Items Used This Month</div>
        <div class="stat-value"><?= number_format($usageLast30) ?></div>
        <div class="stat-accent">Last 30 days</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Inventory Value</div>
        <div class="stat-value">ksh<?= number_format($inventoryValue, 2) ?></div>
        <div class="stat-accent">Current stock estimate</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Expiring Soon</div>
        <div class="stat-value"><?= number_format($expiringSoon) ?></div>
        <div class="stat-accent">Next 45 days</div>
      </div>
      <div class="stat-card">
        <div class="stat-label"><?= $isAdmin ? 'System Orders' : 'My Orders' ?></div>
        <div class="stat-value"><?= number_format(count($orderRows)) ?></div>
        <div class="stat-accent"><?= $isAdmin ? 'All recorded orders' : 'Orders created by you' ?></div>
      </div>
    </section>

    <section class="content-grid">
      <div class="panel">
        <h3>Usage Trends</h3>
        <div class="mini-list">
          <?php if (count($trendData) === 0): ?>
            <div class="mini-row">
              <strong>No usage data yet</strong>
              <span>Record usage to see trends</span>
            </div>
          <?php else: ?>
            <?php foreach ($trendData as $row): ?>
              <?php $percent = (int)round(((int)$row['used_total'] / $maxUsed) * 100); ?>
              <div>
                <div class="mini-row">
                  <strong><?= htmlspecialchars($row['category'] ?: 'Uncategorized') ?></strong>
                  <span><?= (int)$row['used_total'] ?> used</span>
                </div>
                <div class="progress"><div style="width:<?= $percent ?>%"></div></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <h3>Recent Usage</h3>
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th>Quantity</th>
              <th>Used By</th>
              <th>Used At</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($usageRows) === 0): ?>
              <tr>
                <td colspan="4">No usage recorded yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($usageRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['name']) ?></td>
                  <td><?= (int)$row['used_quantity'] ?></td>
                  <td><?= htmlspecialchars($row['used_by']) ?></td>
                  <td><?= date("M j, Y", strtotime($row['used_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel" style="margin-top:22px;">
      <h3><?= $isAdmin ? 'System Orders' : 'My Orders' ?></h3>
      <table>
        <thead>
          <tr>
            <th>Order</th>
            <th>Supplier</th>
            <th>Requested By</th>
            <th>Total Units</th>
            <th>Created At</th>
            <th>Follow-up</th>
            <th>Received By</th>
            <th>Received At</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($orderRows) === 0): ?>
            <tr>
              <td colspan="8">No orders recorded yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($orderRows as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['order_number']) ?></td>
                <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                <td><?= htmlspecialchars($row['requested_by']) ?></td>
                <td><?= (int)$row['total_units'] ?></td>
                <td><?= !empty($row['created_at']) ? date("M j, Y g:i A", strtotime($row['created_at'])) : '-' ?></td>
                <td><?= !empty($row['follow_up_date']) ? date("M j, Y", strtotime($row['follow_up_date'])) : '-' ?></td>
                <td><?= htmlspecialchars($row['received_by'] ?? '-') ?></td>
                <td><?= !empty($row['received_at']) ? date("M j, Y g:i A", strtotime($row['received_at'])) : '-' ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

    <?php if ($isAdmin): ?>
      <section class="panel" style="margin-top:22px;">
        <h3>Supplier Activity</h3>
        <table>
          <thead>
            <tr>
              <th>Supplier</th>
              <th>Contact</th>
              <th>Created At</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($supplierRows) === 0): ?>
              <tr>
                <td colspan="3">No supplier records found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($supplierRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['name']) ?></td>
                  <td><?= htmlspecialchars($row['contact'] ?? '-') ?></td>
                  <td><?= !empty($row['created_at']) ? date("M j, Y g:i A", strtotime($row['created_at'])) : '-' ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>
  </main>
</div>
</body>
<script>
setInterval(function () {
  window.location.reload();
}, 30000);
</script>
</html>

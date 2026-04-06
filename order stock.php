<?php
include 'db.php';
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if (!in_array(($_SESSION['role'] ?? ''), ['admin', 'pharmacist'], true)) {
    header("Location: dashboard.php");
    exit;
}

$canManageSuppliers = ($_SESSION['role'] ?? '') === 'pharmacist';

$suppliers = [];
$supplierResult = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
if ($supplierResult) {
    while ($row = $supplierResult->fetch_assoc()) {
        $suppliers[] = $row;
    }
}
$supplierMap = [];
foreach ($suppliers as $supplierRow) {
    $supplierMap[(int)$supplierRow['id']] = $supplierRow['name'];
}

$lowItems = [];
$itemsResult = $conn->query("SELECT id, name, quantity FROM stock WHERE quantity <= 15 ORDER BY quantity ASC, name ASC");
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $lowItems[] = $row;
    }
}

$selectedSupplierId = (int)($_POST['supplier'] ?? 0);
$selectedSupplier = $supplierMap[$selectedSupplierId] ?? '';
$notes = trim($_POST['notes'] ?? '');
$orderedItems = [];
$totalRequested = 0;
$followUpDate = date('Y-m-d', strtotime('+7 days'));
$orderNumber = 'PO-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
$saveMessage = '';
$orderSaved = false;
$savedOrderId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($lowItems as $item) {
        $field = 'order_qty_' . (int)$item['id'];
        $qty = (int)($_POST[$field] ?? 0);
        if ($qty > 0) {
            $orderedItems[] = [
                'stock_id' => (int)$item['id'],
                'name' => $item['name'],
                'current' => (int)$item['quantity'],
                'order_qty' => $qty,
                'line_status' => 'ordered',
            ];
            $totalRequested += $qty;
        }
    }

    if ($selectedSupplierId <= 0) {
        $saveMessage = 'Please select a supplier before generating the receipt.';
    } elseif (count($orderedItems) === 0) {
        $saveMessage = 'Please enter at least one order quantity.';
    } elseif ($selectedSupplier === '') {
        $saveMessage = 'The selected supplier was not found.';
    } else {
        try {
            $conn->begin_transaction();

            $totalItems = count($orderedItems);
            $requestedBy = $_SESSION['username'];
            $status = 'ordered';
            $notificationStatus = 'pending';

            $orderStmt = $conn->prepare("
                INSERT INTO purchase_orders
                (order_number, supplier_id, supplier_name, requested_by, total_items, total_units, notes, status, follow_up_date, notification_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $orderStmt->bind_param(
                "sissiissss",
                $orderNumber,
                $selectedSupplierId,
                $selectedSupplier,
                $requestedBy,
                $totalItems,
                $totalRequested,
                $notes,
                $status,
                $followUpDate,
                $notificationStatus
            );
            $orderStmt->execute();
            $savedOrderId = (int)$conn->insert_id;
            $orderStmt->close();

            $itemStmt = $conn->prepare("
                INSERT INTO purchase_order_items
                (purchase_order_id, stock_id, item_name, current_quantity, order_quantity, line_status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($orderedItems as $row) {
                $lineStatus = 'ordered';
                $itemStmt->bind_param(
                    "iisiis",
                    $savedOrderId,
                    $row['stock_id'],
                    $row['name'],
                    $row['current'],
                    $row['order_qty'],
                    $lineStatus
                );
                $itemStmt->execute();
            }
            $itemStmt->close();

            $followupNotes = 'Review stock after delivery and notify supplier if any item remains below threshold.';
            $followStmt = $conn->prepare("
                INSERT INTO order_followups
                (purchase_order_id, supplier_id, supplier_name, scheduled_for, status, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $followStatus = 'pending';
            $followStmt->bind_param(
                "iissss",
                $savedOrderId,
                $selectedSupplierId,
                $selectedSupplier,
                $followUpDate,
                $followStatus,
                $followupNotes
            );
            $followStmt->execute();
            $followStmt->close();

            $conn->commit();
            $orderSaved = true;
            $saveMessage = 'Order saved. Receipt generated and follow-up scheduled for ' . date('M j, Y', strtotime($followUpDate)) . '.';
        } catch (Throwable $e) {
            $conn->rollback();
            $saveMessage = 'Could not save the order right now. Please try again. Error: ' . $e->getMessage();
        }
    }
}

$recentOrders = [];
$recentResult = $conn->query("
    SELECT po.order_number, po.supplier_name, po.total_items, po.total_units, po.status, po.follow_up_date, po.notification_status, po.created_at
    FROM purchase_orders po
    ORDER BY po.created_at DESC
    LIMIT 8
");
if ($recentResult) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentOrders[] = $row;
    }
}

$recentFollowups = [];
$followupResult = $conn->query("
    SELECT supplier_name, scheduled_for, status, created_at
    FROM order_followups
    ORDER BY created_at DESC
    LIMIT 5
");
if ($followupResult) {
    while ($row = $followupResult->fetch_assoc()) {
        $recentFollowups[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Order Stock | City Clinic</title>
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
      <a class="nav-item active" href="order stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M6 6h15l-2 9H8L6 6z"></path>
          <path d="M6 6l-2-3H1"></path>
          <circle cx="9" cy="20" r="1.5"></circle>
          <circle cx="18" cy="20" r="1.5"></circle>
        </svg>
        Order Stock
      </a>
      <?php if ($canManageSuppliers): ?>
        <a class="nav-item" href="supplier.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 7h16v10H4z"></path>
            <path d="M7 7V5h10v2"></path>
            <path d="M9 12h6"></path>
          </svg>
          Suppliers
        </a>
      <?php endif; ?>
      <a class="nav-item" href="receive stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 3v18"></path>
          <path d="M8 7l4-4 4 4"></path>
          <path d="M8 17l4 4 4-4"></path>
        </svg>
        Receive Stock
      </a>
      <a class="nav-item" href="reports.php">
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
        <h1>Order Stock</h1>
        <p>Create a purchase order for low-stock items without changing inventory records.</p>
      </div>
      <div class="header-actions">
        <div class="chip"><?= $orderNumber ?></div>
        <div class="chip"><?= date("F j, Y") ?></div>
      </div>
    </header>

    <div class="page-note no-print">
      <div class="summary-card">
        <span class="summary-label">Low Stock Items</span>
        <strong><?= count($lowItems) ?></strong>
        <span>Items currently eligible for reorder on this screen.</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">Order Mode</span>
        <strong>Draft PO</strong>
        <span>Build a clean purchase order without affecting stock counts.</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">Supplier</span>
        <strong><?= count($suppliers) ?></strong>
        <span>Available suppliers ready for selection when generating the order.</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">Pharmacist Access</span>
        <strong>Enabled</strong>
                <span>Admins and pharmacists can place orders directly from this page.</span>
      </div>
    </div>

    <section class="panel no-print" style="margin-bottom:22px;">
      <div class="panel-header">
        <div>
          <h3>How to Order</h3>
          <p>Follow these quick steps to create and save a purchase order.</p>
        </div>
      </div>
      <div class="process-grid">
        <div class="process-card">
          <div class="step">1</div>
          <strong>Select a supplier</strong>
          <span>Choose the supplier you want to send the order to.</span>
        </div>
        <div class="process-card">
          <div class="step">2</div>
          <strong>Enter quantities</strong>
          <span>Fill in the order quantity for each low-stock item.</span>
        </div>
        <div class="process-card">
          <div class="step">3</div>
          <strong>Save the order</strong>
          <span>Click <strong>Save Order</strong> to store the order history and follow-up date.</span>
        </div>
      </div>
    </section>

    <section class="panel no-print">
      <div class="panel-header">
        <div>
          <h3>Purchase Order Form</h3>
          <p>Choose a supplier, enter the quantities you need, and generate a tidy order preview for printing.</p>
        </div>
      </div>
      <form class="form" method="post" action="order stock.php">
        <div class="field">
          <label>Supplier</label>
          <select name="supplier" required>
            <option value="">Select supplier</option>
            <?php foreach ($suppliers as $supplier): ?>
              <option value="<?= (int)$supplier['id'] ?>" <?= $selectedSupplierId === (int)$supplier['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($supplier['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Notes</label>
          <input type="text" name="notes" value="<?= htmlspecialchars($notes) ?>" placeholder="Delivery instructions or urgent request">
          <div class="form-helper">Optional note for the receiving supplier or procurement team.</div>
        </div>

        <div class="field inline">
          <label>Reorder Items</label>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Current Qty</th>
                  <th>Order Qty</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($lowItems) === 0): ?>
                  <tr>
                    <td colspan="3">No low-stock items need ordering right now.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($lowItems as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars($item['name']) ?></td>
                      <td><?= (int)$item['quantity'] ?></td>
                      <td>
                        <input type="number" min="0" name="order_qty_<?= (int)$item['id'] ?>" placeholder="0" style="max-width:120px;">
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="form-actions">
          <button class="button ghost" type="reset">Clear</button>
          <button class="button primary" type="submit">Save Order</button>
        </div>
      </form>
    </section>

    <?php if ($saveMessage): ?>
      <div class="mini-row" style="margin-bottom:14px;">
        <strong><?= htmlspecialchars($saveMessage) ?></strong>
      </div>
    <?php endif; ?>

    <?php if ($orderSaved): ?>
      <section class="panel" style="margin-top:22px;">
        <div class="receipt-sheet">
          <div class="receipt-header">
            <div class="receipt-brand">
              <strong>City Clinic Receipt</strong>
              <span>Purchase order receipt generated from the order screen.</span>
            </div>
            <div class="receipt-meta">
              <strong><?= htmlspecialchars($orderNumber) ?></strong><br>
              <span><?= date("F j, Y") ?></span>
            </div>
          </div>

          <div class="receipt-summary">
            <div class="mini-row">
              <strong>Supplier</strong>
              <span><?= htmlspecialchars($selectedSupplier) ?></span>
            </div>
            <div class="mini-row">
              <strong>Items</strong>
              <span><?= count($orderedItems) ?></span>
            </div>
            <div class="mini-row">
              <strong>Total Units</strong>
              <span><?= (int)$totalRequested ?></span>
            </div>
            <div class="mini-row">
              <strong>Follow-up</strong>
              <span><?= date("M j, Y", strtotime($followUpDate)) ?></span>
            </div>
          </div>

          <div class="panel-header" style="margin-bottom:12px;">
            <div>
              <h3 style="margin-bottom:4px;">Receipt Details</h3>
              <p>Review the generated receipt before printing or saving it as a PDF.</p>
            </div>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Current Qty</th>
                  <th>Order Qty</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($orderedItems as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= (int)$row['current'] ?></td>
                    <td><?= (int)$row['order_qty'] ?></td>
                    <td><span class="status pending"><?= htmlspecialchars(ucfirst($row['line_status'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="receipt-footer">
            <div>Notes: <?= htmlspecialchars($notes ?: 'None') ?></div>
            <div>Prepared for procurement and supplier confirmation. Supplier notification is queued for later.</div>
          </div>

          <div class="form-actions no-print" style="margin-top:16px;">
            <button type="button" class="button primary" onclick="window.print(); return false;">Print / Save PDF</button>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <section class="panel no-print" style="margin-top:22px;">
      <div class="panel-header">
        <div>
          <h3>Saved Orders</h3>
          <p>Every submitted purchase order and its follow-up reminder is stored here for later review.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Order</th>
              <th>Supplier</th>
              <th>Items</th>
              <th>Units</th>
              <th>Follow-up</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($recentOrders) === 0): ?>
              <tr>
                <td colspan="6">No saved orders yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentOrders as $history): ?>
                <tr>
                  <td><?= htmlspecialchars($history['order_number']) ?></td>
                  <td><?= htmlspecialchars($history['supplier_name']) ?></td>
                  <td><?= (int)$history['total_items'] ?></td>
                  <td><?= (int)$history['total_units'] ?></td>
                  <td><?= !empty($history['follow_up_date']) ? date("M j, Y", strtotime($history['follow_up_date'])) : '-' ?></td>
                  <td><span class="status pending"><?= htmlspecialchars(ucfirst($history['notification_status'] ?: 'pending')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel no-print" style="margin-top:22px;">
      <div class="panel-header">
        <div>
          <h3>Follow-up Schedule</h3>
          <p>Upcoming review dates for recent orders. This is where the later supplier reminder is tracked.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Supplier</th>
              <th>Scheduled For</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($recentFollowups) === 0): ?>
              <tr>
                <td colspan="3">No follow-up schedules yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentFollowups as $followup): ?>
                <tr>
                  <td><?= htmlspecialchars($followup['supplier_name']) ?></td>
                  <td><?= !empty($followup['scheduled_for']) ? date("M j, Y", strtotime($followup['scheduled_for'])) : '-' ?></td>
                  <td><span class="status pending"><?= htmlspecialchars(ucfirst($followup['status'] ?: 'pending')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>
</body>
</html>

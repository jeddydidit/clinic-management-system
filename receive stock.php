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

$message = '';
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$canManageSuppliers = ($_SESSION['role'] ?? '') === 'pharmacist';
$hasReceivedBy = !empty($GLOBALS['clinic_po_has_received_by']);
$hasReceivedAt = !empty($GLOBALS['clinic_po_has_received_at']);

if (($_POST['action'] ?? '') === 'receive_order') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        $message = 'Please select a valid order.';
    } else {
        $conn->begin_transaction();
        try {
            $orderStmt = $conn->prepare("SELECT id, order_number, supplier_id, supplier_name, status FROM purchase_orders WHERE id = ? FOR UPDATE");
            $orderStmt->bind_param("i", $orderId);
            $orderStmt->execute();
            $orderResult = $orderStmt->get_result();
            $order = $orderResult ? $orderResult->fetch_assoc() : null;
            $orderStmt->close();

            if (!$order) {
                throw new Exception('Order not found.');
            }

            if (($order['status'] ?? '') === 'received') {
                throw new Exception('This order has already been received.');
            }

            $itemsStmt = $conn->prepare("SELECT poi.id, poi.stock_id, poi.order_quantity, poi.item_name, s.quantity, s.name, s.supplier_id
                                         FROM purchase_order_items poi
                                         JOIN stock s ON s.id = poi.stock_id
                                         WHERE poi.purchase_order_id = ?
                                         FOR UPDATE");
            $itemsStmt->bind_param("i", $orderId);
            $itemsStmt->execute();
            $itemsResult = $itemsStmt->get_result();

            $items = [];
            while ($row = $itemsResult->fetch_assoc()) {
                $items[] = $row;
            }
            $itemsStmt->close();

            if (count($items) === 0) {
                throw new Exception('No order items found for this order.');
            }

            $user = $_SESSION['username'];
            foreach ($items as $item) {
                $newQty = (int)$item['quantity'] + (int)$item['order_quantity'];
                $updateStockSql = $hasReceivedAt
                    ? "UPDATE stock SET quantity = ?, supplier_id = ?, updated_at = NOW(), updated_by = ? WHERE id = ?"
                    : "UPDATE stock SET quantity = ?, supplier_id = ?, updated_by = ? WHERE id = ?";
                $stmt = $conn->prepare($updateStockSql);
                $stmt->bind_param("iisi", $newQty, $order['supplier_id'], $user, $item['stock_id']);
                $stmt->execute();
                $stmt->close();

                $lineStmt = $conn->prepare("UPDATE purchase_order_items SET line_status = 'received' WHERE id = ?");
                $lineStmt->bind_param("i", $item['id']);
                $lineStmt->execute();
                $lineStmt->close();
            }

            $orderUpdateSql = $hasReceivedBy && $hasReceivedAt
                ? "UPDATE purchase_orders SET status = 'received', notification_status = 'received', received_by = ?, received_at = NOW() WHERE id = ?"
                : "UPDATE purchase_orders SET status = 'received', notification_status = 'received' WHERE id = ?";

            $orderUpdate = $conn->prepare($orderUpdateSql);
            if ($hasReceivedBy && $hasReceivedAt) {
                $orderUpdate->bind_param("si", $user, $orderId);
            } else {
                $orderUpdate->bind_param("i", $orderId);
            }
            $orderUpdate->execute();
            $orderUpdate->close();

            $followupStmt = $conn->prepare("UPDATE order_followups SET status = 'completed' WHERE purchase_order_id = ?");
            $followupStmt->bind_param("i", $orderId);
            $followupStmt->execute();
            $followupStmt->close();

            $conn->commit();
            $message = 'Stock received successfully and inventory updated.';
        } catch (Throwable $e) {
            $conn->rollback();
            $message = 'Could not receive stock: ' . $e->getMessage();
        }
    }
}

$orderRows = [];
$orderSql = $isAdmin
    ? "SELECT id, order_number, supplier_name, requested_by, total_units, status, follow_up_date, created_at, received_by, received_at
       FROM purchase_orders
       ORDER BY created_at DESC"
    : "SELECT id, order_number, supplier_name, requested_by, total_units, status, follow_up_date, created_at, received_by, received_at
       FROM purchase_orders
       ORDER BY created_at DESC";

$orderResult = $conn->query($orderSql);
if ($orderResult) {
    while ($row = $orderResult->fetch_assoc()) {
        $orderRows[] = $row;
    }
}

$selectedOrderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
if ($selectedOrderId <= 0 && count($orderRows) > 0) {
    $selectedOrderId = (int)$orderRows[0]['id'];
}

$selectedOrder = null;
$selectedItems = [];
if ($selectedOrderId > 0) {
    $stmt = $conn->prepare("SELECT id, order_number, supplier_name, requested_by, total_items, total_units, status, follow_up_date, created_at, received_by, received_at
                            FROM purchase_orders WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $selectedOrderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $selectedOrder = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($selectedOrder) {
        $stmt = $conn->prepare("SELECT poi.id, poi.item_name, poi.order_quantity, poi.current_quantity, poi.line_status, s.quantity AS current_stock
                                FROM purchase_order_items poi
                                JOIN stock s ON s.id = poi.stock_id
                                WHERE poi.purchase_order_id = ?
                                ORDER BY poi.id ASC");
        $stmt->bind_param("i", $selectedOrderId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $selectedItems[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receive Stock | City Clinic</title>
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
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l9-9 9 9"></path><path d="M9 21V9h6v12"></path></svg>
        Dashboard
      </a>
      <a class="nav-item" href="record stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        Record Stock
      </a>
      <a class="nav-item" href="view stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4h18v6H3z"></path><path d="M3 14h18v6H3z"></path></svg>
        View Stock
      </a>
      <a class="nav-item" href="order stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-2 9H8L6 6z"></path><path d="M6 6l-2-3H1"></path><circle cx="9" cy="20" r="1.5"></circle><circle cx="18" cy="20" r="1.5"></circle></svg>
        Order Stock
      </a>
      <a class="nav-item active" href="receive stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v18"></path><path d="M8 7l4-4 4 4"></path><path d="M8 17l4 4 4-4"></path></svg>
        Receive Stock
      </a>
      <?php if ($canManageSuppliers): ?>
      <a class="nav-item" href="supplier.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16"></path><path d="M6 7v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"></path><path d="M9 11h6"></path></svg>
        Suppliers
      </a>
      <?php endif; ?>
      <a class="nav-item" href="reports.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19h16"></path><path d="M6 16V8"></path><path d="M12 16V5"></path><path d="M18 16v-6"></path></svg>
        Reports
      </a>
      <?php if ($isAdmin): ?>
      <a class="nav-item" href="user management.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 11a4 4 0 1 0-8 0"></path><path d="M12 15c-4 0-7 2-7 4v2h14v-2c0-2-3-4-7-4z"></path></svg>
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
        <h1>Receive Stock</h1>
        <p>Confirm delivered orders, update inventory, and close out the purchase order.</p>
      </div>
      <div class="header-actions">
        <div class="chip"><?= date("F j, Y") ?></div>
      </div>
    </header>

    <?php if ($message): ?>
      <div class="mini-row" style="margin-bottom:14px;">
        <strong><?= htmlspecialchars($message) ?></strong>
      </div>
    <?php endif; ?>

    <section class="panel">
      <h3>Pending Orders</h3>
      <form class="form" method="get" action="receive stock.php" style="grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); margin-bottom:18px;">
        <div class="field">
          <label>Select Order</label>
          <select name="order_id" onchange="this.form.submit()">
            <?php foreach ($orderRows as $row): ?>
              <option value="<?= (int)$row['id'] ?>" <?= $selectedOrderId === (int)$row['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($row['order_number']) ?> - <?= htmlspecialchars($row['supplier_name']) ?> (<?= htmlspecialchars(ucfirst($row['status'])) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>

      <?php if ($selectedOrder): ?>
        <div class="receipt-summary">
          <div class="mini-row"><strong>Order</strong><span><?= htmlspecialchars($selectedOrder['order_number']) ?></span></div>
          <div class="mini-row"><strong>Supplier</strong><span><?= htmlspecialchars($selectedOrder['supplier_name']) ?></span></div>
          <div class="mini-row"><strong>Requested By</strong><span><?= htmlspecialchars($selectedOrder['requested_by']) ?></span></div>
          <div class="mini-row"><strong>Status</strong><span><?= htmlspecialchars(ucfirst($selectedOrder['status'])) ?></span></div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th>Current Stock</th>
                <th>Ordered Qty</th>
                <th>Line Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($selectedItems as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['item_name']) ?></td>
                  <td><?= (int)$item['current_stock'] ?></td>
                  <td><?= (int)$item['order_quantity'] ?></td>
                  <td><?= htmlspecialchars(ucfirst($item['line_status'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <form class="form" method="post" action="receive stock.php" style="margin-top:18px;">
          <input type="hidden" name="action" value="receive_order">
          <input type="hidden" name="order_id" value="<?= (int)$selectedOrderId ?>">
          <div class="form-actions">
            <button class="button primary" type="submit" onclick="return confirm('Mark this order as received and update stock?')">Receive Stock</button>
          </div>
        </form>
      <?php else: ?>
        <p>No orders available to receive.</p>
      <?php endif; ?>
    </section>

    <section class="panel" style="margin-top:22px;">
      <h3>Order History</h3>
      <table>
        <thead>
          <tr>
            <th>Order</th>
            <th>Supplier</th>
            <th>Requested By</th>
            <th>Status</th>
            <th>Received By</th>
            <th>Received At</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($orderRows) === 0): ?>
            <tr><td colspan="6">No purchase orders found.</td></tr>
          <?php else: ?>
            <?php foreach ($orderRows as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['order_number']) ?></td>
                <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                <td><?= htmlspecialchars($row['requested_by']) ?></td>
                <td><?= htmlspecialchars(ucfirst($row['status'])) ?></td>
                <td><?= htmlspecialchars($row['received_by'] ?? '-') ?></td>
                <td><?= !empty($row['received_at']) ? date("M j, Y g:i A", strtotime($row['received_at'])) : '-' ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </main>
</div>
</body>
</html>

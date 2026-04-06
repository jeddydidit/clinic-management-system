<?php
include 'db.php';
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$message = "";
$hasImagePath = !empty($GLOBALS['clinic_stock_has_image_path']);
$hasUpdatedAt = !empty($GLOBALS['clinic_stock_has_updated_at']);
$hasUpdatedBy = !empty($GLOBALS['clinic_stock_has_updated_by']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'use') {
    $stockId = (int)($_POST['stock_id'] ?? 0);
    $usedQty = (int)($_POST['used_quantity'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $usedBy = $_SESSION['username'];

    if ($stockId <= 0 || $usedQty <= 0) {
        $message = "Please select an item and a valid quantity.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT quantity FROM stock WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $stockId);
            $stmt->execute();
            $stmt->bind_result($currentQty);
            $stmt->fetch();
            $stmt->close();

            if ($currentQty < $usedQty) {
                $message = "Not enough stock available.";
                $conn->rollback();
            } else {
                $newQty = $currentQty - $usedQty;
                $updateSql = $hasUpdatedAt
                    ? "UPDATE stock SET quantity = ?, updated_at = NOW() WHERE id = ?"
                    : "UPDATE stock SET quantity = ? WHERE id = ?";
                $stmt = $conn->prepare($updateSql);
                $stmt->bind_param("ii", $newQty, $stockId);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO usage_log (stock_id, used_quantity, used_by, note, used_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("iiss", $stockId, $usedQty, $usedBy, $note);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $message = "Usage recorded. Stock updated.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Could not record usage. Please try again.";
        }
    }
}

$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$status = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(s.name LIKE CONCAT('%', ?, '%') OR s.batch_number LIKE CONCAT('%', ?, '%'))";
    $types .= "ss";
    $params[] = $search;
    $params[] = $search;
}
if ($category !== '' && $category !== 'All categories') {
    $where[] = "s.category = ?";
    $types .= "s";
    $params[] = $category;
}
if ($status !== '' && $status !== 'All statuses') {
    if ($status === 'Healthy') {
        $where[] = "s.quantity > 15";
    } elseif ($status === 'Low') {
        $where[] = "s.quantity BETWEEN 6 AND 15";
    } elseif ($status === 'Critical') {
        $where[] = "s.quantity <= 5";
    }
}

$stockSelect = $hasImagePath ? "s.id, s.name, s.batch_number, s.quantity, s.expiry_date, s.category, s.other_details, s.image_path, sp.name AS supplier"
                             : "s.id, s.name, s.batch_number, s.quantity, s.expiry_date, s.category, s.other_details, NULL AS image_path, sp.name AS supplier";
$stockSelect .= ", s.updated_at, s.updated_by";
$pendingSelect = "
    SELECT latest.stock_id, latest.order_quantity AS pending_order_qty, po.order_number AS pending_order_number,
           po.follow_up_date AS pending_follow_up, po.notification_status AS pending_order_status
    FROM purchase_order_items latest
    INNER JOIN purchase_orders po ON po.id = latest.purchase_order_id
    INNER JOIN (
        SELECT poi2.stock_id, MAX(poi2.id) AS latest_item_id
        FROM purchase_order_items poi2
        INNER JOIN purchase_orders po2 ON po2.id = poi2.purchase_order_id
        WHERE po2.status <> 'received'
          AND poi2.line_status <> 'received'
        GROUP BY poi2.stock_id
    ) latest_items ON latest_items.latest_item_id = latest.id
    WHERE po.status <> 'received'
      AND latest.line_status <> 'received'
";
$sql = "SELECT {$stockSelect}
        , pending_orders.pending_order_qty, pending_orders.pending_order_number, pending_orders.pending_follow_up, pending_orders.pending_order_status
        FROM stock s
        LEFT JOIN suppliers sp ON s.supplier_id = sp.id
        LEFT JOIN ({$pendingSelect}) pending_orders ON pending_orders.stock_id = s.id";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY s.name ASC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

$stockOptions = $conn->query("SELECT id, name, batch_number, quantity FROM stock ORDER BY name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>View Stock | City Clinic</title>
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

      <a class="nav-item active" href="view stock.php">
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

      <a class="nav-item" href="supplier.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 7h16"></path>
          <path d="M6 7v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"></path>
          <path d="M9 11h6"></path>
        </svg>
        Suppliers
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
        <h1>View Stock</h1>
        <p>Scan availability, quantities, and expiry information.</p>
      </div>
      <div class="header-actions">
        <div class="chip">Inventory list</div>
        <div class="chip"><?= date("F j, Y") ?></div>
      </div>
    </header>

    <section class="panel">
      <h3>Stock Overview</h3>
      <?php if ($message): ?>
        <div class="mini-row" style="margin-bottom:14px;">
          <strong><?= htmlspecialchars($message) ?></strong>
        </div>
      <?php endif; ?>

      <form class="form" method="get" action="view stock.php" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));">
        <div class="field">
          <label>Search</label>
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or code">
        </div>
        <div class="field">
          <label>Category</label>
          <select name="category">
            <option>All categories</option>
            <?php foreach (clinic_stock_categories() as $categoryOption): ?>
              <option <?= $category === $categoryOption ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option>All statuses</option>
            <option <?= $status === 'Healthy' ? 'selected' : '' ?>>Healthy</option>
            <option <?= $status === 'Low' ? 'selected' : '' ?>>Low</option>
            <option <?= $status === 'Critical' ? 'selected' : '' ?>>Critical</option>
          </select>
        </div>
        <div class="form-actions">
          <button class="button ghost" type="submit">Apply Filters</button>
        </div>
      </form>

      <div style="margin-top:18px;">
        <table>
          <thead>
            <tr>
              <th>Medicine</th>
              <th>Batch</th>
              <th>Quantity</th>
              <th>Order</th>
              <th>Follow-up</th>
              <th>Expiry</th>
              <th>Last Updated</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($items) === 0): ?>
              <tr>
                <td colspan="8">No stock items found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($items as $item): ?>
                <?php
                  $qty = (int)$item['quantity'];
                  $pendingQty = (int)($item['pending_order_qty'] ?? 0);
                  $pendingOrderNumber = trim($item['pending_order_number'] ?? '');
                  $pendingFollowUp = trim($item['pending_follow_up'] ?? '');
                  $pendingOrderStatus = trim($item['pending_order_status'] ?? '');
                  if ($qty <= 5) {
                      $statusClass = "critical";
                      $statusText = "Critical";
                  } elseif ($qty <= 15) {
                      $statusClass = "low";
                      $statusText = "Low";
                  } else {
                      $statusClass = "ok";
                      $statusText = "Healthy";
                  }
                ?>
                <tr>
                  <td>
                    <div class="item-cell">
                      <img class="item-thumb" src="<?= htmlspecialchars(clinic_stock_image_src($item['image_path'] ?? '')) ?>" alt="<?= htmlspecialchars($item['name'] ?? '') ?>">
                      <span>
                        <?= htmlspecialchars($item['name'] ?? '') ?><br>
                        <small style="color:var(--muted);"><?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?></small>
                        <?php if (($item['category'] ?? '') === 'Other' && !empty($item['other_details'])): ?>
                          <br><small style="color:var(--muted);"><?= htmlspecialchars($item['other_details']) ?></small>
                        <?php endif; ?>
                      </span>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($item['batch_number'] ?? '') ?></td>
                  <td><?= $qty ?></td>
                  <td>
                    <?php if ($pendingQty > 0): ?>
                      <span class="status pending"><?= htmlspecialchars('Pending ' . $pendingQty) ?></span><br>
                      <small style="color:var(--muted);"><?= htmlspecialchars($pendingOrderNumber ?: 'Open order') ?></small>
                    <?php else: ?>
                      <span class="status ok">No open order</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= $pendingFollowUp !== '' ? htmlspecialchars(date("M j, Y", strtotime($pendingFollowUp))) : '-' ?>
                    <?php if ($pendingOrderStatus !== ''): ?>
                      <br><small style="color:var(--muted);"><?= htmlspecialchars(ucfirst($pendingOrderStatus)) ?></small>
                    <?php endif; ?>
                  </td>
                  <td><?= $item['expiry_date'] ? date("M j, Y", strtotime($item['expiry_date'])) : "-" ?></td>
                  <td>
                    <?= !empty($item['updated_at']) ? date("M j, Y g:i A", strtotime($item['updated_at'])) : '-' ?><br>
                    <small style="color:var(--muted);"><?= htmlspecialchars($item['updated_by'] ?? '-') ?></small>
                  </td>
                  <td><span class="status <?= $statusClass ?>"><?= $statusText ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel" style="margin-top:22px;">
      <h3>Record Usage</h3>
      <form class="form" method="post" action="view stock.php">
        <input type="hidden" name="action" value="use">
        <div class="field">
          <label>Item</label>
          <select name="stock_id" required>
            <option value="">Select item</option>
            <?php while ($row = $stockOptions->fetch_assoc()): ?>
              <option value="<?= (int)$row['id'] ?>">
                <?= htmlspecialchars($row['name']) ?> (<?= (int)$row['quantity'] ?>)
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="field">
          <label>Quantity Used</label>
          <input type="number" name="used_quantity" min="1" required>
        </div>
        <div class="field">
          <label>Note</label>
          <input type="text" name="note" placeholder="Optional usage note">
        </div>
        <div class="form-actions">
          <button class="button primary" type="submit">Save Usage</button>
        </div>
      </form>
    </section>
  </main>
</div>
</body>
</html>

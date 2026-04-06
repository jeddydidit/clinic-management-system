<?php
include 'db.php';
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$isPharmacist = ($_SESSION['role'] ?? '') === 'pharmacist';
$canOrderStock = in_array(($_SESSION['role'] ?? ''), ['admin', 'pharmacist'], true);
$hasImagePath = !empty($GLOBALS['clinic_stock_has_image_path']);

/* TOTAL MEDICINES */
$totalStock = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) AS total FROM stock")
)['total'];

/* LOW STOCK */
$lowStock = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) AS low FROM stock WHERE quantity <= 5")
)['low'];

/* SUPPLIERS */
$totalSuppliers = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) AS total FROM suppliers")
)['total'];

/* PENDING ORDERS */
$pendingOrders = 0;
$pendingOrdersResult = $conn->query("SELECT COUNT(*) AS total FROM purchase_orders WHERE notification_status = 'pending' AND status <> 'received'");
if ($pendingOrdersResult) {
    $pendingOrders = (int)($pendingOrdersResult->fetch_assoc()['total'] ?? 0);
}

$lowItems = [];
$lowSelect = $hasImagePath ? "name, quantity, image_path" : "name, quantity, NULL AS image_path";
$lowResult = $conn->query("SELECT {$lowSelect} FROM stock WHERE quantity <= 15 ORDER BY quantity ASC LIMIT 5");
if ($lowResult) {
    while ($row = $lowResult->fetch_assoc()) {
        $lowItems[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard | City Clinic</title>
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
      <a class="nav-item active" href="dashboard.php">
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

      <?php if ($canOrderStock): ?>
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
      <?php endif; ?>
      <?php if ($isPharmacist): ?>
        <a class="nav-item" href="supplier.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 7h16"></path>
            <path d="M6 7v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"></path>
            <path d="M9 11h6"></path>
          </svg>
          Suppliers
        </a>
      <?php endif; ?>

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
    <h1>Dashboard</h1>
    <p>A guided view of stock health, supplier activity, and the next actions your team should take.</p>
  </div>
      <div class="header-actions">
        <div class="chip">City Clinic Inventory</div>
        <div class="chip"><?= date("F j, Y") ?></div>
      </div>
</header>

    <section class="stats">
      <div class="stat-card">
        <div class="stat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 12a4 4 0 1 1 8 0v5a4 4 0 1 1-8 0z"></path>
            <path d="M10 10h4"></path>
            <path d="M12 8v4"></path>
          </svg>
        </div>
        <div class="stat-label">Total Medicines</div>
        <div class="stat-value"><?= $totalStock ?></div>
        <div class="stat-accent">Stable inventory count</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3l9 16H3L12 3z"></path>
            <path d="M12 9v4"></path>
            <path d="M12 17h.01"></path>
          </svg>
        </div>
        <div class="stat-label">Low Stock Items</div>
        <div class="stat-value"><?= $lowStock ?></div>
        <div class="stat-accent">Requires attention</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3v18"></path>
            <path d="M3 12h18"></path>
            <path d="M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"></path>
          </svg>
        </div>
        <div class="stat-label">Suppliers</div>
        <div class="stat-value"><?= $totalSuppliers ?></div>
        <div class="stat-accent">Active partners</div>
      </div>
      <?php if ($canOrderStock): ?>
        <div class="stat-card">
          <div class="stat-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 8.5l-9 5-9-5"></path>
              <path d="M3 8.5l9-5 9 5-9 5-9-5z"></path>
              <path d="M12 13.5V21"></path>
            </svg>
          </div>
          <div class="stat-label">Pending Orders</div>
          <div class="stat-value"><?= $pendingOrders ?></div>
          <div class="stat-accent"><a href="order stock.php">Open order screen</a></div>
        </div>
      <?php endif; ?>
    </section>

    <section class="quick-actions">
      <a class="quick-action" href="record stock.php">
        <span class="qa-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 5v14"></path>
            <path d="M5 12h14"></path>
          </svg>
        </span>
        <span>
          <strong>Record Stock</strong>
          <span>Add new inventory quickly without hunting through menus.</span>
        </span>
      </a>
      <?php if ($canOrderStock): ?>
        <a class="quick-action" href="order stock.php">
          <span class="qa-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 6h15l-2 9H8L6 6z"></path>
              <path d="M6 6l-2-3H1"></path>
            </svg>
          </span>
          <span>
            <strong>Order Stock</strong>
            <span>Create a clean purchase order from low-stock items.</span>
          </span>
        </a>
        <a class="quick-action" href="receive stock.php">
          <span class="qa-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 3v18"></path>
              <path d="M8 7l4-4 4 4"></path>
              <path d="M8 17l4 4 4-4"></path>
            </svg>
          </span>
          <span>
            <strong>Receive Stock</strong>
            <span>Confirm deliveries and push items into inventory.</span>
          </span>
        </a>
        <a class="quick-action" href="supplier.php">
          <span class="qa-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 7h16"></path>
              <path d="M6 7v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"></path>
            </svg>
          </span>
          <span>
            <strong>Manage Suppliers</strong>
            <span>Keep supplier contacts organised and ready to use.</span>
          </span>
        </a>
      <?php endif; ?>
      <a class="quick-action" href="reports.php">
        <span class="qa-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 19h16"></path>
            <path d="M6 16V8"></path>
            <path d="M12 16V5"></path>
            <path d="M18 16v-6"></path>
          </svg>
        </span>
        <span>
          <strong>Generate Reports</strong>
          <span>Review performance and export a clean PDF report.</span>
        </span>
      </a>
    </section>

    <section class="content-grid">
      <div class="panel">
        <h3>Low Stock Alerts</h3>
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th>Quantity</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($lowItems) === 0): ?>
              <tr>
                <td colspan="3">No low stock alerts.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($lowItems as $item): ?>
                <?php
                  $qty = (int)$item['quantity'];
                  if ($qty <= 5) {
                      $statusClass = "critical";
                      $statusText = "Critical";
                  } else {
                      $statusClass = "low";
                      $statusText = "Low";
                  }
                ?>
                <tr>
                  <td>
                    <div class="item-cell">
                      <img class="item-thumb" src="<?= htmlspecialchars(clinic_stock_image_src($item['image_path'] ?? '')) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                      <span><?= htmlspecialchars($item['name']) ?></span>
                    </div>
                  </td>
                  <td><?= $qty ?></td>
                  <td><span class="status <?= $statusClass ?>"><?= $statusText ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="panel">
        <h3>Inventory Health</h3>
        <div class="mini-list">
          <div>
            <div class="mini-row">
              <strong>Fast Movers</strong>
              <span>Restock soon</span>
            </div>
            <div class="progress"><div style="width:68%"></div></div>
          </div>
          <div>
            <div class="mini-row">
              <strong>Slow Movers</strong>
              <span>Review pricing</span>
            </div>
            <div class="progress"><div style="width:35%"></div></div>
          </div>
          <div>
            <div class="mini-row">
              <strong>Safety Stock</strong>
              <span>Within range</span>
            </div>
            <div class="progress"><div style="width:82%"></div></div>
          </div>
        </div>
      </div>
    </section>
  </main>
</div>
</body>
<script>
setInterval(function () {
  window.location.reload();
}, 30000);
</script>
</html>

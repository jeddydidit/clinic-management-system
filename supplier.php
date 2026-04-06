<?php
include 'db.php';
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'pharmacist') {
    header("Location: dashboard.php");
    exit;
}

$message = '';
$hasSupplierContact = clinic_column_exists($conn, 'suppliers', 'contact');
$hasSupplierCreatedAt = clinic_column_exists($conn, 'suppliers', 'created_at');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supplier'])) {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');

    if ($name === '') {
        $message = 'Please enter a supplier name.';
    } else {
        $check = $conn->prepare("SELECT 1 FROM suppliers WHERE name = ? LIMIT 1");
        $check->bind_param("s", $name);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = 'Supplier already exists.';
        } else {
            $check->close();
            if ($hasSupplierContact) {
                $stmt = $conn->prepare("INSERT INTO suppliers (name, contact) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $contact);
            } else {
                $stmt = $conn->prepare("INSERT INTO suppliers (name) VALUES (?)");
                $stmt->bind_param("s", $name);
            }
            if ($stmt->execute()) {
                $message = 'Supplier added successfully.';
            } else {
                $message = 'Could not add supplier. Please try again.';
            }
            $stmt->close();
        }
        $check->close();
    }
}

$suppliers = [];
$supplierColumns = ['id', 'name'];
if ($hasSupplierContact) {
    $supplierColumns[] = 'contact';
}
if ($hasSupplierCreatedAt) {
    $supplierColumns[] = 'created_at';
}
$result = $conn->query('SELECT ' . implode(', ', $supplierColumns) . ' FROM suppliers ORDER BY name ASC');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}
$supplierCount = count($suppliers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Suppliers | City Clinic</title>
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
      <a class="nav-item" href="record stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 5v14"></path>
          <path d="M5 12h14"></path>
        </svg>
        Record Stock
      </a>
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
      <a class="nav-item active" href="supplier.php">
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
        <h1>Suppliers</h1>
        <p>Manage the clinic's supplier directory in one clean, organized view.</p>
      </div>
      <div class="header-actions">
        <div class="chip">Supplier directory</div>
        <div class="chip"><?= date("F j, Y") ?></div>
      </div>
    </header>

    <div class="page-note">
      <div class="summary-card">
        <span class="summary-label">Supplier Count</span>
        <strong><?= $supplierCount ?></strong>
        <span>Registered suppliers available for ordering and stock planning.</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">Add Supplier</span>
        <strong>Quick Entry</strong>
        <span>Keep the directory organized with a clean, fast supplier form.</span>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="mini-row" style="margin-bottom:14px;">
        <strong><?= htmlspecialchars($message) ?></strong>
      </div>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-header">
        <div>
          <h3>Add Supplier</h3>
          <p>Create a supplier record manually so ordering stays organized and easy to manage.</p>
        </div>
      </div>
      <form class="form" method="post" action="supplier.php">
        <div class="field">
          <label>Supplier Name</label>
          <input type="text" name="name" placeholder="Enter supplier name" required>
        </div>
        <div class="field">
          <label>Contact</label>
          <input type="text" name="contact" placeholder="Phone or email">
          <div class="form-helper">Optional if your current supplier table does not store contact details.</div>
        </div>
        <div class="form-actions">
          <button class="button primary" type="submit" name="add_supplier">Add Supplier</button>
        </div>
      </form>
    </section>

    <section class="panel" style="margin-top:22px;">
      <div class="panel-header">
        <div>
          <h3>Supplier List</h3>
          <p>Review all registered suppliers at a glance.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Contact</th>
              <th>Added On</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($suppliers) === 0): ?>
              <tr>
                <td colspan="3">No suppliers added yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($suppliers as $supplier): ?>
                <tr>
                  <td><?= htmlspecialchars($supplier['name']) ?></td>
                  <td><?= htmlspecialchars($supplier['contact'] ?? '-') ?></td>
                  <td><?= !empty($supplier['created_at']) ? date("M j, Y", strtotime($supplier['created_at'])) : '-' ?></td>
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

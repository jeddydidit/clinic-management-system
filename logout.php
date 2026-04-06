<?php
require_once 'bootstrap.php';
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

if (isset($_POST['confirm'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (isset($_POST['cancel'])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Logout | City Clinic</title>
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
      <a class="logout" href="logout.php">Logout</a>
    </div>
  </aside>

  <main class="main">
    <header class="page-header">
      <div>
        <h1>Sign Out</h1>
        <p>Please save any changes before leaving the system.</p>
      </div>
    </header>

    <section class="panel" style="max-width:520px;">
      <h3>Ready to leave?</h3>
      <p style="color:var(--muted); margin-top:6px;">
        You will need to log in again to access the system.
      </p>
      <form method="POST" class="form-actions" style="margin-top:20px;">
        <button type="submit" name="confirm" class="button primary">Sign Out</button>
        <button type="submit" name="cancel" class="button ghost">Cancel</button>
      </form>
    </section>
  </main>
</div>
</body>
</html>

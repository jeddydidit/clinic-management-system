<?php
include 'db.php';
session_start();

if(!isset($_SESSION['username']) || $_SESSION['role'] != 'admin'){
    header("Location: index.php");
    exit;
}

$message = "";

/* Handle updates safely */
if(isset($_POST['update_user'])){
    $id = intval($_POST['user_id']);
    $username = trim($conn->real_escape_string($_POST['username']));
    $email = trim($conn->real_escape_string($_POST['email']));
    $phone = trim($conn->real_escape_string($_POST['phone']));
    $role = trim($conn->real_escape_string($_POST['role']));

    if($username && $email && $phone && $role){
        $check = $conn->prepare("SELECT 1 FROM users WHERE username = ? AND user_id != ? LIMIT 1");
        $check->bind_param("si", $username, $id);
        $check->execute();
        $check->store_result();

        if($check->num_rows > 0){
            $message = "Username already exists. Please choose another.";
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, phone=?, role=? WHERE user_id=?");
            $stmt->bind_param("ssssi", $username, $email, $phone, $role, $id);
            if($stmt->execute()){
                $message = "User updated successfully!";
            } else {
                $message = "Error: ".$conn->error;
            }
        }
    } else {
        $message = "All fields are required!";
    }
}

/* Handle deletions safely */
if(isset($_POST['delete_user'])){
    $id = intval($_POST['user_id']);
    $conn->query("DELETE FROM users WHERE user_id=$id");
    $message = "User deleted successfully!";
}

/* Fetch users */
$users = mysqli_query($conn, "SELECT * FROM users ORDER BY username ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management | City Clinic</title>
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

      <a class="nav-item" href="reports.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 19h16"></path>
          <path d="M6 16V8"></path>
          <path d="M12 16V5"></path>
          <path d="M18 16v-6"></path>
        </svg>
        Reports
      </a>

      <a class="nav-item active" href="user management.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M16 11a4 4 0 1 0-8 0"></path>
          <path d="M12 15c-4 0-7 2-7 4v2h14v-2c0-2-3-4-7-4z"></path>
        </svg>
        User Management
      </a>
    </nav>

    <div class="sidebar-footer">
      <a class="logout" href="logout.php" onclick="return confirm('Do you want to sign out now?')">Logout</a>
    </div>
  </aside>

  <main class="main">
    <header class="page-header">
      <div>
        <h1>User Management</h1>
        <p>Update staff access, roles, and contact details.</p>
      </div>
      <div class="header-actions">
        <div class="chip">Admin controls</div>
        <div class="chip"><?= date("F j, Y") ?></div>
      </div>
    </header>

    <?php if($message): ?>
      <div class="mini-row" style="margin-bottom:14px;">
        <strong><?= htmlspecialchars($message) ?></strong>
      </div>
    <?php endif; ?>

    <section class="panel">
      <h3>Staff Accounts</h3>
      <div style="margin-top:18px;">
        <table>
          <thead>
            <tr>
              <th>Staff ID</th>
              <th>Username</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Role</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php while($user = mysqli_fetch_assoc($users)): ?>
            <tr>
              <td><?= htmlspecialchars($user['staff_id']) ?></td>

              <td>
                <input type="text" form="form-<?= $user['user_id'] ?>" name="username" value="<?= htmlspecialchars($user['username']) ?>">
              </td>

              <td>
                <input type="text" form="form-<?= $user['user_id'] ?>" name="email" value="<?= htmlspecialchars($user['email']) ?>">
              </td>

              <td>
                <input type="text" form="form-<?= $user['user_id'] ?>" name="phone" value="<?= htmlspecialchars($user['phone']) ?>">
              </td>

              <td>
                <select form="form-<?= $user['user_id'] ?>" name="role">
                  <option value="admin" <?= $user['role']=='admin'?'selected':'' ?>>Admin</option>
                  <option value="staff" <?= $user['role']=='staff'?'selected':'' ?>>Staff</option>
                  <option value="pharmacist" <?= $user['role']=='pharmacist'?'selected':'' ?>>Pharmacist</option>
                </select>
              </td>

              <td>
                <form id="form-<?= $user['user_id'] ?>" method="POST" style="margin:0; display:flex; gap:8px;">
                  <input type="hidden" name="user_id" value="<?= htmlspecialchars($user['user_id']) ?>">
                  <button type="submit" name="update_user" class="button primary">Save</button>
                  <button type="submit" name="delete_user" class="button ghost" onclick="return confirm('Delete this user?')">Delete</button>
                </form>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>
</body>
</html>

<?php
include 'db.php';
session_start();

$error = '';
$success = '';
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = 'Password reset successful. You can now log in with your new password.';
}
if (isset($_POST['login'])) {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header("Location: dashboard.php");
            exit;
        }

        $error = "Invalid login details!";
    } else {
        $error = "Invalid login details!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>City Clinic | Staff Login</title>
<link rel="stylesheet" href="auth.css">
</head>
<body class="auth-login">
  <div class="auth-shell">
    <div class="auth-hero">
      <div class="auth-hero-content">
        <div class="auth-brand">
          <span class="auth-brand-badge"></span>
          City Clinic
        </div>
        <div class="hero-pill">Inventory + Care</div>
        <h1>Welcome back</h1>
        <p>Sign in to track usage, monitor low stock, and keep the clinic supplied without the notebook chaos.</p>
      </div>
    </div>

    <div class="auth-panel">
      <div>
        <h2>Staff Login</h2>
        <p>Use your username or email address together with your password.</p>
      </div>

      <?php if ($success) echo "<div class='message success'>" . htmlspecialchars($success) . "</div>"; ?>
      <?php if ($error) echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; ?>

      <form class="auth-form" method="post">
        <div class="field">
          <label>Username or Email</label>
          <input type="text" name="identifier" required>
        </div>
        <div class="field">
          <label>Password</label>
          <div class="password-input">
            <input id="loginPassword" type="password" name="password" required>
            <button class="password-toggle" type="button" data-target="loginPassword" aria-label="Show password">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path class="eye-outline" d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
                <circle class="eye-pupil" cx="12" cy="12" r="2.6" />
                <circle class="eye-dot" cx="12" cy="12" r="1.1" />
                <path class="eye-slash" d="M5 19L19 5" />
              </svg>
            </button>
          </div>
        </div>
        <div class="auth-actions">
          <button class="button primary" type="submit" name="login">Login</button>
        </div>
      </form>

      <div class="auth-links">
        <a href="forgotpassword.php">Forgot Password?</a> &middot;
        <a href="signup.php">Create account</a>
      </div>
    </div>
  </div>
  <script>
    (function () {
      document.querySelectorAll('.password-toggle').forEach((button) => {
        button.addEventListener('click', () => {
          const targetId = button.getAttribute('data-target');
          const input = targetId ? document.getElementById(targetId) : null;
          if (!input) return;
          const isHidden = input.type === 'password';
          input.type = isHidden ? 'text' : 'password';
          button.classList.toggle('is-visible', isHidden);
          button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
      });
    })();
  </script>
</body>
</html>

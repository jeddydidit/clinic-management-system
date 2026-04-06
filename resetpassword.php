<?php
include 'db.php';

$error = '';
$success = '';
$token = trim($_GET['token'] ?? '');
$user = null;

function is_strong_password($password){
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password);
}

if ($token === '') {
    $error = 'Invalid reset request.';
} else {
    $stmt = $conn->prepare("SELECT user_id, username FROM users WHERE reset_token = ? AND token_expiry > NOW() LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$user) {
        $error = 'Reset link expired or invalid.';
    }
}

if (!$error && isset($_POST['reset'])) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($password !== $confirm) {
        $error = 'Passwords do not match!';
    } elseif (!is_strong_password($password)) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.';
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE reset_token = ?");
        $stmt->bind_param("ss", $hashed, $token);
        $stmt->execute();
        $stmt->close();

        header("Location: index.php?reset=success");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password | City Clinic</title>
<link rel="stylesheet" href="auth.css">
</head>
<body>
  <div class="auth-shell">
    <div class="auth-hero">
      <div class="auth-hero-content">
        <div class="auth-brand">
          <span class="auth-brand-badge"></span>
          City Clinic
        </div>
        <div class="hero-pill">Account recovery</div>
        <h1>Create a new password</h1>
        <p>Choose a strong password and keep it secure. This update takes effect immediately.</p>
      </div>
    </div>

    <div class="auth-panel">
      <div>
        <h2>Reset Password</h2>
        <p>Enter and confirm your new password.</p>
      </div>

      <?php if ($error) echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; ?>
      <?php if ($success) echo "<div class='message success'>" . htmlspecialchars($success) . "</div>"; ?>

      <?php if (!$error): ?>
        <form class="auth-form" method="post">
          <div class="field">
            <label>New Password</label>
            <div class="password-input">
              <input id="resetPassword" type="password" name="password" required placeholder="Use 8+ chars, uppercase, number, symbol" title="Use at least 8 characters with uppercase, lowercase, a number, and a symbol">
              <button class="password-toggle" type="button" data-target="resetPassword" aria-label="Show password">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                  <path class="eye-outline" d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
                  <circle class="eye-pupil" cx="12" cy="12" r="2.6" />
                  <circle class="eye-dot" cx="12" cy="12" r="1.1" />
                  <path class="eye-slash" d="M5 19L19 5" />
                </svg>
              </button>
            </div>
            <div class="password-meter" aria-live="polite">
              <div class="password-meter-track"><div id="resetPasswordFill" class="password-meter-fill"></div></div>
              <div class="password-meter-label"><span id="resetPasswordLabel">Strength</span><strong id="resetPasswordText">Type your password</strong></div>
            </div>
          </div>
          <div class="field">
            <label>Confirm Password</label>
            <div class="password-input">
              <input id="resetConfirmPassword" type="password" name="confirm" required>
              <button class="password-toggle" type="button" data-target="resetConfirmPassword" aria-label="Show confirm password">
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
            <button class="button primary" name="reset">Reset Password</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="auth-links">
        <a href="index.php">Back to Login</a>
      </div>
    </div>
  </div>

  <?php if (!$error): ?>
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

      const input = document.getElementById('resetPassword');
      const fill = document.getElementById('resetPasswordFill');
      const label = document.getElementById('resetPasswordLabel');
      const text = document.getElementById('resetPasswordText');

      if (!input || !fill || !label || !text) return;

      const updateMeter = () => {
        const value = input.value || '';
        let score = 0;
        if (value.length >= 8) score++;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
        if (/\d/.test(value)) score++;
        if (/[^A-Za-z0-9]/.test(value)) score++;
        if (value.length >= 12) score++;

        let width = 0;
        let state = 'Weak';
        let message = 'Add more characters';
        let color = 'linear-gradient(90deg, #ef4444, #f97316)';

        if (score <= 1) {
          width = 20;
          state = 'Weak';
          message = 'Use mixed characters';
        } else if (score === 2) {
          width = 45;
          state = 'Fair';
          message = 'Add a symbol or number';
          color = 'linear-gradient(90deg, #f97316, #f59e0b)';
        } else if (score === 3) {
          width = 70;
          state = 'Good';
          message = 'Almost there';
          color = 'linear-gradient(90deg, #f59e0b, #22c55e)';
        } else {
          width = 100;
          state = 'Strong';
          message = 'Looks secure';
          color = 'linear-gradient(90deg, #16a34a, #0ea5e9)';
        }

        fill.style.width = width + '%';
        fill.style.background = color;
        label.textContent = 'Strength: ' + state;
        text.textContent = value ? message : 'Type your password';
      };

      input.addEventListener('input', updateMeter);
      updateMeter();
    })();
  </script>
  <?php endif; ?>
</body>
</html>

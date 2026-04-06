<?php
include 'db.php';
session_start();
date_default_timezone_set('Africa/Nairobi');
$error = '';
$success = '';
$admin_invite_code = 'adin123';
$pharmacist_invite_code = 'pharm123';

function is_strong_password($password){
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password);
}

function clinic_log_mail_event(string $message): void
{
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'mail.log', '[' . $timestamp . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function send_welcome_email(string $username, string $email, string $role, string &$deliveryError = ''): bool
{
    $brevoApiKey = trim((string)clinic_config('BREVO_API_KEY', ''));
    $brevoSenderEmail = trim((string)clinic_config('BREVO_SENDER_EMAIL', 'cityclinic001@gmail.com'));
    $brevoSenderName = trim((string)clinic_config('BREVO_SENDER_NAME', 'City Clinic'));

    if ($brevoApiKey === '') {
        $deliveryError = 'BREVO_API_KEY is missing.';
        return false;
    }

    if (!function_exists('curl_init')) {
        $deliveryError = 'PHP cURL extension is not available.';
        return false;
    }

    $loginLink = clinic_base_url() . '/index.php';
    $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeRole = htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8');
    $safeLoginLink = htmlspecialchars($loginLink, ENT_QUOTES, 'UTF-8');

    $data = [
        'sender' => [
            'name' => $brevoSenderName,
            'email' => $brevoSenderEmail,
        ],
        'to' => [[
            'email' => $email,
            'name' => $username,
        ]],
        'subject' => 'Welcome to City Clinic',
        'htmlContent' => '<div style="font-family:Arial,sans-serif;color:#333;line-height:1.6;">'
            . '<h2 style="color:#0b6c66;">Welcome, ' . $safeUsername . '</h2>'
            . '<p>Your City Clinic account has been created successfully.</p>'
            . '<p><strong>Role:</strong> ' . $safeRole . '</p>'
            . '<p>You can now sign in and start using the clinic system.</p>'
            . '<p style="text-align:center;margin:24px 0;">'
            . '<a href="' . $safeLoginLink . '" style="background:#0b6c66;color:#fff;padding:14px 24px;text-decoration:none;border-radius:999px;font-weight:bold;display:inline-block;">'
            . 'Open City Clinic'
            . '</a>'
            . '</p>'
            . '<p>If you did not create this account, please contact the clinic administrator immediately.</p>'
            . '</div>',
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'api-key: ' . $brevoApiKey,
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $sent = $response !== false && $httpCode >= 200 && $httpCode < 300;
    if (!$sent) {
        $deliveryError = $curlError !== ''
            ? $curlError
            : ('Brevo API returned HTTP ' . $httpCode . ($response ? ' with response: ' . $response : ''));
    } else {
        clinic_log_mail_event('Welcome email accepted by Brevo for ' . $email . ' with HTTP ' . $httpCode . '.');
    }

    return $sent;
}

if(isset($_POST['signup'])){
    $staff_id = trim($conn->real_escape_string($_POST['staff_id']));
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $admin_code = trim($_POST['admin_code'] ?? '');
    $pharmacist_code = trim($_POST['pharmacist_code'] ?? '');
    $role = 'staff';
    $password = $_POST['password'];
    $confirm = $_POST['confirm'];

    if($password !== $confirm){
        $error = "Passwords do not match!";
    } elseif(!is_strong_password($password)){
        $error = "Password must be at least 8 characters and include uppercase, lowercase, number, and special character.";
    } else {
        if ($admin_code !== '' && hash_equals($admin_invite_code, $admin_code)) {
            $role = 'admin';
        } elseif ($pharmacist_code !== '' && hash_equals($pharmacist_invite_code, $pharmacist_code)) {
            $role = 'pharmacist';
        } elseif ($admin_code !== '' || $pharmacist_code !== '') {
            $error = "Invalid admin or pharmacist code.";
        }

        if (!$error) {
            $checkStaff = $conn->prepare("SELECT 1 FROM users WHERE staff_id = ? LIMIT 1");
            $checkStaff->bind_param("s", $staff_id);
            $checkStaff->execute();
            $checkStaff->store_result();
            if($checkStaff->num_rows > 0){
                $error = "Staff ID already exists. Please use a unique Staff ID.";
            }
            $checkStaff->close();

            if(!$error){
                $check = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
                $check->bind_param("s", $username);
                $check->execute();
                $check->store_result();

                if($check->num_rows > 0){
                    $error = "Username already exists. Please choose another.";
                } else {
                    $check->close();

                    $checkEmail = $conn->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
                    $checkEmail->bind_param("s", $email);
                    $checkEmail->execute();
                    $checkEmail->store_result();

                    if($checkEmail->num_rows > 0){
                        $error = "Email already exists. Please use another email or log in instead.";
                    }
                    $checkEmail->close();

                    if (!$error) {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO users (staff_id, username, email, phone, password, role) VALUES (?,?,?,?,?,?)");
                        $stmt->bind_param("ssssss", $staff_id, $username, $email, $phone, $hashed, $role);

                        try {
                            if($stmt->execute()){
                                $deliveryError = '';
                                $mailSent = send_welcome_email($username, $email, $role, $deliveryError);
                                if (!$mailSent && $deliveryError !== '') {
                                    error_log('Signup welcome email not sent for ' . $email . ': ' . $deliveryError);
                                    clinic_log_mail_event('Welcome email failed for ' . $email . ': ' . $deliveryError);
                                }
                                $success = "Signup successful!";
                                if ($mailSent) {
                                    $success .= " Welcome email sent to " . $email . ".";
                                } else {
                                    $success .= " Account created, but the welcome email could not be sent right now.";
                                    if ($deliveryError !== '') {
                                        $success .= " Reason: " . $deliveryError;
                                    }
                                }
                                header("refresh:2;url=index.php");
                            } else {
                                $error = "Error: ".$conn->error;
                            }
                        } catch (mysqli_sql_exception $e) {
                            if ((int)$e->getCode() === 1062) {
                                $error = "That staff ID, username, or email is already in use.";
                            } else {
                                $error = "Could not complete signup right now. Please try again.";
                            }
                        }
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up | City Clinic</title>
<link rel="stylesheet" href="auth.css">
</head>
<body class="auth-signup">
  <div class="auth-shell">
    <div class="auth-hero">
      <div class="auth-hero-content">
        <div class="auth-brand">
          <span class="auth-brand-badge"></span>
          City Clinic
        </div>
        <div class="hero-pill">Staff onboarding</div>
        <h1>Create your account</h1>
        <p>Give each nurse and pharmacist secure access so inventory updates are immediate and accurate.</p>
      </div>
    </div>

    <div class="auth-panel">
      <div>
        <h2>Sign Up</h2>
        <p>Register a new staff account.</p>
      </div>

      <?php if($error) echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; ?>
      <?php if($success) echo "<div class='message success'>" . htmlspecialchars($success) . "</div>"; ?>

      <form class="auth-form" method="post">
        <div class="field">
          <label>Staff ID</label>
          <input type="text" name="staff_id" required>
        </div>
        <div class="field">
          <label>Username</label>
          <input type="text" name="username" required>
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" name="email" required>
        </div>
        <div class="field">
          <label>Phone</label>
          <input type="text" name="phone" required>
        </div>
        <div class="field">
          <label>Admin Code</label>
          <div class="password-input">
            <input id="adminCode" type="password" name="admin_code" placeholder="Enter code only if creating an admin account">
            <button class="password-toggle" type="button" data-target="adminCode" aria-label="Show admin code">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path class="eye-outline" d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
                <circle class="eye-pupil" cx="12" cy="12" r="2.6" />
                <circle class="eye-dot" cx="12" cy="12" r="1.1" />
                <path class="eye-slash" d="M5 19L19 5" />
              </svg>
            </button>
          </div>
          <div class="form-helper">Leave blank to create a staff account.</div>
        </div>
        <div class="field">
          <label>Pharmacist Code</label>
          <div class="password-input">
            <input id="pharmacistCode" type="password" name="pharmacist_code" placeholder="Enter code only if creating a pharmacist account">
            <button class="password-toggle" type="button" data-target="pharmacistCode" aria-label="Show pharmacist code">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path class="eye-outline" d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
                <circle class="eye-pupil" cx="12" cy="12" r="2.6" />
                <circle class="eye-dot" cx="12" cy="12" r="1.1" />
                <path class="eye-slash" d="M5 19L19 5" />
              </svg>
            </button>
          </div>
          <div class="form-helper">Leave blank to create a staff account.</div>
        </div>
        <div class="field">
          <label>Password</label>
          <div class="password-input">
            <input id="signupPassword" type="password" name="password" required placeholder="Use 8+ chars, uppercase, number, symbol" title="Use at least 8 characters with uppercase, lowercase, a number, and a symbol">
            <button class="password-toggle" type="button" data-target="signupPassword" aria-label="Show password">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path class="eye-outline" d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
                <circle class="eye-pupil" cx="12" cy="12" r="2.6" />
                <circle class="eye-dot" cx="12" cy="12" r="1.1" />
                <path class="eye-slash" d="M5 19L19 5" />
              </svg>
            </button>
          </div>
          <div class="password-meter" aria-live="polite">
            <div class="password-meter-track"><div id="signupPasswordFill" class="password-meter-fill"></div></div>
            <div class="password-meter-label"><span id="signupPasswordLabel">Strength</span><strong id="signupPasswordText">Type your password</strong></div>
          </div>
          <div class="password-hint">Strong password: 8+ characters, upper + lower case, number, and symbol.</div>
        </div>
        <div class="field">
          <label>Confirm Password</label>
          <div class="password-input">
            <input id="confirmPassword" type="password" name="confirm" required>
            <button class="password-toggle" type="button" data-target="confirmPassword" aria-label="Show confirm password">
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
          <button class="button primary" type="submit" name="signup">Create Account</button>
        </div>
      </form>

      <div class="auth-links">
        Already have an account? <a href="index.php">Login</a>
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

      const input = document.getElementById('signupPassword');
      const fill = document.getElementById('signupPasswordFill');
      const label = document.getElementById('signupPasswordLabel');
      const text = document.getElementById('signupPasswordText');

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
</body>
</html>

<?php
include 'db.php';
date_default_timezone_set('Africa/Nairobi');

$message = '';
$messageClass = '';

if (isset($_POST['forgot'])) {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageClass = 'error';
    } else {
        $stmt = $conn->prepare("SELECT user_id, username, email FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $username = $user['username'];
            $userEmail = $user['email'];
            $token = bin2hex(random_bytes(32));
            $expiry = date("Y-m-d H:i:s", time() + (15 * 60));

            $update = $conn->prepare("UPDATE users SET reset_token = ?, token_expiry = ? WHERE user_id = ?");
            $update->bind_param("ssi", $token, $expiry, $user['user_id']);
            $update->execute();
            $update->close();

            $resetLink = clinic_base_url() . "/resetpassword.php?token=" . urlencode($token);
            $brevoApiKey = trim((string)clinic_config('BREVO_API_KEY', '
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'));
            $brevoSenderEmail = trim((string)clinic_config('BREVO_SENDER_EMAIL', 'cityclinic001@gmail.com'));
            $brevoSenderName = trim((string)clinic_config('BREVO_SENDER_NAME', 'City Clinic'));

            $sent = false;
            $deliveryError = '';
            if ($brevoApiKey !== '' && function_exists('curl_init')) {
                $data = [
                    'sender' => [
                        'name' => $brevoSenderName,
                        'email' => $brevoSenderEmail,
                    ],
                    'to' => [[
                        'email' => $userEmail,
                        'name' => $username,
                    ]],
                    'subject' => 'City Clinic Password Reset',
                    'htmlContent' => '<div style="font-family:Arial,sans-serif;color:#333;line-height:1.6;">'
                        . '<h2 style="color:#0b6c66;">Hello ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</h2>'
                        . '<p>You recently requested a password reset for your City Clinic account.</p>'
                        . '<p style="text-align:center;margin:24px 0;">'
                        . '<a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" style="background:#0b6c66;color:#fff;padding:14px 24px;text-decoration:none;border-radius:999px;font-weight:bold;display:inline-block;">'
                        . 'Reset My Password'
                        . '</a>'
                        . '</p>'
                        . '<p>This link is valid for 15 minutes.</p>'
                        . '<p>If you did not request this reset, you can safely ignore this email.</p>'
                        . '</div>',
                ];

                $ch = curl_init("https://api.brevo.com/v3/smtp/email");
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Content-Type: application/json",
                    "api-key: " . $brevoApiKey,
                ]);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                $curlError = curl_error($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $sent = $response !== false && $httpCode >= 200 && $httpCode < 300;
                if (!$sent) {
                    $deliveryError = $curlError !== ''
                        ? $curlError
                        : ('Brevo API returned HTTP ' . $httpCode . ($response ? ' with response: ' . $response : ''));
                }
            } elseif ($brevoApiKey === '') {
                $deliveryError = 'BREVO_API_KEY is missing.';
            } else {
                $deliveryError = 'PHP cURL extension is not available.';
            }

            if ($sent) {
                $message = 'If this email exists, a reset link has been sent.';
                $messageClass = 'success';
            } else {
                error_log('Forgot password email not sent for ' . $userEmail . ': ' . $deliveryError);
                $message = 'Reset token created, but email delivery is not configured yet. Add your Brevo settings in app-config.php or server environment variables.';
                $messageClass = 'error';
            }
        } else {
            $message = 'If this email exists, a reset link has been sent.';
            $messageClass = 'success';
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
<title>Forgot Password | City Clinic</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
body{
  min-height:100vh;
  display:flex;
  justify-content:center;
  align-items:center;
  padding:24px;
  background:
    linear-gradient(135deg, rgba(8,20,32,.06), rgba(255,255,255,.03)),
    url("https://source.unsplash.com/featured/1600x1200/?hospital,clinic,care") center/cover fixed no-repeat,
    radial-gradient(900px 500px at 20% 0%, rgba(255,255,255,.08), transparent 58%),
    radial-gradient(900px 500px at 80% 100%, rgba(15,118,110,.12), transparent 52%),
    #e0f0ff;
}
.login-box{
  width:min(420px, 100%);
  background:rgba(255,255,255,.1);
  border-radius:24px;
  padding:42px 34px;
  border:1px solid rgba(255,255,255,.34);
  box-shadow:0 30px 90px rgba(15,23,42,.16);
  backdrop-filter:blur(16px);
}
.logo{text-align:center;margin-bottom:24px;}
.logo i{
  font-size:56px;
  color:#0b6c66;
  text-shadow:0 10px 24px rgba(15,118,110,.2);
}
.logo h2{
  color:#0b6c66;
  font-size:28px;
  margin-top:10px;
}
.input-group{position:relative;margin-bottom:20px;}
.input-group input{
  width:100%;
  padding:14px 15px;
  border:1px solid rgba(31,124,198,.18);
  border-radius:14px;
  background:rgba(255,255,255,.32);
  color:#0b6c66;
  font-size:14px;
  outline:none;
}
.input-group label{
  position:absolute;
  top:50%;
  left:15px;
  color:#1f7cc6;
  transform:translateY(-50%);
  transition:0.3s;
  pointer-events:none;
}
.input-group input:focus ~ label,
.input-group input:valid ~ label{
  top:-10px;
  left:12px;
  font-size:12px;
  color:#0b6c66;
}
button{
  width:100%;
  padding:16px;
  border:none;
  border-radius:16px;
  background:linear-gradient(135deg, #0b6c66, #1f7cc6);
  color:#fff;
  font-weight:bold;
  font-size:16px;
  cursor:pointer;
  transition:all 0.3s;
  box-shadow:0 12px 24px rgba(15,23,42,.12);
}
button:hover{
  box-shadow:0 16px 34px rgba(15,23,42,.16);
  transform:scale(1.03);
}
.success,.error{
  padding:12px;
  text-align:center;
  border-radius:12px;
  margin-bottom:15px;
  font-weight:500;
}
.success{
  background:rgba(220,252,231,.72);
  color:#166534;
}
.error{
  background:rgba(254,226,226,.72);
  color:#991b1b;
}
.links{text-align:center;margin-top:15px;}
.links a{color:#1f7cc6;text-decoration:none;font-size:14px;}
.links a:hover{color:#0b6c66;text-decoration:underline;}
</style>
</head>
<body>

<div class="login-box">
  <div class="logo"><i class="fas fa-hospital-symbol"></i><h2>City Clinic</h2></div>
  <p style="text-align:center;color:#555;font-size:14px;margin-bottom:22px;">Forgot your password?</p>

  <?php if ($message) echo "<div class='" . htmlspecialchars($messageClass) . "'>" . htmlspecialchars($message) . "</div>"; ?>

  <form method="post">
    <div class="input-group">
      <input type="email" name="email" required>
      <label>Email</label>
    </div>
    <button type="submit" name="forgot">Reset Password</button>
  </form>

  <div class="links">
    <a href="index.php">Back to Login</a>
  </div>
</div>

</body>
</html>

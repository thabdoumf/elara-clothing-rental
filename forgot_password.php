<?php
session_start();
include("db.php");
if (file_exists(__DIR__ . '/config.local.php')) { require_once __DIR__ . '/config.local.php'; }

// ── PHPMailer ─────────────────────────────────────────────────────────────────
require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!defined('SMTP_HOST'))  define('SMTP_HOST',  'smtp.gmail.com');
if (!defined('SMTP_PORT'))  define('SMTP_PORT',  587);
if (!defined('SMTP_USER'))  define('SMTP_USER', '');
if (!defined('SMTP_PASS'))  define('SMTP_PASS', '');
if (!defined('SHOP_NAME'))  define('SHOP_NAME',  'Élara');
if (!defined('SHOP_EMAIL')) define('SHOP_EMAIL', '');
// ─────────────────────────────────────────────────────────────────────────────

$step    = 'email';   // email → code → reset → done
$message = '';
$error   = '';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION['csrf_token'];

// Clear any leftover session so page always starts fresh
if (empty($_POST)) {
    unset($_SESSION['reset_email'], $_SESSION['reset_verified'], $_SESSION['reset_code_attempts']);
}

// ── STEP 1: User submits their email ─────────────────────────────────────────
if (isset($_POST['send_code'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }
    if (!empty($_SESSION['reset_last_sent']) && time() - (int)$_SESSION['reset_last_sent'] < 60) { $error = 'Veuillez attendre une minute avant de demander un autre code.'; }
    $email = trim($_POST['email']);

    if (!empty($error)) {
        // Request throttled.
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Adresse email invalide.";
    } else {
        $check = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();
        $check->bind_result($uid, $uname);
        $check->fetch();

        if ($check->num_rows > 0) {
            $code    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['reset_last_sent'] = time();
            $_SESSION['reset_code_attempts'] = 0;
            $expires = date('Y-m-d H:i:s', time() + 900); // 15 minutes

            $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $del->bind_param("s", $email);
            $del->execute();

            $ins = $conn->prepare("INSERT INTO password_resets (email, code, expires_at) VALUES (?, ?, ?)");
            $ins->bind_param("sss", $email, $code, $expires);
            $ins->execute();

            // Send code by email
            $mail = new PHPMailer(true);
            try {
                $mail->SMTPDebug  = 0; // Set to 2 temporarily if mail fails
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom(SHOP_EMAIL, SHOP_NAME);
                $mail->addAddress($email, $uname);
                $mail->isHTML(true);
                $mail->Subject = 'Code de reinitialisation — ' . SHOP_NAME;
                $year = date('Y');
                $mail->Body = <<<HTML
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#F5F0E8;font-family:'Georgia',serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F0E8;padding:40px 0;">
  <tr><td align="center">
    <table width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;">
      <tr><td align="center" style="background:#3D2314;padding:28px 40px;border-radius:2px 2px 0 0;">
        <p style="margin:0;font-size:10px;letter-spacing:0.25em;text-transform:uppercase;color:#C4A882;">Location Vetements</p>
        <h1 style="margin:6px 0 0;font-size:28px;font-weight:700;color:#FDFAF6;">Elara</h1>
      </td></tr>
      <tr><td style="background:#FDFAF6;padding:40px 48px;border-left:1px solid #E8DDD0;border-right:1px solid #E8DDD0;">
        <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.15em;text-transform:uppercase;color:#8B5E3C;">Reinitialisation</p>
        <h2 style="margin:0 0 24px;font-size:22px;color:#3D2314;">Votre code de verification</h2>
        <div style="width:40px;height:2px;background:#C4A882;margin-bottom:24px;"></div>
        <p style="margin:0 0 28px;font-size:15px;line-height:1.7;color:#5C3D25;">
          Utilisez ce code pour reinitialiser votre mot de passe. Il expire dans <strong>15 minutes</strong>.
        </p>
        <div style="background:#F5F0E8;border:2px dashed #C4A882;border-radius:4px;padding:24px;text-align:center;margin-bottom:28px;">
          <p style="margin:0;font-size:42px;font-weight:700;color:#3D2314;letter-spacing:0.3em;font-family:monospace;">{$code}</p>
        </div>
        <p style="margin:0;font-size:13px;color:#A8896E;line-height:1.6;">
          Si vous n'avez pas demande cette reinitialisation, ignorez cet email.
        </p>
      </td></tr>
      <tr><td align="center" style="background:#E8DDD0;padding:20px 40px;border-radius:0 0 2px 2px;">
        <p style="margin:0;font-size:12px;color:#8B5E3C;">© {$year} Elara · Tous droits reserves</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
                $mail->AltBody = "Votre code Elara : {$code} (valable 15 min)";
                $mail->send();
                // Mail sent successfully — no error shown to user
            } catch (Exception $e) {
                error_log('Password reset email failed: ' . $mail->ErrorInfo);
                $del = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
                $del->bind_param('s', $email);
                $del->execute();
                $del->close();
                $error = 'Impossible d’envoyer le code pour le moment. Réessayez plus tard.';
            }
        }
        $check->close();

        if (empty($error)) {
            $_SESSION['reset_email'] = $email;
            $step    = 'code';
            $message = "Si cet email existe, un code a été envoyé.";
        }
        // If there's an error, stay on email step and show it
    }
}

// ── STEP 2: User submits the 6-digit code ────────────────────────────────────
if (isset($_POST['verify_code'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }
    $email = $_SESSION['reset_email'] ?? '';
    $code = trim($_POST['code'] ?? '');
    $attempts = (int)($_SESSION['reset_code_attempts'] ?? 0);

    if ($email === '' || $attempts >= 5) {
        $error = 'Trop de tentatives. Demandez un nouveau code.';
        $step = 'email';
        unset($_SESSION['reset_email'], $_SESSION['reset_verified'], $_SESSION['reset_code_attempts']);
    } elseif (!preg_match('/^[0-9]{6}$/', $code)) {
        $_SESSION['reset_code_attempts'] = $attempts + 1;
        $error = 'Code invalide ou expiré.';
        $step = 'code';
    } else {
        $chk = $conn->prepare("SELECT id FROM password_resets WHERE email = ? AND code = ? AND expires_at > NOW()");
        $chk->bind_param("ss", $email, $code);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $_SESSION['reset_verified'] = true;
            $_SESSION['reset_code_attempts'] = 0;
            $step = 'reset';
        } else {
            $_SESSION['reset_code_attempts'] = $attempts + 1;
            $error = 'Code invalide ou expiré.';
            $step = 'code';
        }
        $chk->close();
    }
}

// ── STEP 3: User sets new password ───────────────────────────────────────────
if (isset($_POST['new_password'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }
    $email    = $_SESSION['reset_email'] ?? '';
    $verified = $_SESSION['reset_verified'] ?? false;
    $pass1    = $_POST['password'];
    $pass2    = $_POST['password_confirm'];

    if (!$verified || empty($email)) {
        $error = "Session expirée. Recommencez.";
        $step  = 'email';
    } elseif (strlen($pass1) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
        $step  = 'reset';
    } elseif ($pass1 !== $pass2) {
        $error = "Les mots de passe ne correspondent pas.";
        $step  = 'reset';
    } else {
        $hash = password_hash($pass1, PASSWORD_DEFAULT);
        $upd  = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $upd->bind_param("ss", $hash, $email);
        $upd->execute();
        $upd->close();

        $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $del->bind_param("s", $email);
        $del->execute();
        $del->close();

        unset($_SESSION['reset_email'], $_SESSION['reset_verified']);
        $step = 'done';
    }
}

// Restore step from session only after a POST (multi-step flow)
if (!empty($_POST)) {
    if (!empty($_SESSION['reset_verified'])) $step = 'reset';
    elseif (!empty($_SESSION['reset_email']) && $step === 'email') $step = 'code';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié — Élara</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --cream:    #F5F0E8;
            --sand:     #E8DDD0;
            --clay:     #C4A882;
            --terra:    #8B5E3C;
            --espresso: #3D2314;
            --white:    #FDFAF6;
            --error:    #C0392B;
            --success:  #27AE60;
        }

        html, body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--espresso);
            overflow-x: hidden;
        }

        .page {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
        }

        /* ── Left panel ── */
        .panel-left {
            position: relative;
            background: var(--espresso);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 3rem;
            overflow: hidden;
        }

        .panel-left::before {
            content: '?';
            position: absolute;
            font-family: 'Playfair Display', serif;
            font-size: 40vw;
            font-weight: 700;
            color: rgba(196,168,130,0.04);
            line-height: 1;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
        }

        .panel-left::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                -45deg, transparent, transparent 24px,
                rgba(196,168,130,0.04) 24px, rgba(196,168,130,0.04) 25px
            );
            pointer-events: none;
        }

        .panel-brand { position: relative; z-index: 1; }
        .panel-brand .label {
            font-size: 0.7rem; letter-spacing: 0.3em;
            text-transform: uppercase; color: var(--clay); margin-bottom: 0.6rem;
        }
        .panel-brand .logo {
            font-family: 'Playfair Display', serif;
            font-size: 2.8rem; font-weight: 700; color: var(--white); line-height: 1;
        }

        .panel-steps { position: relative; z-index: 1; }
        .panel-steps h3 {
            font-size: 0.65rem; letter-spacing: 0.2em; text-transform: uppercase;
            color: var(--clay); margin-bottom: 1.5rem;
        }

        .step-item {
            display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem;
        }

        .step-num {
            width: 28px; height: 28px; border-radius: 50%;
            border: 1.5px solid var(--terra);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 500; color: var(--clay);
            flex-shrink: 0; transition: all 0.3s;
        }

        .step-num.active {
            background: var(--clay); border-color: var(--clay); color: var(--espresso);
        }

        .step-num.done {
            background: var(--terra); border-color: var(--terra); color: var(--white);
        }

        .step-text p {
            font-size: 0.85rem; font-weight: 500; color: var(--clay); margin-bottom: 0.15rem;
        }
        .step-text span { font-size: 0.75rem; color: rgba(196,168,130,0.5); }

        .orb {
            position: absolute; border-radius: 50%; pointer-events: none;
        }
        .orb-1 {
            width: 280px; height: 280px;
            background: radial-gradient(circle, rgba(139,94,60,0.18) 0%, transparent 70%);
            top: 25%; right: -80px;
        }
        .orb-2 {
            width: 180px; height: 180px;
            background: radial-gradient(circle, rgba(196,168,130,0.1) 0%, transparent 70%);
            bottom: 20%; left: -40px;
        }

        /* ── Right panel ── */
        .panel-right {
            background: var(--cream);
            display: flex; align-items: center; justify-content: center;
            padding: 3rem 2rem; position: relative;
        }

        .panel-right::before {
            content: '';
            position: absolute; inset: 0;
            background:
                radial-gradient(ellipse at 80% 10%, rgba(196,168,130,0.2) 0%, transparent 50%),
                radial-gradient(ellipse at 20% 90%, rgba(139,94,60,0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        /* ── Form card ── */
        .form-card {
            position: relative; z-index: 1;
            width: 100%; max-width: 400px;
            animation: fadeUp 0.55s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .form-heading { margin-bottom: 2rem; }
        .form-heading .tag {
            font-size: 0.68rem; letter-spacing: 0.25em; text-transform: uppercase;
            color: var(--terra); margin-bottom: 0.5rem;
        }
        .form-heading h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2rem; font-weight: 700; color: var(--espresso); line-height: 1.1;
        }
        .form-heading p {
            margin-top: 0.5rem; font-size: 0.875rem;
            color: rgba(61,35,20,0.5); font-weight: 300; line-height: 1.6;
        }

        /* Alert */
        .alert {
            padding: 0.85rem 1rem; border-radius: 2px;
            margin-bottom: 1.5rem; font-size: 0.85rem; font-weight: 500;
            display: flex; align-items: flex-start; gap: 0.6rem;
        }
        .alert-error   { background: #FCF0EF; border-left: 3px solid var(--error); color: var(--error); animation: shake 0.4s ease; }
        .alert-success { background: #EEF8F2; border-left: 3px solid var(--success); color: #1E7E45; }

        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(3px); }
        }

        /* Form elements */
        .form-group { margin-bottom: 1.25rem; }

        label {
            display: block; font-size: 0.68rem; font-weight: 500;
            letter-spacing: 0.14em; text-transform: uppercase;
            color: var(--terra); margin-bottom: 0.45rem;
        }

        .input-wrap { position: relative; }
        .input-wrap .icon {
            position: absolute; left: 0.9rem; top: 50%;
            transform: translateY(-50%); color: var(--clay);
            font-size: 1rem; pointer-events: none;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.6rem;
            border: 1.5px solid var(--sand); border-radius: 2px;
            background: var(--white); color: var(--espresso);
            font-family: 'DM Sans', sans-serif; font-size: 0.9rem;
            transition: border-color 0.2s, box-shadow 0.2s; outline: none;
        }
        input::placeholder { color: rgba(61,35,20,0.3); }
        input:focus {
            border-color: var(--terra);
            box-shadow: 0 0 0 3px rgba(139,94,60,0.1);
        }

        /* Code input special style */
        .code-input {
            text-align: center !important;
            font-size: 1.8rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.4em !important;
            padding: 1rem !important;
            font-family: monospace !important;
            color: var(--espresso) !important;
        }

        /* Password toggle */
        .toggle-pw {
            position: absolute; right: 0.9rem; top: 50%;
            transform: translateY(-50%); background: none; border: none;
            cursor: pointer; color: var(--clay); font-size: 0.85rem; padding: 0.2rem;
            transition: color 0.2s;
        }
        .toggle-pw:hover { color: var(--terra); }

        /* Password strength */
        .strength-bar { margin-top: 0.5rem; height: 3px; background: var(--sand); border-radius: 2px; overflow: hidden; }
        .strength-fill { height: 100%; width: 0%; border-radius: 2px; transition: width 0.4s, background 0.4s; }
        .strength-label { font-size: 0.68rem; color: var(--clay); margin-top: 0.3rem; min-height: 1em; }

        /* Timer */
        .code-timer {
            text-align: center; font-size: 0.78rem;
            color: rgba(61,35,20,0.45); margin-top: 0.6rem;
        }
        .code-timer span { color: var(--terra); font-weight: 500; }

        /* Submit button */
        .btn-submit {
            width: 100%; padding: 0.95rem;
            background: var(--espresso); color: var(--white);
            border: none; border-radius: 2px;
            font-family: 'DM Sans', sans-serif; font-size: 0.875rem;
            font-weight: 500; letter-spacing: 0.14em; text-transform: uppercase;
            cursor: pointer; position: relative; overflow: hidden;
            transition: transform 0.15s; margin-top: 0.5rem;
        }
        .btn-submit::before {
            content: ''; position: absolute; inset: 0;
            background: var(--terra); transform: translateX(-100%);
            transition: transform 0.35s ease;
        }
        .btn-submit:hover::before { transform: translateX(0); }
        .btn-submit:active { transform: scale(0.98); }
        .btn-submit span { position: relative; z-index: 1; }

        /* Back link */
        .back-link {
            display: block; text-align: center; margin-top: 1.5rem;
            font-size: 0.82rem; color: rgba(61,35,20,0.5);
            text-decoration: none;
            transition: color 0.2s;
        }
        .back-link:hover { color: var(--terra); }

        /* Success state */
        .success-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: #EEF8F2; border: 2px solid #27AE60;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; margin: 0 auto 1.5rem;
            animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) both;
        }
        @keyframes popIn {
            from { transform: scale(0); opacity: 0; }
            to   { transform: scale(1); opacity: 1; }
        }

        .success-card { text-align: center; }
        .success-card h1 {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem; color: var(--espresso); margin-bottom: 0.75rem;
        }
        .success-card p { font-size: 0.9rem; color: rgba(61,35,20,0.55); line-height: 1.7; margin-bottom: 2rem; }

        .btn-login {
            display: inline-block; padding: 0.9rem 2.5rem;
            background: var(--espresso); color: var(--white);
            border-radius: 2px; text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
            letter-spacing: 0.14em; text-transform: uppercase;
            transition: background 0.25s;
        }
        .btn-login:hover { background: var(--terra); }

        @media (max-width: 768px) {
            .page { grid-template-columns: 1fr; }
            .panel-left { display: none; }
        }
    </style>
</head>
<body>
<div class="page">

    <!-- ── Left panel ── -->
    <div class="panel-left">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>

        <div class="panel-brand">
            <p class="label">Location Vêtements</p>
            <p class="logo">Élara</p>
        </div>

        <div class="panel-steps">
            <h3>Étapes de récupération</h3>

            <div class="step-item">
                <div class="step-num <?php echo in_array($step, ['email']) ? 'active' : (in_array($step, ['code','reset','done']) ? 'done' : ''); ?>">
                    <?php echo in_array($step, ['code','reset','done']) ? '✓' : '1'; ?>
                </div>
                <div class="step-text">
                    <p>Email</p>
                    <span>Entrez votre adresse email</span>
                </div>
            </div>

            <div class="step-item">
                <div class="step-num <?php echo $step === 'code' ? 'active' : ($step === 'reset' || $step === 'done' ? 'done' : ''); ?>">
                    <?php echo ($step === 'reset' || $step === 'done') ? '✓' : '2'; ?>
                </div>
                <div class="step-text">
                    <p>Code de vérification</p>
                    <span>Vérifiez votre boîte mail</span>
                </div>
            </div>

            <div class="step-item">
                <div class="step-num <?php echo $step === 'reset' ? 'active' : ($step === 'done' ? 'done' : ''); ?>">
                    <?php echo $step === 'done' ? '✓' : '3'; ?>
                </div>
                <div class="step-text">
                    <p>Nouveau mot de passe</p>
                    <span>Choisissez un mot de passe fort</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Right panel ── -->
    <div class="panel-right">
        <div class="form-card">

        <?php if ($step === 'done'): ?>
            <!-- ── SUCCESS ── -->
            <div class="success-card">
                <div class="success-icon">✓</div>
                <h1>Mot de passe mis à jour !</h1>
                <p>Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.</p>
                <a href="login.php" class="btn-login">Se connecter →</a>
            </div>

        <?php elseif ($step === 'reset'): ?>
            <!-- ── STEP 3: New password ── -->
            <div class="form-heading">
                <p class="tag">Étape 3 / 3</p>
                <h1>Nouveau mot de passe</h1>
                <p>Choisissez un mot de passe sécurisé d'au moins 6 caractères.</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error">⚠ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form-group">
                    <label for="password">Nouveau mot de passe</label>
                    <div class="input-wrap">
                        <span class="icon">🔑</span>
                        <input type="password" id="password" name="password"
                               placeholder="••••••••" required
                               oninput="checkStrength(this.value)">
                        <button type="button" class="toggle-pw" onclick="togglePw('password', this)">👁</button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <div class="strength-label" id="strengthLabel"></div>
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirmer le mot de passe</label>
                    <div class="input-wrap">
                        <span class="icon">🔒</span>
                        <input type="password" id="password_confirm" name="password_confirm"
                               placeholder="••••••••" required>
                        <button type="button" class="toggle-pw" onclick="togglePw('password_confirm', this)">👁</button>
                    </div>
                </div>

                <button type="submit" name="new_password" class="btn-submit">
                    <span>Enregistrer le mot de passe</span>
                </button>
            </form>

        <?php elseif ($step === 'code'): ?>
            <!-- ── STEP 2: Enter code ── -->
            <div class="form-heading">
                <p class="tag">Étape 2 / 3</p>
                <h1>Code de vérification</h1>
                <p>Entrez le code à 6 chiffres envoyé à <strong><?php echo htmlspecialchars($_SESSION['reset_email'] ?? ''); ?></strong>.</p>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-error">⚠ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form-group">
                    <label for="code">Code à 6 chiffres</label>
                    <div class="input-wrap">
                        <input type="text" id="code" name="code" class="code-input"
                               placeholder="000000" maxlength="6"
                               pattern="[0-9]{6}" inputmode="numeric"
                               autocomplete="one-time-code" required
                               oninput="this.value=this.value.replace(/\D/g,'')">
                    </div>
                    <div class="code-timer">Code valable <span id="timer">15:00</span></div>
                </div>

                <button type="submit" name="verify_code" class="btn-submit">
                    <span>Vérifier le code</span>
                </button>
            </form>

            <a href="forgot_password.php" class="back-link">← Renvoyer un nouveau code</a>

        <?php else: ?>
            <!-- ── STEP 1: Enter email ── -->
            <div class="form-heading">
                <p class="tag">Étape 1 / 3</p>
                <h1>Mot de passe oublié ?</h1>
                <p>Entrez votre adresse email et nous vous enverrons un code de vérification.</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error">⚠ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form-group">
                    <label for="email">Adresse email</label>
                    <div class="input-wrap">
                        <span class="icon">✉</span>
                        <input type="email" id="email" name="email"
                               placeholder="exemple@mail.com"
                               value=""
                               required autocomplete="off">
                    </div>
                </div>

                <button type="submit" name="send_code" class="btn-submit">
                    <span>Envoyer le code</span>
                </button>
            </form>

            <a href="login.php" class="back-link">← Retour à la connexion</a>
        <?php endif; ?>

        </div>
    </div>
</div>

<script>
function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
}

function checkStrength(val) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    if (!fill) return;
    let score = 0;
    if (val.length >= 6)           score++;
    if (val.length >= 10)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        { pct:'0%',   color:'transparent', text:'' },
        { pct:'25%',  color:'#C0392B',     text:'Très faible' },
        { pct:'50%',  color:'#E67E22',     text:'Faible' },
        { pct:'75%',  color:'#F1C40F',     text:'Moyen' },
        { pct:'88%',  color:'#2ECC71',     text:'Fort' },
        { pct:'100%', color:'#27AE60',     text:'Très fort' },
    ];
    const lvl = val.length === 0 ? levels[0] : levels[Math.min(score, 5)];
    fill.style.width      = lvl.pct;
    fill.style.background = lvl.color;
    label.textContent     = lvl.text;
    label.style.color     = lvl.color;
}

const timerEl = document.getElementById('timer');
if (timerEl) {
    let total = 15 * 60;
    const tick = setInterval(() => {
        total--;
        const m = String(Math.floor(total / 60)).padStart(2, '0');
        const s = String(total % 60).padStart(2, '0');
        timerEl.textContent = m + ':' + s;
        if (total <= 0) {
            clearInterval(tick);
            timerEl.textContent = 'expiré';
            timerEl.style.color = '#C0392B';
        }
    }, 1000);
}
</script>

</body>
</html>
<?php
session_start();
include("db.php");

$error = "";
if (empty($_SESSION["csrf_token"])) { $_SESSION["csrf_token"] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION["csrf_token"];
$attempts_key = 'login_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$lockout_key  = 'login_lockout_'  . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

// Simple brute-force protection using session
if (!isset($_SESSION[$attempts_key])) $_SESSION[$attempts_key] = 0;
if (!isset($_SESSION[$lockout_key]))  $_SESSION[$lockout_key]  = 0;

$is_locked   = $_SESSION[$lockout_key] > time();
$attempts    = $_SESSION[$attempts_key];
$remaining   = max(0, $_SESSION[$lockout_key] - time());

if (isset($_POST['login']) && !$is_locked) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }

    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Veuillez remplir tous les champs.";
    } else {

        $stmt = $conn->prepare("SELECT id, name, role, password FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($id, $name, $role, $hash);
        $stmt->fetch();

        if ($stmt->num_rows > 0 && password_verify($password, $hash)) {

            // Reset attempts on success
            $_SESSION[$attempts_key] = 0;
            $_SESSION[$lockout_key]  = 0;

            session_regenerate_id(true);
            $_SESSION['user_id']   = $id;
            $_SESSION['user_name'] = $name;
            $_SESSION['role']      = $role;

            if ($role === 'admin') {
                header("Location: dashboard.php");
            } else {
                header("Location: index.php");
            }
            exit();

        } else {
            $_SESSION[$attempts_key]++;
            $attempts = $_SESSION[$attempts_key];

            if ($attempts >= 5) {
                $_SESSION[$lockout_key] = time() + 120; // 2 minute lockout
                $is_locked = true;
                $remaining = 120;
                $error = "";
            } else {
                $left  = 5 - $attempts;
                $error = "Email ou mot de passe incorrect. ($left tentative" . ($left > 1 ? 's' : '') . " restante" . ($left > 1 ? 's' : '') . ")";
            }
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — Élara</title>
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
        }

        html, body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background: var(--espresso);
            color: var(--espresso);
            overflow-x: hidden;
        }

        /* ── Split layout ── */
        .page {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
        }

        /* ── Left panel — decorative ── */
        .panel-left {
            position: relative;
            background: var(--espresso);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 3rem;
            overflow: hidden;
        }

        /* Big decorative E letter */
        .panel-left::before {
            content: 'É';
            position: absolute;
            font-family: 'Playfair Display', serif;
            font-size: 38vw;
            font-weight: 700;
            color: rgba(196,168,130,0.04);
            line-height: 1;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
            user-select: none;
        }

        /* Diagonal lines texture */
        .panel-left::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                -45deg,
                transparent,
                transparent 24px,
                rgba(196,168,130,0.04) 24px,
                rgba(196,168,130,0.04) 25px
            );
            pointer-events: none;
        }

        .panel-brand {
            position: relative;
            z-index: 1;
        }

        .panel-brand .label {
            font-size: 0.7rem;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--clay);
            margin-bottom: 0.6rem;
        }

        .panel-brand .logo {
            font-family: 'Playfair Display', serif;
            font-size: 2.8rem;
            font-weight: 700;
            color: var(--white);
            line-height: 1;
        }

        .panel-quote {
            position: relative;
            z-index: 1;
        }

        .panel-quote blockquote {
            font-family: 'Playfair Display', serif;
            font-style: italic;
            font-size: 1.35rem;
            color: var(--clay);
            line-height: 1.6;
            border-left: 2px solid var(--terra);
            padding-left: 1.25rem;
            margin-bottom: 1rem;
        }

        .panel-quote cite {
            font-size: 0.75rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: rgba(196,168,130,0.5);
        }

        /* Floating orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .orb-1 {
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(139,94,60,0.2) 0%, transparent 70%);
            top: 20%; right: -100px;
        }

        .orb-2 {
            width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(196,168,130,0.12) 0%, transparent 70%);
            bottom: 15%; left: -50px;
        }

        /* ── Right panel — form ── */
        .panel-right {
            background: var(--cream);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            position: relative;
        }

        .panel-right::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at 80% 10%, rgba(196,168,130,0.2) 0%, transparent 50%),
                radial-gradient(ellipse at 20% 90%, rgba(139,94,60,0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        /* ── Form card ── */
        .form-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 400px;
            animation: fadeUp 0.6s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .form-heading {
            margin-bottom: 2.5rem;
        }

        .form-heading .welcome {
            font-size: 0.7rem;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: var(--terra);
            margin-bottom: 0.5rem;
        }

        .form-heading h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--espresso);
            line-height: 1.1;
        }

        .form-heading p {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: rgba(61,35,20,0.5);
            font-weight: 300;
        }

        /* ── Alert ── */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 2px;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%      { transform: translateX(-6px); }
            40%      { transform: translateX(6px); }
            60%      { transform: translateX(-4px); }
            80%      { transform: translateX(4px); }
        }

        .alert-error {
            background: #FCF0EF;
            border-left: 3px solid var(--error);
            color: var(--error);
        }

        .alert-lockout {
            background: #FEF9EC;
            border-left: 3px solid #E67E22;
            color: #8B4513;
        }

        /* ── Form fields ── */
        .form-group {
            margin-bottom: 1.25rem;
            position: relative;
        }

        label {
            display: block;
            font-size: 0.68rem;
            font-weight: 500;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--terra);
            margin-bottom: 0.45rem;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap .icon {
            position: absolute;
            left: 0.9rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--clay);
            font-size: 1rem;
            pointer-events: none;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.6rem;
            border: 1.5px solid var(--sand);
            border-radius: 2px;
            background: var(--white);
            color: var(--espresso);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            font-weight: 400;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }

        input::placeholder { color: rgba(61,35,20,0.3); }

        input:focus {
            border-color: var(--terra);
            box-shadow: 0 0 0 3px rgba(139,94,60,0.1);
        }

        /* Show/hide password toggle */
        .toggle-pw {
            position: absolute;
            right: 0.9rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--clay);
            font-size: 0.85rem;
            padding: 0.2rem;
            transition: color 0.2s;
            line-height: 1;
        }

        .toggle-pw:hover { color: var(--terra); }

        /* Remember me + forgot */
        .form-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.75rem;
            margin-top: -0.25rem;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .remember input[type="checkbox"] {
            width: 15px;
            height: 15px;
            accent-color: var(--espresso);
            cursor: pointer;
        }

        .remember span {
            font-size: 0.8rem;
            color: rgba(61,35,20,0.6);
        }

        .forgot {
            font-size: 0.8rem;
            color: var(--terra);
            text-decoration: none;
            border-bottom: 1px solid transparent;
            transition: border-color 0.2s;
        }

        .forgot:hover { border-color: var(--terra); }

        /* ── Submit button ── */
        .btn-submit {
            width: 100%;
            padding: 0.95rem;
            background: var(--espresso);
            color: var(--white);
            border: none;
            border-radius: 2px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.875rem;
            font-weight: 500;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform 0.15s;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--terra);
            transform: translateX(-100%);
            transition: transform 0.35s ease;
        }

        .btn-submit:hover::before  { transform: translateX(0); }
        .btn-submit:active         { transform: scale(0.98); }
        .btn-submit:disabled       { opacity: 0.5; cursor: not-allowed; }

        .btn-submit span { position: relative; z-index: 1; }

        /* Attempt dots */
        .attempt-dots {
            display: flex;
            gap: 0.35rem;
            justify-content: center;
            margin-top: 0.75rem;
        }

        .dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            border: 1.5px solid var(--clay);
            transition: background 0.3s;
        }

        .dot.used { background: var(--error); border-color: var(--error); }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 1.75rem 0;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--sand);
        }

        .divider span {
            font-size: 0.7rem;
            letter-spacing: 0.1em;
            color: var(--clay);
            text-transform: uppercase;
        }

        /* Register link */
        .register-link {
            text-align: center;
        }

        .register-link p {
            font-size: 0.85rem;
            color: rgba(61,35,20,0.55);
        }

        .register-link a {
            color: var(--terra);
            font-weight: 500;
            text-decoration: none;
            border-bottom: 1px solid var(--clay);
            transition: color 0.2s, border-color 0.2s;
        }

        .register-link a:hover {
            color: var(--espresso);
            border-color: var(--espresso);
        }

        /* ── Mobile ── */
        @media (max-width: 768px) {
            .page { grid-template-columns: 1fr; }
            .panel-left { display: none; }
            .panel-right { background: var(--cream); min-height: 100vh; }
        }
    </style>
</head>
<body>

<div class="page">

    <!-- ── Left decorative panel ── -->
    <div class="panel-left">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>

        <div class="panel-brand">
            <p class="label">Location Vêtements</p>
            <p class="logo">Élara</p>
        </div>

        <div class="panel-quote">
            <blockquote>
                "La mode est une armure pour affronter la vie de tous les jours."
            </blockquote>
            <cite>— Mary Quant</cite>
        </div>
    </div>

    <!-- ── Right form panel ── -->
    <div class="panel-right">
        <div class="form-card">

            <div class="form-heading">
                <p class="welcome">Bienvenue</p>
                <h1>Connexion</h1>
                <p>Connectez-vous pour accéder à votre espace.</p>
            </div>

            <?php if ($is_locked): ?>
            <div class="alert alert-lockout">
                🔒 Trop de tentatives. Réessayez dans <strong id="countdown"><?php echo $remaining; ?></strong> secondes.
            </div>
            <?php elseif ($error): ?>
            <div class="alert alert-error">
                ⚠ <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" novalidate>
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="form-group">
                    <label for="email">Adresse email</label>
                    <div class="input-wrap">
                        <span class="icon">✉</span>
                        <input type="email" id="email" name="email"
                               placeholder="exemple@mail.com"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                               <?php echo $is_locked ? 'disabled' : ''; ?>
                               required autocomplete="email">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <div class="input-wrap">
                        <span class="icon">🔑</span>
                        <input type="password" id="password" name="password"
                               placeholder="••••••••"
                               <?php echo $is_locked ? 'disabled' : ''; ?>
                               required autocomplete="current-password">
                        <button type="button" class="toggle-pw" onclick="togglePw()" title="Afficher/masquer">
                            👁
                        </button>
                    </div>
                </div>

                <div class="form-meta">
                    <label class="remember">
                        <input type="checkbox" name="remember">
                        <span>Se souvenir de moi</span>
                    </label>
                    <a href="forgot_password.php" class="forgot">Mot de passe oublié ?</a>
                </div>

                <button type="submit" name="login" class="btn-submit" <?php echo $is_locked ? 'disabled' : ''; ?>>
                    <span>Se connecter</span>
                </button>

                <?php if ($attempts > 0 && !$is_locked): ?>
                <div class="attempt-dots">
                    <?php for ($i = 0; $i < 5; $i++): ?>
                    <div class="dot <?php echo $i < $attempts ? 'used' : ''; ?>"></div>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

            </form>

            <div class="divider"><span>ou</span></div>

            <div class="register-link">
                <p>Pas encore de compte ? <a href="register.php">Créer un compte</a></p>
            </div>

        </div>
    </div>

</div>

<script>
// Toggle password visibility
function togglePw() {
    const input = document.getElementById('password');
    const btn   = document.querySelector('.toggle-pw');
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
    } else {
        input.type = 'password';
        btn.textContent = '👁';
    }
}

// Lockout countdown timer
const countdownEl = document.getElementById('countdown');
if (countdownEl) {
    let secs = parseInt(countdownEl.textContent);
    const timer = setInterval(() => {
        secs--;
        countdownEl.textContent = secs;
        if (secs <= 0) {
            clearInterval(timer);
            location.reload();
        }
    }, 1000);
}
</script>

</body>
</html>
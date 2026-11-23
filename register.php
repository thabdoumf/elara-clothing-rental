<?php
ob_start(); // buffer output so die() works even after headers
if (file_exists(__DIR__ . '/config.local.php')) { require_once __DIR__ . '/config.local.php'; }

// ─── PHPMailer (manual load — no Composer needed) ───────────────────────────
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

include("db.php");

$error = "";

// ── SMTP config ──────────────────────────────────────────────────────────────
if (!defined('SMTP_HOST')) define('SMTP_HOST',  'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT',  587);
if (!defined('SMTP_USER')) define('SMTP_USER', '');
if (!defined('SMTP_PASS')) define('SMTP_PASS', '');
if (!defined('SHOP_NAME')) define('SHOP_NAME',  'Élara');
if (!defined('SHOP_EMAIL'))define('SHOP_EMAIL', '');
// ─────────────────────────────────────────────────────────────────────────────

function sendWelcomeMail(string $toEmail, string $toName): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SHOP_EMAIL, SHOP_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(SHOP_EMAIL, SHOP_NAME);

        $mail->isHTML(true);
        $mail->Subject = '✅ Bienvenue chez ' . SHOP_NAME . ' — Compte créé avec succès';

        // ── Beautiful HTML email body ──────────────────────────────────────
        $firstName = explode(' ', trim($toName))[0];
        $year      = date('Y');
        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#F5F0E8;font-family:'Georgia',serif;">

  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F0E8;padding:40px 0;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">

        <!-- Logo / Brand bar -->
        <tr>
          <td align="center" style="background:#3D2314;padding:32px 40px 28px;border-radius:2px 2px 0 0;">
            <p style="margin:0;font-size:11px;letter-spacing:0.25em;text-transform:uppercase;color:#C4A882;">Location Vêtements</p>
            <h1 style="margin:8px 0 0;font-family:'Georgia',serif;font-size:32px;font-weight:700;color:#FDFAF6;letter-spacing:0.02em;">Élara</h1>
          </td>
        </tr>

        <!-- Main card -->
        <tr>
          <td style="background:#FDFAF6;padding:44px 48px 36px;border-left:1px solid #E8DDD0;border-right:1px solid #E8DDD0;">

            <!-- Greeting -->
            <p style="margin:0 0 6px;font-size:13px;letter-spacing:0.15em;text-transform:uppercase;color:#8B5E3C;">Bienvenue,</p>
            <h2 style="margin:0 0 24px;font-size:26px;color:#3D2314;font-weight:600;">$firstName !</h2>

            <!-- Divider line -->
            <div style="width:48px;height:2px;background:#C4A882;margin-bottom:28px;"></div>

            <p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#5C3D25;">
              Votre compte <strong>Élara</strong> a été créé avec succès. Nous sommes ravis de vous accueillir dans notre boutique de location de vêtements.
            </p>

            <p style="margin:0 0 32px;font-size:15px;line-height:1.7;color:#5C3D25;">
              Vous pouvez dès maintenant parcourir notre collection, réserver vos tenues préférées et profiter d'une expérience mode unique.
            </p>

            <!-- CTA Button -->
            <table cellpadding="0" cellspacing="0" style="margin-bottom:36px;">
              <tr>
                <td style="background:#3D2314;border-radius:2px;">
                  <a href="http://localhost/licence/login.php"
                     style="display:inline-block;padding:14px 36px;color:#FDFAF6;font-family:'Georgia',serif;font-size:14px;letter-spacing:0.12em;text-transform:uppercase;text-decoration:none;">
                    Se connecter →
                  </a>
                </td>
              </tr>
            </table>

            <!-- Info box -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F0E8;border-left:3px solid #C4A882;margin-bottom:8px;">
              <tr>
                <td style="padding:16px 20px;">
                  <p style="margin:0;font-size:13px;color:#8B5E3C;line-height:1.6;">
                    📧 <strong>Email enregistré :</strong> $toEmail<br>
                    🔒 Gardez vos identifiants en sécurité et ne les partagez jamais.
                  </p>
                </td>
              </tr>
            </table>

          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td align="center" style="background:#E8DDD0;padding:22px 40px;border-radius:0 0 2px 2px;">
            <p style="margin:0;font-size:12px;color:#8B5E3C;">© $year Élara · Tous droits réservés</p>
            <p style="margin:6px 0 0;font-size:11px;color:#A8896E;">Vous recevez cet e-mail car vous vous êtes inscrit(e) sur notre site.</p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>
HTML;

        // Plain-text fallback
        $mail->AltBody = "Bienvenue chez Élara, $firstName !\n\nVotre compte a été créé avec succès.\nEmail : $toEmail\n\nConnectez-vous sur : http://localhost/licence/login.php\n\n© $year Élara";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Welcome email failed: ' . $mail->ErrorInfo);
        return false;
    }
}

if (isset($_POST['register'])) {

    $name      = trim($_POST['name']);
    $email     = trim($_POST['email']);
    $password  = $_POST['password'];
    $wilaya    = trim($_POST['wilaya']);
    $dob       = trim($_POST['dob']);
    $gender    = trim($_POST['gender']);

    /* Basic validation */
    if (empty($name) || empty($email) || empty($password) || empty($wilaya) || empty($dob)) {
        $error = "Veuillez remplir tous les champs obligatoires.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Adresse email invalide.";

    } elseif (strlen($password) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";

    } else {

        /* Check duplicate email */
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Cet email est déjà utilisé.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare(
                "INSERT INTO users (name, email, password, wilaya, dob, gender, role) VALUES (?, ?, ?, ?, ?, ?, 'user')"
            );
            $stmt->bind_param("ssssss", $name, $email, $hash, $wilaya, $dob, $gender);
            $stmt->execute();
            $stmt->close();

            // Send welcome email
            $mailOk = sendWelcomeMail($email, $name);
            if ($mailOk) {
                header("Location: login.php");
                exit();
            }
            // Mail failed — error shown on screen above, don't redirect
        }

        $check->close();
    }
}

$wilayas = [
    "01 - Adrar", "02 - Chlef", "03 - Laghouat", "04 - Oum El Bouaghi",
    "05 - Batna", "06 - Béjaïa", "07 - Biskra", "08 - Béchar",
    "09 - Blida", "10 - Bouira", "11 - Tamanrasset", "12 - Tébessa",
    "13 - Tlemcen", "14 - Tiaret", "15 - Tizi Ouzou", "16 - Alger",
    "17 - Djelfa", "18 - Jijel", "19 - Sétif", "20 - Saïda",
    "21 - Skikda", "22 - Sidi Bel Abbès", "23 - Annaba", "24 - Guelma",
    "25 - Constantine", "26 - Médéa", "27 - Mostaganem", "28 - M'Sila",
    "29 - Mascara", "30 - Ouargla", "31 - Oran", "32 - El Bayadh",
    "33 - Illizi", "34 - Bordj Bou Arréridj", "35 - Boumerdès", "36 - El Tarf",
    "37 - Tindouf", "38 - Tissemsilt", "39 - El Oued", "40 - Khenchela",
    "41 - Souk Ahras", "42 - Tipaza", "43 - Mila", "44 - Aïn Defla",
    "45 - Naâma", "46 - Aïn Témouchent", "47 - Ghardaïa", "48 - Relizane",
    "49 - Timimoun", "50 - Bordj Badji Mokhtar", "51 - Ouled Djellal",
    "52 - Béni Abbès", "53 - In Salah", "54 - In Guezzam",
    "55 - Touggourt", "56 - Djanet", "57 - El M'Ghair", "58 - El Meniaa"
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription — Location Vêtements</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --cream:   #F5F0E8;
            --sand:    #E8DDD0;
            --clay:    #C4A882;
            --terra:   #8B5E3C;
            --espresso:#3D2314;
            --dark:    #1A0F08;
            --white:   #FDFAF6;
            --error:   #C0392B;
            --success: #27AE60;
        }

        html, body {
            min-height: 100vh;
            font-family: 'DM Sans', sans-serif;
            background-color: var(--cream);
            color: var(--espresso);
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Decorative background texture */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(ellipse at 15% 20%, rgba(196,168,130,0.25) 0%, transparent 55%),
                radial-gradient(ellipse at 85% 80%, rgba(139,94,60,0.15) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* Diagonal stripe accent */
        body::after {
            content: '';
            position: fixed;
            top: -20%;
            right: -10%;
            width: 40vw;
            height: 140vh;
            background: repeating-linear-gradient(
                -55deg,
                transparent,
                transparent 18px,
                rgba(196,168,130,0.08) 18px,
                rgba(196,168,130,0.08) 19px
            );
            pointer-events: none;
            z-index: 0;
        }

        /* ── Card ── */
        .card {
            position: relative;
            z-index: 1;
            background: var(--white);
            border-radius: 2px;
            width: 100%;
            max-width: 520px;
            box-shadow:
                0 2px 4px rgba(61,35,20,0.06),
                0 12px 40px rgba(61,35,20,0.12),
                0 40px 80px rgba(61,35,20,0.08);
            overflow: hidden;
        }

        /* ── Header bar ── */
        .card-header {
            background: var(--espresso);
            padding: 2.5rem 2.5rem 2rem;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            bottom: -30px;
            right: -30px;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 2px solid rgba(196,168,130,0.3);
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: -60px;
            right: -60px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            border: 1px solid rgba(196,168,130,0.15);
        }

        .brand {
            font-family: 'Playfair Display', serif;
            color: var(--clay);
            font-size: 0.75rem;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
            font-weight: 400;
        }

        .card-header h1 {
            font-family: 'Playfair Display', serif;
            color: var(--white);
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.1;
        }

        .card-header p {
            color: rgba(253,250,246,0.5);
            font-size: 0.875rem;
            margin-top: 0.4rem;
            font-weight: 300;
        }

        /* ── Body ── */
        .card-body {
            padding: 2.5rem;
        }

        /* ── Alert ── */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 2px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .alert-error {
            background: #FCF0EF;
            border-left: 3px solid var(--error);
            color: var(--error);
        }

        /* ── Form layout ── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group.full { grid-column: 1 / -1; }

        label {
            display: block;
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--terra);
            margin-bottom: 0.4rem;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="date"],
        select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1.5px solid var(--sand);
            border-radius: 2px;
            background: var(--cream);
            color: var(--espresso);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            font-weight: 400;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
        }

        input::placeholder { color: rgba(61,35,20,0.35); }

        input:focus, select:focus {
            border-color: var(--terra);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(139,94,60,0.08);
        }

        /* Custom select arrow */
        .select-wrap {
            position: relative;
        }

        .select-wrap::after {
            content: '▾';
            position: absolute;
            right: 0.9rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--clay);
            pointer-events: none;
            font-size: 0.85rem;
        }

        /* Gender radio pills */
        .gender-group {
            display: flex;
            gap: 0.6rem;
        }

        .gender-pill input { display: none; }

        .gender-pill label {
            display: inline-block;
            padding: 0.55rem 1.1rem;
            border: 1.5px solid var(--sand);
            border-radius: 100px;
            cursor: pointer;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            color: var(--terra);
            background: var(--cream);
            transition: all 0.2s;
            text-transform: none;
        }

        .gender-pill input:checked + label {
            background: var(--espresso);
            border-color: var(--espresso);
            color: var(--white);
        }

        /* Password strength bar */
        .strength-bar {
            margin-top: 0.5rem;
            height: 3px;
            background: var(--sand);
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            border-radius: 2px;
            transition: width 0.4s, background 0.4s;
        }

        .strength-label {
            font-size: 0.7rem;
            color: var(--clay);
            margin-top: 0.3rem;
            min-height: 1em;
        }

        /* Divider */
        .divider {
            height: 1px;
            background: var(--sand);
            margin: 1.5rem 0;
        }

        /* Submit button */
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
            letter-spacing: 0.12em;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.25s, transform 0.15s;
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--terra);
            transform: translateX(-100%);
            transition: transform 0.35s ease;
        }

        .btn-submit:hover::before { transform: translateX(0); }

        .btn-submit span { position: relative; z-index: 1; }

        .btn-submit:active { transform: scale(0.98); }

        /* Footer link */
        .card-footer {
            text-align: center;
            padding: 1.25rem 2.5rem 2rem;
            border-top: 1px solid var(--sand);
        }

        .card-footer p {
            font-size: 0.85rem;
            color: rgba(61,35,20,0.55);
        }

        .card-footer a {
            color: var(--terra);
            font-weight: 500;
            text-decoration: none;
            border-bottom: 1px solid var(--clay);
            transition: color 0.2s, border-color 0.2s;
        }

        .card-footer a:hover {
            color: var(--espresso);
            border-color: var(--espresso);
        }

        /* Animate in */
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .card { animation: slideUp 0.5s ease both; }

        @media (max-width: 480px) {
            .form-row { grid-template-columns: 1fr; }
            .card-body, .card-header { padding: 1.75rem; }
        }
    </style>
</head>
<body>

<div class="card">

    <!-- Header -->
    <div class="card-header">
        <p class="brand">Location Vêtements</p>
        <h1>Inscription</h1>
        <p>Créez votre compte gratuitement</p>
    </div>

    <!-- Body -->
    <div class="card-body">

        <?php if ($error): ?>
        <div class="alert alert-error">
            ⚠ <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>

            <!-- Row 1: Name -->
            <div class="form-group">
                <label for="name">Nom complet *</label>
                <input type="text" id="name" name="name"
                       placeholder="Ex: Ahmed Benali"
                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                       required>
            </div>

            <!-- Row 2: Email -->
            <div class="form-group">
                <label for="email">Adresse email *</label>
                <input type="email" id="email" name="email"
                       placeholder="exemple@mail.com"
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                       required>
            </div>

            <!-- Row 3: DOB + Gender side by side -->
            <div class="form-row">
                <div class="form-group">
                    <label for="dob">Date de naissance *</label>
                    <input type="date" id="dob" name="dob"
                           max="<?php echo date('Y-m-d', strtotime('-13 years')); ?>"
                           value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>"
                           required>
                </div>

                <div class="form-group">
                    <label>Genre</label>
                    <div class="gender-group">
                        <div class="gender-pill">
                            <input type="radio" id="genre-homme" name="gender" value="homme"
                                <?php echo (isset($_POST['gender']) && $_POST['gender']==='homme') ? 'checked' : ''; ?>>
                            <label for="genre-homme">Homme</label>
                        </div>
                        <div class="gender-pill">
                            <input type="radio" id="genre-femme" name="gender" value="femme"
                                <?php echo (isset($_POST['gender']) && $_POST['gender']==='femme') ? 'checked' : ''; ?>>
                            <label for="genre-femme">Femme</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 4: Wilaya -->
            <div class="form-group">
                <label for="wilaya">Wilaya *</label>
                <div class="select-wrap">
                    <select id="wilaya" name="wilaya" required>
                        <option value="" disabled <?php echo !isset($_POST['wilaya']) ? 'selected' : ''; ?>>
                            Sélectionnez votre wilaya
                        </option>
                        <?php foreach ($wilayas as $w): ?>
                        <option value="<?php echo $w; ?>"
                            <?php echo (isset($_POST['wilaya']) && $_POST['wilaya'] === $w) ? 'selected' : ''; ?>>
                            <?php echo $w; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Row 5: Password -->
            <div class="form-group">
                <label for="password">Mot de passe *</label>
                <input type="password" id="password" name="password"
                       placeholder="Minimum 6 caractères"
                       required oninput="checkStrength(this.value)">
                <div class="strength-bar">
                    <div class="strength-fill" id="strengthFill"></div>
                </div>
                <div class="strength-label" id="strengthLabel"></div>
            </div>

            <div class="divider"></div>

            <button type="submit" name="register" class="btn-submit">
                <span>Créer mon compte</span>
            </button>

        </form>
    </div>

    <!-- Footer -->
    <div class="card-footer">
        <p>Déjà inscrit ? <a href="login.php">Se connecter</a></p>
    </div>

</div>

<script>
function checkStrength(val) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '0%',   color: 'transparent', text: '' },
        { pct: '25%',  color: '#C0392B',     text: 'Très faible' },
        { pct: '50%',  color: '#E67E22',     text: 'Faible' },
        { pct: '75%',  color: '#F1C40F',     text: 'Moyen' },
        { pct: '88%',  color: '#2ECC71',     text: 'Fort' },
        { pct: '100%', color: '#27AE60',     text: 'Très fort' },
    ];

    const lvl = val.length === 0 ? levels[0] : levels[Math.min(score, 5)];
    fill.style.width      = lvl.pct;
    fill.style.background = lvl.color;
    label.textContent     = lvl.text;
    label.style.color     = lvl.color;
}
</script>

</body>
</html>
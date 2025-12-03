<?php
require_once 'db.php';
require_once 'config.php';
session_start();

$pdo = getPDO();

// Si tens permisos específics, els podries controlar aquí
// function user_can_edit_empresa(PDO $pdo): bool {
//     return tePermisMestres($pdo, 1);
// }

// Carregar (o crear) registre únic
$stmt = $pdo->query("SELECT * FROM empresa_params ORDER BY id ASC LIMIT 1");
$empresa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$empresa) {
    // Inserim un registre buit per tenir sempre 1 fila
    $pdo->exec("
        INSERT INTO empresa_params (nom)
        VALUES ('')
    ");
    $stmt = $pdo->query("SELECT * FROM empresa_params ORDER BY id ASC LIMIT 1");
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom       = trim($_POST['nom'] ?? '');
    $nif       = trim($_POST['nif'] ?? '');
    $adreca    = trim($_POST['adreca'] ?? '');
    $cp        = trim($_POST['cp'] ?? '');
    $localitat = trim($_POST['localitat'] ?? '');
    $telefon   = trim($_POST['telefon'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $logoPath  = trim($_POST['logo_path'] ?? '');

    if ($nom === '') {
        $errors[] = 'El nom de l\'empresa és obligatori.';
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'email no té un format vàlid.';
    }

    if (empty($errors)) {
        $sql = "
            UPDATE empresa_params
            SET nom         = :nom,
                nif         = :nif,
                adreca      = :adreca,
                cp          = :cp,
                localitat   = :localitat,
                telefon     = :telefon,
                email       = :email,
                logo_path   = :logo_path,
                updated_at  = NOW()
            WHERE id = :id
        ";
        $stmtUp = $pdo->prepare($sql);
        $stmtUp->execute([
            ':nom'       => $nom,
            ':nif'       => $nif,
            ':adreca'    => $adreca,
            ':cp'        => $cp,
            ':localitat' => $localitat,
            ':telefon'   => $telefon,
            ':email'     => $email,
            ':logo_path' => $logoPath,
            ':id'        => $empresa['id'],
        ]);

        $success = true;

        // Recarreguem dades actualitzades
        $stmt = $pdo->query("SELECT * FROM empresa_params ORDER BY id ASC LIMIT 1");
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <title>Paràmetres empresa</title>
    <style>
        body {
            background: #020617;
            color: #e5e7eb;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 24px;
        }
        .shell {
            max-width: 720px;
            margin: 0 auto;
            background: radial-gradient(circle at top left, rgba(34,197,94,0.18), rgba(15,23,42,0.98));
            border-radius: 16px;
            border: 1px solid rgba(148,163,184,0.45);
            padding: 18px 20px 20px;
            box-shadow:
                0 20px 45px rgba(15,23,42,0.9),
                0 0 0 1px rgba(34,197,94,0.4);
        }
        h1 {
            font-size: 1.1rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            margin: 0 0 8px 0;
        }
        .subtitle {
            font-size: 0.85rem;
            color: #9ca3af;
            margin-bottom: 16px;
        }
        .field-group {
            margin-bottom: 10px;
        }
        label {
            display: block;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.11em;
            margin-bottom: 3px;
            color: #9ca3af;
        }
        input[type="text"],
        input[type="email"],
        textarea {
            width: 100%;
            padding: 7px 9px;
            border-radius: 10px;
            border: 1px solid rgba(55,65,81,0.9);
            background: rgba(15,23,42,0.96);
            color: #e5e7eb;
            font-size: 0.85rem;
        }
        textarea {
            min-height: 60px;
            resize: vertical;
        }
        .actions {
            margin-top: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            cursor: pointer;
            text-decoration: none;
            transition:
                transform 0.12s ease,
                box-shadow 0.12s ease,
                background-color 0.12s ease,
                border-color 0.12s ease;
        }
        .btn-save {
            background: radial-gradient(circle at top left, #bbf7d0, #22c55e);
            border-color: #bbf7d0;
            color: #022c22;
            box-shadow: 0 12px 26px rgba(34,197,94,0.6);
        }
        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(22,163,74,0.8);
        }
        .btn-back {
            border-color: rgba(148,163,184,0.8);
            color: #e5e7eb;
            background: transparent;
        }
        .btn-back:hover {
            background: rgba(15,23,42,0.8);
        }
        .errors {
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: 10px;
            background: rgba(239,68,68,0.10);
            border: 1px solid rgba(239,68,68,0.6);
            color: #fecaca;
            font-size: 0.8rem;
        }
        .success {
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: 10px;
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.7);
            color: #bbf7d0;
            font-size: 0.8rem;
        }
        .logo-preview {
            margin-top: 6px;
            font-size: 0.8rem;
            color: #9ca3af;
        }
        .logo-preview img {
            max-height: 60px;
            margin-top: 4px;
        }
    </style>
</head>
<body>
<div class="shell">
    <h1>Paràmetres empresa</h1>
    <div class="subtitle">
        Dades generals de l’empresa i ruta del logotip per utilitzar a albarans, factures i menú principal.
    </div>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <?php foreach ($errors as $e): ?>
                <div>• <?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($success): ?>
        <div class="success">
            Dades desades correctament.
        </div>
    <?php endif; ?>

    <form method="post">
        <div class="field-group">
            <label for="nom">Nom empresa</label>
            <input type="text" id="nom" name="nom"
                   value="<?= htmlspecialchars($empresa['nom'] ?? '') ?>">
        </div>

        <div class="field-group">
            <label for="nif">NIF</label>
            <input type="text" id="nif" name="nif"
                   value="<?= htmlspecialchars($empresa['nif'] ?? '') ?>">
        </div>

        <div class="field-group">
            <label for="adreca">Adreça</label>
            <textarea id="adreca" name="adreca"><?= htmlspecialchars($empresa['adreca'] ?? '') ?></textarea>
        </div>

        <div class="field-group">
            <label for="cp">Codi postal</label>
            <input type="text" id="cp" name="cp"
                   value="<?= htmlspecialchars($empresa['cp'] ?? '') ?>">
        </div>

        <div class="field-group">
            <label for="localitat">Localitat</label>
            <input type="text" id="localitat" name="localitat"
                   value="<?= htmlspecialchars($empresa['localitat'] ?? '') ?>">
        </div>

        <div class="field-group">
            <label for="telefon">Telèfon</label>
            <input type="text" id="telefon" name="telefon"
                   value="<?= htmlspecialchars($empresa['telefon'] ?? '') ?>">
        </div>

        <div class="field-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($empresa['email'] ?? '') ?>">
        </div>

        <div class="field-group">
            <label for="logo_path">Ruta logo (relativa o absoluta)</label>
            <input type="text" id="logo_path" name="logo_path"
                   value="<?= htmlspecialchars($empresa['logo_path'] ?? '') ?>">
            <div class="logo-preview">
                Exemple: de>img/logo.png</code><br>
                <?php if (!empty($empresa['logo_path'])): ?>
                    Previsualització (si la ruta és correcta):<br>
 <div style="text-align:center; margin-top:4px;">
    <img src="<?= htmlspecialchars($empresa['logo_path']) ?>"
         alt="Logo empresa"
         style="max-height:120px" 
         width="220">
</div>
              <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <div>
                
            </div>
            <div style="margin-left:auto;">
                <button type="submit" class="btn btn-save">Desar</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>

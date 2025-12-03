<?php
session_start();

require_once 'db.php';
$pdo = getPDO();

// Carregar permís de mestres de l'usuari loguejat
$permisMestres = 0;
if (!empty($_SESSION['usuari_id'])) {
    $stmtUser = $pdo->prepare("SELECT permis_mestres FROM usuaris WHERE id = :id AND actiu = TRUE");
    $stmtUser->execute(['id' => $_SESSION['usuari_id']]);
    $rowUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($rowUser) {
        $permisMestres = (int)$rowUser['permis_mestres'];
    }
}

$tePermisLectura    = $permisMestres >= 1;
$tePermisEscriptura = $permisMestres >= 2;

// Si no té ni lectura, fora
if (!$tePermisLectura) {
    http_response_code(403);
    echo "<h2>Accés no permès al formulari de clients.</h2>";
    exit;
}

// Bloquejar POST si no hi ha permís d'escriptura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tePermisEscriptura) {
    http_response_code(403);
    echo "<h2>Permís insuficient: només lectura, no es poden desar canvis.</h2>";
    exit;
}

$id      = $_GET['id'] ?? null;
$nom     = '';
$nif     = '';
$adreca  = '';
$telefon = '';
$email   = '';

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $cli = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cli) {
        $nom     = $cli['nom'];
        $nif     = $cli['nif'];
        $adreca  = $cli['adreca'];
        $telefon = $cli['telefon'];
        $email   = $cli['email'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tePermisEscriptura) {
    $id      = $_POST['id'] ?? null;
    $nom     = $_POST['nom'] ?? '';
    $nif     = $_POST['nif'] ?? '';
    $adreca  = $_POST['adreca'] ?? '';
    $telefon = $_POST['telefon'] ?? '';
    $email   = $_POST['email'] ?? '';

    if ($id) {
        $sql = 'UPDATE clients
                SET nom = :nom, nif = :nif, adreca = :adreca, telefon = :telefon, email = :email
                WHERE id = :id';
        $params = compact('id','nom','nif','adreca','telefon','email');
    } else {
        $sql = 'INSERT INTO clients (nom, nif, adreca, telefon, email)
                VALUES (:nom, :nif, :adreca, :telefon, :email)';
        $params = compact('nom','nif','adreca','telefon','email');
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Location: clients_list.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Editar client' : 'Nou client' ?></title>
    <style>
        :root {
            --bg: #f5f7fb;
            --card-bg: #ffffff;
            --text-main: #334155;
            --text-soft: #64748b;
            --accent: #4f46e5;
            --accent-soft: #e0e7ff;
            --btn-save-bg: #a5b4fc;
            --btn-save-border: #6366f1;
            --btn-back-bg: #e5e7eb;
            --btn-back-border: #cbd5f5;
            --input-border: #cbd5e1;
            --input-focus: #818cf8;
            --btn-disabled-bg: #e5e7eb;
            --btn-disabled-border: #d1d5db;
            --btn-disabled-text: #9ca3af;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at top, #e0f2fe 0, var(--bg) 45%, #fefce8 100%);
            color: var(--text-main);
        }

        .wrapper {
            width: 100%;
            max-width: 720px;
            padding: 24px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 24px 28px;
            box-shadow:
                0 18px 45px rgba(15, 23, 42, 0.15),
                0 0 0 1px rgba(148, 163, 184, 0.25);
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .header-title h1 {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .header-title p {
            font-size: 0.9rem;
            color: var(--text-soft);
        }

        .header-actions {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid transparent;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            cursor: pointer;
            transition:
                transform 0.12s ease,
                box-shadow 0.12s ease,
                background-color 0.12s ease,
                border-color 0.12s ease;
            white-space: nowrap;
        }

        .btn span.icon {
            margin-right: 6px;
        }

        .btn-back {
            background-color: var(--btn-back-bg);
            border-color: var(--btn-back-border);
            color: var(--text-main);
        }

        .btn-save {
            background-color: var(--btn-save-bg);
            border-color: var(--btn-save-border);
            color: #111827;
            box-shadow: 0 8px 18px rgba(129, 140, 248, 0.5);
        }

        .btn-save-disabled {
            background-color: var(--btn-disabled-bg);
            border-color: var(--btn-disabled-border);
            color: var(--btn-disabled-text);
            cursor: default;
            box-shadow: none;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.18);
        }

        .btn-save-disabled:hover {
            transform: none;
            box-shadow: none;
        }

        form {
            margin-top: 10px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 20px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-soft);
        }

        .field label span.required {
            color: #dc2626;
            margin-left: 4px;
        }

        .field input {
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--input-border);
            font-size: 0.9rem;
            color: var(--text-main);
            background-color: #f9fafb;
            transition:
                border-color 0.12s ease,
                box-shadow 0.12s ease,
                background-color 0.12s ease;
        }

        .field input:focus {
            outline: none;
            border-color: var(--input-focus);
            box-shadow: 0 0 0 1px var(--input-focus), 0 0 0 4px rgba(129, 140, 248, 0.15);
            background-color: #ffffff;
        }

        .field-full {
            grid-column: 1 / -1;
        }

        .form-footer {
            margin-top: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .hint {
            font-size: 0.8rem;
            color: var(--text-soft);
        }

        @media (max-width: 640px) {
            .wrapper {
                padding: 16px;
            }

            .card {
                padding: 18px 16px;
            }

            .header {
                flex-direction: column;
                align-items: stretch;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .form-footer {
                flex-direction: column-reverse;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="header-title">
                <h1><?= $id ? 'Editar client' : 'Nou client' ?></h1>
                <p>
                    <?= $id
                        ? 'Modifica les dades del client seleccionat.'
                        : 'Introdueix les dades per crear un nou client.' ?>
                    <?php if (!$tePermisEscriptura): ?>
                        (Permís només de lectura: no es poden desar canvis)
                    <?php endif; ?>
                </p>
            </div>
            <div class="header-actions">
                <a href="clients_list.php" class="btn btn-back">
                    <span class="icon">←</span> Llista
                </a>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id ?? '') ?>">

            <div class="grid">
                <div class="field field-full">
                    <label>Nom <span class="required">*</span></label>
                    <input type="text" name="nom" value="<?= htmlspecialchars($nom) ?>"
                        <?= $tePermisEscriptura ? 'required' : 'readonly' ?>>
                </div>

                <div class="field">
                    <label>NIF</label>
                    <input type="text" name="nif" value="<?= htmlspecialchars($nif) ?>"
                        <?= $tePermisEscriptura ? '' : 'readonly' ?>>
                </div>

                <div class="field">
                    <label>Telèfon</label>
                    <input type="text" name="telefon" value="<?= htmlspecialchars($telefon) ?>"
                        <?= $tePermisEscriptura ? '' : 'readonly' ?>>
                </div>

                <div class="field field-full">
                    <label>Adreça</label>
                    <input type="text" name="adreca" value="<?= htmlspecialchars($adreca) ?>"
                        <?= $tePermisEscriptura ? '' : 'readonly' ?>>
                </div>

                <div class="field field-full">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($email) ?>"
                        <?= $tePermisEscriptura ? '' : 'readonly' ?>>
                </div>
            </div>

            <div class="form-footer">
                <div class="hint">
                    Els camps marcats amb <span class="required">*</span> són obligatoris.
                </div>
                <?php if ($tePermisEscriptura): ?>
                    <button type="submit" class="btn btn-save">
                        <span class="icon">💾</span> Desar
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-save btn-save-disabled" title="Només lectura">
                        <span class="icon">🔒</span> Només lectura
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
</body>
</html>

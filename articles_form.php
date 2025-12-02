<?php
require_once 'db.php';
$pdo = getPDO();

$id   = $_GET['id'] ?? null;
$codi = '';
$descripcio = '';
$preu = '';
$iva  = 21;

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM articles WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $art = $stmt->fetch();
    if ($art) {
        $codi       = $art['codi'];
        $descripcio = $art['descripcio'];
        $preu       = $art['preu'];
        $iva        = $art['iva'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = $_POST['id'] ?? null;
    $codi        = $_POST['codi'] ?? '';
    $descripcio  = $_POST['descripcio'] ?? '';
    $preu        = $_POST['preu'] ?? 0;
    $iva         = $_POST['iva'] ?? 21;

    if ($id) {
        $sql = 'UPDATE articles
                SET codi = :codi, descripcio = :descripcio, preu = :preu, iva = :iva
                WHERE id = :id';
        $params = compact('id','codi','descripcio','preu','iva');
    } else {
        $sql = 'INSERT INTO articles (codi, descripcio, preu, iva)
                VALUES (:codi, :descripcio, :preu, :iva)';
        $params = compact('codi','descripcio','preu','iva');
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Location: articles_list.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Editar article' : 'Nou article' ?></title>

<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Editar article' : 'Nou article' ?></title>
    <style>
        :root {
            --bg: #f5f7fb;
            --card-bg: #ffffff;
            --text-main: #334155;
            --text-soft: #64748b;
            --accent: #0ea5e9;
            --accent-soft: #e0f2fe;
            --btn-save-bg: #f9a8d4;      /* rosa pastel */
            --btn-save-border: #ec4899;
            --btn-back-bg: #e5e7eb;
            --btn-back-border: #cbd5f5;
            --input-border: #cbd5e1;
            --input-focus: #0ea5e9;
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
            box-shadow: 0 8px 18px rgba(236, 72, 153, 0.45);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.18);
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
            box-shadow: 0 0 0 1px var(--input-focus), 0 0 0 4px rgba(14, 165, 233, 0.18);
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
                <h1><?= $id ? 'Editar article' : 'Nou article' ?></h1>
                <p>
                    <?= $id ? 'Modifica les dades de l’article seleccionat.' : 'Introdueix les dades per crear un nou article.' ?>
                </p>
            </div>
            <div class="header-actions">
                <a href="articles_list.php" class="btn btn-back">
                    <span class="icon">←</span> Llista
                </a>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id ?? '') ?>">

            <div class="grid">
                <div class="field">
                    <label>Codi <span class="required">*</span></label>
                    <input type="text" name="codi" value="<?= htmlspecialchars($codi) ?>" required>
                </div>

                <div class="field">
                    <label>Preu <span class="required">*</span></label>
                    <input type="number" step="0.01" name="preu" value="<?= htmlspecialchars($preu) ?>" required>
                </div>

                <div class="field field-full">
                    <label>Descripció <span class="required">*</span></label>
                    <input type="text" name="descripcio" value="<?= htmlspecialchars($descripcio) ?>" required>
                </div>

                <div class="field">
                    <label>IVA (%) <span class="required">*</span></label>
                    <input type="number" step="0.01" name="iva" value="<?= htmlspecialchars($iva) ?>" required>
                </div>
            </div>

            <div class="form-footer">
                <div class="hint">
                    Els camps marcats amb <span class="required">*</span> són obligatoris.
                </div>
                <button type="submit" class="btn btn-save">
                    <span class="icon">💾</span> Desar
                </button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
```

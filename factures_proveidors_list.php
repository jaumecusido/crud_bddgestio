<?php
require_once 'db.php';
$pdo = getPDO();

/*
 * CREACIÓ DE TAULES SI NO EXISTEIXEN
 */

$sqlProveidors = "
CREATE TABLE IF NOT EXISTS proveidors (
    id          SERIAL PRIMARY KEY,
    nom         VARCHAR(100) NOT NULL,
    nif         VARCHAR(20),
    adreca      VARCHAR(255),
    telefon     VARCHAR(50),
    email       VARCHAR(100)
);
";

$sqlFacturesProv = "
CREATE TABLE IF NOT EXISTS factures_proveidors (
    id           SERIAL PRIMARY KEY,
    num_factura  VARCHAR(50)  NOT NULL,
    proveidor_id INTEGER      NOT NULL,
    data_factura DATE         NOT NULL,
    import_total NUMERIC(10,2) NOT NULL DEFAULT 0,
    observacions VARCHAR(255),

    CONSTRAINT fk_facprov_proveidor
        FOREIGN KEY (proveidor_id)
        REFERENCES proveidors(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);
";

$pdo->exec($sqlProveidors);
$pdo->exec($sqlFacturesProv);

/*
 * PROCESSAR ACCIONS (EDITAR / ESBORRAR)
 */

$errors = [];
$okMsg  = '';

$accio   = $_POST['accio']   ?? '';
$edit_id = $_POST['edit_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accio === 'delete' && $edit_id && ctype_digit((string)$edit_id)) {
    // Esborrar factura
    $stmtDel = $pdo->prepare("DELETE FROM factures_proveidors WHERE id = :id");
    $stmtDel->execute(['id' => (int)$edit_id]);
    $okMsg = 'Factura de proveïdor esborrada correctament.';
}

/*
 * PROCESSAR FORMULARI (ALTA / EDICIÓ)
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accio === 'save') {
    $id          = $_POST['id'] ?? '';  // buit = nova
    $num_factura = trim($_POST['num_factura'] ?? '');
    $proveidor_id = $_POST['proveidor_id'] ?? '';
    $data_factura = $_POST['data_factura'] ?? '';
    $import_total = $_POST['import_total'] ?? '';
    $observacions = trim($_POST['observacions'] ?? '');

    if ($num_factura === '') {
        $errors[] = 'Cal indicar el número de factura.';
    }
    if ($proveidor_id === '' || !ctype_digit((string)$proveidor_id)) {
        $errors[] = 'Cal seleccionar un proveïdor.';
    }
    if ($data_factura === '') {
        $errors[] = 'Cal indicar la data de la factura.';
    }
    if ($import_total === '' || !is_numeric(str_replace(',', '.', $import_total))) {
        $errors[] = 'Cal indicar un import vàlid.';
    }

    if (empty($errors)) {
        $importFloat = (float)str_replace(',', '.', $import_total);

        if ($id !== '' && ctype_digit((string)$id)) {
            // UPDATE
            $sqlUpd = "
                UPDATE factures_proveidors
                SET num_factura  = :num_factura,
                    proveidor_id = :proveidor_id,
                    data_factura = :data_factura,
                    import_total = :import_total,
                    observacions = :observacions
                WHERE id = :id
            ";
            $stmt = $pdo->prepare($sqlUpd);
            $stmt->execute([
                'num_factura'  => $num_factura,
                'proveidor_id' => (int)$proveidor_id,
                'data_factura' => $data_factura,
                'import_total' => $importFloat,
                'observacions' => $observacions !== '' ? $observacions : null,
                'id'           => (int)$id,
            ]);
            $okMsg = 'Factura de proveïdor actualitzada correctament.';
        } else {
            // INSERT
            $sqlIns = "
                INSERT INTO factures_proveidors
                    (num_factura, proveidor_id, data_factura, import_total, observacions)
                VALUES
                    (:num_factura, :proveidor_id, :data_factura, :import_total, :observacions)
            ";
            $stmt = $pdo->prepare($sqlIns);
            $stmt->execute([
                'num_factura'  => $num_factura,
                'proveidor_id' => (int)$proveidor_id,
                'data_factura' => $data_factura,
                'import_total' => $importFloat,
                'observacions' => $observacions !== '' ? $observacions : null,
            ]);
            $okMsg = 'Factura de proveïdor creada correctament.';
        }

        // Després de desar, netegem camps (no estem forçant reload aquí per simplicitat)
        $id = '';
        $num_factura = $data_factura = $import_total = $observacions = '';
        $proveidor_id = '';
    } else {
        // Si hi ha errors en edició, mantenim l'id per seguir editant
        $id = ($id !== '' && ctype_digit((string)$id)) ? $id : '';
    }
}

// Si s’ha clicat "Editar" des del llistat (GET id)
$editIdGet = $_GET['id'] ?? '';
if ($editIdGet !== '' && ctype_digit((string)$editIdGet) && $accio !== 'save') {
    $stmtEdit = $pdo->prepare("
        SELECT id, num_factura, proveidor_id, data_factura, import_total, observacions
        FROM factures_proveidors
        WHERE id = :id
    ");
    $stmtEdit->execute(['id' => (int)$editIdGet]);
    if ($rowEdit = $stmtEdit->fetch(PDO::FETCH_ASSOC)) {
        $id           = $rowEdit['id'];
        $num_factura  = $rowEdit['num_factura'];
        $proveidor_id = $rowEdit['proveidor_id'];
        $data_factura = $rowEdit['data_factura'];
        $import_total = number_format((float)$rowEdit['import_total'], 2, ',', '.');
        $observacions = $rowEdit['observacions'] ?? '';
    }
}

/*
 * CARREGAR PROVEÏDORS I LLISTAT DE FACTURES
 */

$provStmt = $pdo->query("SELECT id, nom FROM proveidors ORDER BY nom");
$proveidors = $provStmt->fetchAll(PDO::FETCH_ASSOC);

$factStmt = $pdo->query("
    SELECT f.id,
           f.num_factura,
           f.data_factura,
           f.import_total,
           f.observacions,
           p.nom AS proveidor_nom
    FROM factures_proveidors f
    LEFT JOIN proveidors p ON p.id = f.proveidor_id
    ORDER BY f.data_factura DESC, f.num_factura DESC
");
$factures = $factStmt->fetchAll(PDO::FETCH_ASSOC);

// Valors per repintar el formulari si no s’han omplert abans
$id           = $id           ?? '';
$num_factura  = $num_factura  ?? '';
$proveidor_id = $proveidor_id ?? '';
$data_factura = $data_factura ?? date('Y-m-d');
$import_total = $import_total ?? '';
$observacions = $observacions ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Factura proveïdor</title>
    <style>
        :root {
            --bg: #020617;
            --card-bg: rgba(15,23,42,0.96);
            --border-soft: rgba(148,163,184,0.5);
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
            --accent: #ef4444;
            --row-hover: rgba(51,65,85,0.9);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgba(239,68,68,0.20) 0, rgba(15,23,42,1) 45%, #000 100%);
            color: var(--text-main);
            padding: 14px 16px;
        }

        .page {
            max-width: 980px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .header {
            border-bottom: 1px solid rgba(31,41,55,0.9);
            padding-bottom: 8px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }

        .title-block h1 {
            font-size: 1rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .title-block p {
            font-size: 0.8rem;
            color: var(--text-soft);
            margin-top: 2px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background-color: rgba(248,113,113,0.16);
            border: 1px solid rgba(248,113,113,0.7);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #fecaca;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.8fr);
            gap: 12px;
            align-items: flex-start;
        }

        .card {
            background-color: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-soft);
            padding: 14px 14px 12px;
            box-shadow:
                0 18px 40px rgba(15,23,42,0.8),
                0 0 0 1px rgba(127,29,29,0.5);
        }

        .card-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            margin-bottom: 6px;
            color: #fecaca;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 16px;
            margin-bottom: 10px;
        }

        .field {
            flex: 1 1 140px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 0.8rem;
        }

        .field label {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-soft);
        }

        .field input,
        .field select,
        .field textarea {
            padding: 6px 8px;
            border-radius: 10px;
            border: 1px solid rgba(148,163,184,0.9);
            background-color: rgba(15,23,42,0.9);
            color: var(--text-main);
            font-size: 0.85rem;
        }

        .field textarea {
            min-height: 70px;
            resize: vertical;
        }

        .form-actions {
            margin-top: 8px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .btn {
            padding: 7px 14px;
            border-radius: 999px;
            border: 1px solid rgba(248,113,113,0.9);
            background-color: rgba(248,113,113,0.18);
            color: #fecaca;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            cursor: pointer;
        }

        .btn-secondary {
            border-color: rgba(148,163,184,0.9);
            background-color: transparent;
            color: #e5e7eb;
        }

        .btn-small {
            padding: 4px 8px;
            font-size: 0.7rem;
        }

        .messages {
            margin-bottom: 10px;
            font-size: 0.8rem;
        }

        .msg-ok {
            padding: 6px 10px;
            border-radius: 10px;
            background-color: rgba(34,197,94,0.2);
            border: 1px solid rgba(34,197,94,0.8);
            color: #bbf7d0;
        }

        .msg-error {
            padding: 6px 10px;
            border-radius: 10px;
            background-color: rgba(239,68,68,0.15);
            border: 1px solid rgba(248,113,113,0.8);
            color: #fecaca;
            margin-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        th, td {
            padding: 6px 8px;
            text-align: left;
        }

        th {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #fed7d7;
            border-bottom: 1px solid rgba(55,65,81,0.9);
            background: linear-gradient(to right, rgba(127,29,29,0.8), rgba(15,23,42,0.95));
        }

        tbody tr:nth-child(even) { background-color: rgba(15,23,42,0.95); }
        tbody tr:nth-child(odd)  { background-color: rgba(17,24,39,0.98); }

        tbody tr:hover { background-color: var(--row-hover); }

        td {
            border-bottom: 1px solid rgba(31,41,55,0.9);
            color: #e5e7eb;
        }

        .right { text-align: right; }
        .nowrap { white-space: nowrap; }

        .actions-col {
            white-space: nowrap;
        }

        @media (max-width: 880px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="title-block">
            <h1>Factures proveïdor</h1>
            <p>Alta, edició i esborrat de factures de compra a proveïdors.</p>
        </div>
        <div class="pill">📑 Proveïdors</div>
    </div>

    <div class="messages">
        <?php if (!empty($okMsg)): ?>
            <div class="msg-ok"><?= htmlspecialchars($okMsg) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
            <div class="msg-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>

    <div class="grid">
        <!-- FORMULARI ALTA/EDICIÓ -->
        <div class="card">
            <div class="card-title">
                <?= $id ? 'Editar factura #' . htmlspecialchars($id) : 'Nova factura proveïdor' ?>
            </div>
            <form method="post">
                <input type="hidden" name="accio" value="save">
                <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">

                <div class="form-row">
                    <div class="field">
                        <label for="num_factura">Núm. factura</label>
                        <input type="text" id="num_factura" name="num_factura"
                               value="<?= htmlspecialchars($num_factura) ?>" required>
                    </div>

                    <div class="field">
                        <label for="proveidor_id">Proveïdor</label>
                        <select id="proveidor_id" name="proveidor_id" required>
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($proveidors as $p): ?>
                                <option value="<?= $p['id'] ?>"
                                    <?= ($proveidor_id !== '' && (int)$proveidor_id === (int)$p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="data_factura">Data factura</label>
                        <input type="date" id="data_factura" name="data_factura"
                               value="<?= htmlspecialchars($data_factura) ?>" required>
                    </div>

                    <div class="field">
                        <label for="import_total">Import total</label>
                        <input type="text" id="import_total" name="import_total"
                               placeholder="0,00"
                               value="<?= htmlspecialchars($import_total) ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field" style="flex:1 1 100%;">
                        <label for="observacions">Observacions</label>
                        <textarea id="observacions" name="observacions"><?= htmlspecialchars($observacions) ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    
                    <button type="submit" class="btn">Desar factura</button>
                </div>
            </form>
        </div>

        <!-- LLISTAT FACTURES EXISTENTS -->
        <div class="card">
            <div class="card-title">Factures existents</div>
            <?php if (empty($factures)): ?>
                <p style="font-size:0.8rem;color:var(--text-soft);">No hi ha cap factura de proveïdor.</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th class="nowrap">Núm.</th>
                        <th class="nowrap">Data</th>
                        <th>Proveïdor</th>
                        <th class="right nowrap">Import</th>
                        <th class="actions-col">Accions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($factures as $f): ?>
                        <tr>
                            <td class="nowrap"><?= htmlspecialchars($f['num_factura']) ?></td>
                            <td class="nowrap">
                                <?= htmlspecialchars(
                                    $f['data_factura']
                                        ? (new DateTime($f['data_factura']))->format('d/m/Y')
                                        : ''
                                ) ?>
                            </td>
                            <td><?= htmlspecialchars($f['proveidor_nom']) ?></td>
                            <td class="right nowrap">
                                <?= htmlspecialchars(number_format((float)$f['import_total'], 2, ',', '.')) ?> €
                            </td>
                            <td class="actions-col">
                                <a href="factures_proveidors_form.php?id=<?= $f['id'] ?>"
                                   class="btn btn-small btn-secondary">Editar</a>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('Vols esborrar aquesta factura?');">
                                    <input type="hidden" name="accio" value="delete">
                                    <input type="hidden" name="edit_id" value="<?= $f['id'] ?>">
                                    <button type="submit" class="btn btn-small">Esborrar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

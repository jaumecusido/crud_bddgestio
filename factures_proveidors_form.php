<?php
session_start();
require_once 'db.php';
require_once 'config.php';

$pdo = getPDO();

/**
 * Permisos: pots adaptar-los als de compres/proveïdors
 */
function user_can_edit_factures_proveidors(PDO $pdo): bool {
    return tePermisGestio($pdo, 2); // adapta si cal
}

// ---------- CARREGA INICIAL ----------
$id = $_GET['id'] ?? $_POST['id'] ?? null;

// Carregar llista de proveïdors
$proveidorsStmt = $pdo->query("SELECT id, nom FROM proveidors ORDER BY nom");
$proveidors = $proveidorsStmt->fetchAll(PDO::FETCH_ASSOC);

// Carregar articles per seleccionar a les línies
$artsStmt = $pdo->query("SELECT id, codi, descripcio FROM articles ORDER BY codi");
$articles = $artsStmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_GET['msg'] ?? null;

$tePermis = user_can_edit_factures_proveidors($pdo);

// ---------- PROCESSAR POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tePermis) {
    $id               = $_POST['id'] ?? null;
    $proveidor_id     = $_POST['proveidor_id'] ?? null;
    $data_factura     = $_POST['data_factura'] ?? date('Y-m-d');
    $num_factura_ext  = trim($_POST['num_factura_ext'] ?? '');
    $observacions     = $_POST['observacions'] ?? '';

    $accio_afegir_linia = isset($_POST['afegir_linia']);
    $accio_desar        = isset($_POST['desar']);

    // Si ve d’esborrar línia
    $delete_line_id = $_POST['delete_line_id'] ?? null;

    try {
        $pdo->beginTransaction();

        // 1. Desa/actualitza capçalera
        if ($id) {
            $sql = "
                UPDATE factures_proveidors
                   SET proveidor_id = :proveidor_id,
                       data_factura = :data_factura,
                       num_factura_ext = :num_factura_ext,
                       observacions = :observacions
                 WHERE id = :id
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'proveidor_id'    => $proveidor_id,
                'data_factura'    => $data_factura,
                'num_factura_ext' => $num_factura_ext,
                'observacions'    => $observacions,
                'id'              => $id,
            ]);
            $facturaId = (int)$id;
        } else {
            $sql = "
                INSERT INTO factures_proveidors
                    (proveidor_id, data_factura, num_factura_ext, observacions)
                VALUES
                    (:proveidor_id, :data_factura, :num_factura_ext, :observacions)
                RETURNING id
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'proveidor_id'    => $proveidor_id,
                'data_factura'    => $data_factura,
                'num_factura_ext' => $num_factura_ext,
                'observacions'    => $observacions,
            ]);
            $facturaId = (int)$stmt->fetchColumn();
            $id = $facturaId;
        }

        // 2. Esborrar línia si s’ha demanat
        if ($delete_line_id && ctype_digit((string)$delete_line_id)) {
            $stmt = $pdo->prepare("
                DELETE FROM factura_proveidor_linies
                 WHERE id = :id AND factura_id = :factura_id
            ");
            $stmt->execute([
                'id'         => $delete_line_id,
                'factura_id' => $facturaId,
            ]);
        }

        // 3. Actualitzar línies existents
        if (!empty($_POST['line_id']) && is_array($_POST['line_id'])) {
            foreach ($_POST['line_id'] as $idx => $lineId) {
                if (!$lineId || !ctype_digit((string)$lineId)) {
                    continue;
                }
                $qty   = $_POST['line_quantitat'][$idx] ?? null;
                $preu  = $_POST['line_preu'][$idx] ?? null;
                $desc  = $_POST['line_desc'][$idx] ?? '';
                $codi  = $_POST['line_codi'][$idx] ?? '';

                if ($qty === null || $preu === null) {
                    continue;
                }

                $qty  = (float)str_replace(',', '.', $qty);
                $preu = (float)str_replace(',', '.', $preu);

                if ($qty == 0 && $preu == 0) {
                    continue;
                }

                $sql = "
                    UPDATE factura_proveidor_linies
                       SET quantitat    = :quantitat,
                           preu_unitari = :preu_unitari,
                           descripcio   = :descripcio,
                           codi_article = :codi_article
                     WHERE id = :id
                       AND factura_id = :factura_id
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'quantitat'    => $qty,
                    'preu_unitari' => $preu,
                    'descripcio'   => $desc,
                    'codi_article' => $codi,
                    'id'           => $lineId,
                    'factura_id'   => $facturaId,
                ]);
            }
        }

        // 4. Afegir línia nova
        $new_article_id = $_POST['new_article_id'] ?? null;
        $new_qty        = $_POST['new_quantitat'] ?? null;

        if ($new_article_id && $new_qty !== null) {
            $new_qty = (float)str_replace(',', '.', $new_qty);
            if ($new_qty > 0) {
                $stmt = $pdo->prepare("
                    SELECT id, codi, descripcio, preu_compra AS preu
                      FROM articles
                     WHERE id = :id
                ");
                $stmt->execute(['id' => $new_article_id]);
                $art = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($art) {
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(MAX(num_linia), 0) + 1
                          FROM factura_proveidor_linies
                         WHERE factura_id = :factura_id
                    ");
                    $stmt->execute(['factura_id' => $facturaId]);
                    $num_linia = (int)$stmt->fetchColumn();

                    $sql = "
                        INSERT INTO factura_proveidor_linies
                            (factura_id, num_linia, article_id, codi_article,
                             descripcio, preu_unitari, quantitat)
                        VALUES
                            (:factura_id, :num_linia, :article_id, :codi_article,
                             :descripcio, :preu_unitari, :quantitat)
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        'factura_id'   => $facturaId,
                        'num_linia'    => $num_linia,
                        'article_id'   => $art['id'],
                        'codi_article' => $art['codi'],
                        'descripcio'   => $art['descripcio'],
                        'preu_unitari' => $art['preu'],
                        'quantitat'    => $new_qty,
                    ]);
                }
            }
        }

        // 5. Recalcular import_total de la factura
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantitat * preu_unitari), 0)
              FROM factura_proveidor_linies
             WHERE factura_id = :factura_id
        ");
        $stmt->execute(['factura_id' => $facturaId]);
        $importTotal = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            UPDATE factures_proveidors
               SET import_total = :import_total
             WHERE id = :id
        ");
        $stmt->execute([
            'import_total' => $importTotal,
            'id'           => $facturaId,
        ]);

        $pdo->commit();

        if ($accio_desar) {
            header('Location: factures_proveidors_list.php');
            exit;
        }

        header('Location: factures_proveidors_form.php?id=' . $facturaId);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error desant factura de proveïdor: ' . $e->getMessage());
    }
}

// ---------- CARREGAR DADES PER AL FORMULARI (GET o després de POST) ----------
$proveidor_id    = null;
$data_factura    = date('Y-m-d');
$num_factura_ext = '';
$observacions    = '';
$linies          = [];
$import_total    = 0.0;

if ($id) {
    $stmt = $pdo->prepare("
        SELECT fp.*, p.nom AS proveidor_nom
          FROM factures_proveidors fp
          JOIN proveidors p ON p.id = fp.proveidor_id
         WHERE fp.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $proveidor_id    = $row['proveidor_id'];
        $data_factura    = $row['data_factura'];
        $num_factura_ext = $row['num_factura_ext'] ?? '';
        $observacions    = $row['observacions'] ?? '';
        $import_total    = (float)($row['import_total'] ?? 0);
    }

    $stmt = $pdo->prepare("
        SELECT id, num_linia, codi_article, descripcio, preu_unitari, quantitat
          FROM factura_proveidor_linies
         WHERE factura_id = :factura_id
         ORDER BY num_linia
    ");
    $stmt->execute(['factura_id' => $id]);
    $linies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$attrDisabled = $tePermis ? '' : 'disabled';
$attrReadOnly = $tePermis ? '' : 'readonly';

?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Factura proveïdor '.$id : 'Nova factura proveïdor' ?></title>
    <style>
        :root {
            --bg: #f5f7fb;
            --card-bg: #ffffff;
            --text-main: #334155;
            --text-soft: #64748b;
            --btn-save-bg: #bae6fd;
            --btn-save-border: #0ea5e9;
            --btn-back-bg: #e5e7eb;
            --btn-back-border: #cbd5f5;
            --input-border: #d4d4d8;
            --input-focus: #0ea5e9;
            --row-hover: #eff6ff;
            --row-even: #f9fafb;
            --row-odd: #fefce8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background: radial-gradient(circle at top, #e0f2fe 0, var(--bg) 45%, #fefce8 100%);
            color: var(--text-main);
        }
        .wrapper { width: 100%; max-width: 1040px; padding: 24px; }
        .card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 22px 26px;
            box-shadow: 0 18px 45px rgba(15,23,42,0.15), 0 0 0 1px rgba(148,163,184,0.25);
        }
        .header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .header-title h1 {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .header-title p { font-size: 0.9rem; color: var(--text-soft); }
        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-items: center;
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
            transition: transform .12s, box-shadow .12s, background-color .12s, border-color .12s;
            white-space: nowrap;
            height: 34px;
        }
        .btn span.icon { margin-right: 6px; }
        .btn-back {
            background: var(--btn-back-bg);
            border-color: var(--btn-back-border);
            color: var(--text-main);
        }
        .btn-save {
            background: var(--btn-save-bg);
            border-color: var(--btn-save-border);
            color: #0f172a;
            box-shadow: 0 8px 18px rgba(14,165,233,0.45);
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15,23,42,0.18);
        }
        form { margin-top: 10px; }
        .grid-header {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 16px 20px;
            margin-bottom: 16px;
        }
        .field { display: flex; flex-direction: column; gap: 4px; }
        .field label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-soft);
            font-weight: 500;
        }
        .field .required { color: #dc2626; margin-left: 4px; }
        .field select,
        .field input,
        .field textarea {
            padding: 7px 10px;
            border-radius: 8px;
            border: 1px solid var(--input-border);
            font-size: 0.9rem;
            color: var(--text-main);
            background: #fdfdfd;
            transition: border-color .15s, box-shadow .15s, background-color .15s;
            width: 100%;
        }
        .field textarea { min-height: 70px; resize: vertical; }
        .field select:focus,
        .field input:focus,
        .field textarea:focus {
            outline: none;
            border-color: var(--input-focus);
            box-shadow: 0 0 0 1px rgba(14,165,233,.35);
            background-color: #ffffff;
        }
        .field-full { grid-column: 1 / -1; }
        .section-linies-title {
            margin-top: 20px;
            margin-bottom: 6px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-soft);
        }
        .table-wrapper {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(148,163,184,.5);
            background: linear-gradient(to bottom, #f9fafb, #ffffff);
        }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        thead {
            background: linear-gradient(to right, #e0f2fe, #ede9fe);
        }
        th, td {
            padding: 7px 8px;
            text-align: left;
        }
        th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #4b5563;
            border-bottom: 1px solid rgba(148,163,184,.7);
        }
        tbody tr:nth-child(odd) { background: var(--row-odd); }
        tbody tr:nth-child(even) { background: var(--row-even); }
        tbody tr:hover { background: var(--row-hover); }
        td { border-bottom: 1px solid rgba(226,232,240,.9); color: #111827; }
        .col-num { width: 60px; text-align: center; }
        .col-qty, .col-preu, .col-total { text-align: right; white-space: nowrap; }
        .col-qty { width: 90px; }
        .col-preu { width: 100px; }
        .col-total { width: 110px; }
        .col-del { width: 80px; text-align: center; }
        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            background: #e5e7eb;
            color: #4b5563;
        }
        .small { font-size: 0.8rem; color: var(--text-soft); }
        .empty { padding: 14px 10px; font-size: 0.9rem; color: var(--text-soft); }
        .input-num { text-align: right; }
        td input[type="text"],
        td input[type="number"] {
            padding: 5px 7px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            font-size: 0.85rem;
            background: #ffffff;
            width: 100%;
        }
        td input[type="text"]:focus,
        td input[type="number"]:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 1px rgba(59,130,246,.35);
            outline: none;
        }
        .form-footer {
            margin-top: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .footer-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .hint { font-size: 0.8rem; color: var(--text-soft); }
        .total-box {
            font-size: 0.9rem;
            font-weight: 600;
            padding: 6px 10px;
            border-radius: 999px;
            background: #ecfeff;
            border: 1px solid #67e8f9;
        }
        @media (max-width: 780px) {
            .grid-header { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .wrapper { padding: 16px; }
            .card { padding: 18px 16px; }
            .header { flex-direction: column; align-items: stretch; }
            .header-actions { justify-content: flex-start; }
            .form-footer { flex-direction: column-reverse; align-items: flex-start; }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="header-title">
                <h1><?= $id ? "Factura proveïdor #$id" : "Nova factura proveïdor" ?></h1>
                <p>Capçalera i línies en una sola pantalla. Registre de compres a proveïdors.</p>
            </div>
            <div class="header-actions">
                <a href="factures_proveidors_list.php" class="btn btn-back">
                    <span class="icon">←</span>Llista
                </a>
                <?php if ($id): ?>
                    <a href="factura_proveidor_pdf.php?factura_id=<?= htmlspecialchars($id) ?>"
                       target="_blank"
                       class="btn btn-save">
                        <span class="icon">📄</span>PDF
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flash): ?>
            <div style="margin-bottom:10px;padding:8px 12px;border-radius:10px;
                        background:#ecfeff;color:#0f172a;border:1px solid #67e8f9;font-size:0.85rem;">
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id ?? '') ?>">

            <div class="grid-header">
                <div class="field">
                    <label>Proveïdor <span class="required">*</span></label>
                    <select name="proveidor_id" required <?= $attrDisabled ?>>
                        <option value="">Tria un proveïdor</option>
                        <?php foreach ($proveidors as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                <?= ($p['id'] == $proveidor_id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Data factura <span class="required">*</span></label>
                    <input type="date" name="data_factura"
                           value="<?= htmlspecialchars($data_factura) ?>"
                           required <?= $attrDisabled ?>>
                </div>
                <div class="field">
                    <label>Núm. factura proveïdor</label>
                    <input type="text" name="num_factura_ext"
                           value="<?= htmlspecialchars($num_factura_ext) ?>"
                           placeholder="Número que indica el proveïdor"
                           <?= $attrReadOnly ?>>
                </div>
                <div class="field field-full">
                    <label>Observacions</label>
                    <textarea name="observacions" <?= $attrDisabled ?>><?= htmlspecialchars($observacions) ?></textarea>
                </div>
            </div>

            <div class="section-linies-title">Línies de factura</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th class="col-num">Línia</th>
                        <th>Codi</th>
                        <th>Descripció</th>
                        <th class="col-qty">Quantitat</th>
                        <th class="col-preu">Preu</th>
                        <th class="col-total">Import</th>
                        <th class="col-del">Eliminar</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($linies)): ?>
                        <tr>
                            <td colspan="7" class="empty">
                                Encara no hi ha línies. Afegeix-ne una més avall.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($linies as $idx => $l): ?>
                            <?php
                            $import = (float)$l['preu_unitari'] * (float)$l['quantitat'];
                            ?>
                            <tr>
                                <td class="col-num">
                                    <span class="tag"><?= htmlspecialchars($l['num_linia']) ?></span>
                                    <input type="hidden" name="line_id[]"
                                           value="<?= htmlspecialchars($l['id']) ?>">
                                </td>
                                <td>
                                    <input type="text" name="line_codi[]"
                                           value="<?= htmlspecialchars($l['codi_article']) ?>"
                                           <?= $attrReadOnly ?>>
                                </td>
                                <td>
                                    <input type="text" name="line_desc[]"
                                           value="<?= htmlspecialchars($l['descripcio']) ?>"
                                           <?= $attrReadOnly ?>>
                                </td>
                                <td class="col-qty">
                                    <input class="input-num" type="number" step="0.01" min="0"
                                           name="line_quantitat[]"
                                           value="<?= htmlspecialchars($l['quantitat']) ?>"
                                           <?= $attrReadOnly ?>>
                                </td>
                                <td class="col-preu">
                                    <input class="input-num" type="number" step="0.01" min="0"
                                           name="line_preu[]"
                                           value="<?= htmlspecialchars($l['preu_unitari']) ?>"
                                           <?= $attrReadOnly ?>>
                                </td>
                                <td class="col-total">
                                    <?= htmlspecialchars(number_format($import, 2, ',', '.')) ?> €
                                </td>
                                <td class="col-del">
                                    <?php if ($tePermis): ?>
                                        <button type="submit" name="delete_line_id"
                                                value="<?= htmlspecialchars($l['id']) ?>"
                                                class="btn"
                                                style="background:#fee2e2;border-color:#fecaca;color:#b91c1c;height:28px;font-size:0.78rem;"
                                                onclick="return confirm('Vols eliminar aquesta línia?');">
                                            ✕
                                        </button>
                                    <?php else: ?>
                                        <span class="small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($tePermis): ?>
                <div style="margin-top:10px;">
                    <div class="field" style="margin-bottom:6px;">
                        <label>Afegir nova línia</label>
                    </div>
                    <div class="grid-header" style="grid-template-columns: 2fr 1fr; gap:10px 14px;">
                        <div class="field">
                            <select name="new_article_id">
                                <option value="">Article</option>
                                <?php foreach ($articles as $art): ?>
                                    <option value="<?= $art['id'] ?>">
                                        <?= htmlspecialchars($art['codi'] . ' - ' . $art['descripcio']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <input class="input-num" type="number" name="new_quantitat"
                                   step="0.01" min="0" placeholder="Quantitat">
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-footer">
                <div class="hint">
                    Pots registrar totes les compres al proveïdor des d’aquesta pantalla.<br>
                    Assegura’t que les quantitats i preus coincideixen amb la factura del proveïdor.
                </div>
                <div class="footer-buttons">
                    <div class="total-box">
                        Total factura: <?= htmlspecialchars(number_format($import_total, 2, ',', '.')) ?> €
                    </div>
                    <?php if ($tePermis): ?>
                        <button type="submit" name="afegir_linia" class="btn btn-back">
                            <span class="icon">＋</span>Afegir línia
                        </button>
                        <button type="submit" name="desar" class="btn btn-save">
                            <span class="icon">💾</span>Desar
                        </button>
                    <?php else: ?>
                        <span class="small">Mode només lectura (sense permisos d’edició).</span>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>

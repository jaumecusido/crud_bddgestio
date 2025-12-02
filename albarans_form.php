
<?php
require_once 'db.php';
$pdo = getPDO();

$id = $_GET['id'] ?? null;

// 1) Carregar llista de clients
$clientsStmt = $pdo->query("SELECT id, nom FROM clients ORDER BY nom");
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Processar POST (capçalera + línies)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id             = $_POST['id'] ?? null;
    $client_id      = $_POST['client_id'] ?? null;
    $data_albara    = $_POST['data_albara'] ?? date('Y-m-d');
    $observacions   = $_POST['observacions'] ?? '';

    // botó premut
    $accio_afegir_linia = isset($_POST['afegir_linia']);
    $accio_desar        = isset($_POST['desar']);

    // si ve d'un botó eliminar, marquem aquesta línia a esborrar
    if (!empty($_POST['delete_line_id'])) {
        $_POST['delete_line'] = [$_POST['delete_line_id']];
    }

    // agafem adreça actual del client
    $stmt = $pdo->prepare("SELECT adreca FROM clients WHERE id = :id");
    $stmt->execute(['id' => $client_id]);
    $cli = $stmt->fetch(PDO::FETCH_ASSOC);
    $adreca_entrega = $cli ? $cli['adreca'] : '';

    $pdo->beginTransaction();
    try {
        if ($id) {
            // update capçalera
            $sql = "
                UPDATE albarans
                SET client_id = :client_id,
                    data_albara = :data_albara,
                    adreca_entrega = :adreca_entrega,
                    observacions = :observacions
                WHERE id = :id
            ";
            $params = compact('id','client_id','data_albara','adreca_entrega','observacions');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $albaraId = $id;
        } else {
            // insert capçalera nova
            $sql = "
                INSERT INTO albarans (client_id, data_albara, adreca_entrega, observacions)
                VALUES (:client_id, :data_albara, :adreca_entrega, :observacions)
                RETURNING id
            ";
            $params = compact('client_id','data_albara','adreca_entrega','observacions');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $albaraId = $stmt->fetchColumn();
            $id = $albaraId;
        }

        // 2.1) Esborrar línies marcades (des de botó eliminar)
        if (!empty($_POST['delete_line']) && is_array($_POST['delete_line'])) {
            $idsToDelete = array_filter($_POST['delete_line'], 'ctype_digit');
            if ($idsToDelete) {
                $in  = implode(',', array_fill(0, count($idsToDelete), '?'));
                $sql = "DELETE FROM albara_linies WHERE albara_id = ? AND id IN ($in)";
                $stmt = $pdo->prepare($sql);
                $params = array_merge([$albaraId], $idsToDelete);
                $stmt->execute($params);
            }
        }

        // 2.2) Guardar / actualitzar línies existents
        if (!empty($_POST['line_id']) && is_array($_POST['line_id'])) {
            foreach ($_POST['line_id'] as $idx => $lineId) {
                $lineId = $_POST['line_id'][$idx] ?? null;
                if (!$lineId) {
                    continue;
                }

                $qty  = $_POST['line_quantitat'][$idx] ?? null;
                $preu = $_POST['line_preu'][$idx] ?? null;

                if ($qty === null || $qty === '' || $preu === null || $preu === '') {
                    continue;
                }

                $qty  = (float)$qty;
                $preu = (float)$preu;
                if ($qty < 0 || $preu < 0) {
                    continue;
                }

                $desc   = $_POST['line_desc'][$idx] ?? '';
                $codi   = $_POST['line_codi'][$idx] ?? '';

                $sql = "
                    UPDATE albara_linies
                    SET quantitat = :quantitat,
                        preu_unitari = :preu_unitari,
                        descripcio = :descripcio,
                        codi_article = :codi_article
                    WHERE id = :id AND albara_id = :albara_id
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'quantitat'   => $qty,
                    'preu_unitari'=> $preu,
                    'descripcio'  => $desc,
                    'codi_article'=> $codi,
                    'id'          => $lineId,
                    'albara_id'   => $albaraId,
                ]);
            }
        }

        // 2.3) Afegir línia nova (si hi ha article i quantitat)
        $new_article_id = $_POST['new_article_id'] ?? null;
        $new_qty        = $_POST['new_quantitat'] ?? null;

        if ($new_article_id && $new_qty !== null && $new_qty !== '') {
            $new_qty = (float)$new_qty;
            if ($new_qty > 0) {
                $stmt = $pdo->prepare("SELECT id, codi, descripcio, preu FROM articles WHERE id = :id");
                $stmt->execute(['id' => $new_article_id]);
                $art = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($art) {
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(num_linia),0)+1 FROM albara_linies WHERE albara_id = :id");
                    $stmt->execute(['id' => $albaraId]);
                    $num_linia = (int)$stmt->fetchColumn();

                    $sql = "
                        INSERT INTO albara_linies
                            (albara_id, num_linia, article_id, codi_article, descripcio, preu_unitari, quantitat)
                        VALUES
                            (:albara_id, :num_linia, :article_id, :codi_article, :descripcio, :preu_unitari, :quantitat)
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        'albara_id'    => $albaraId,
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

        $pdo->commit();

        if ($accio_desar) {
            header('Location: albarans_list.php');
            exit;
        }

        header('Location: albarans_form.php?id=' . $albaraId);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error desant: '.$e->getMessage());
    }
}

// 3) Si no hi ha POST: carregar dades per mostrar formulari
$client_id      = '';
$data_albara    = date('Y-m-d');
$adreca_entrega = '';
$observacions   = '';
$linies         = [];
$articles       = [];

// Carregar articles per al select de nova línia
$artsStmt = $pdo->query("SELECT id, codi, descripcio FROM articles ORDER BY codi");
$articles = $artsStmt->fetchAll(PDO::FETCH_ASSOC);

if ($id) {
    $stmt = $pdo->prepare("
        SELECT a.*, c.nom AS client_nom
        FROM albarans a
        JOIN clients c ON c.id = a.client_id
        WHERE a.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $client_id      = $row['client_id'];
        $data_albara    = $row['data_albara'];
        $adreca_entrega = $row['adreca_entrega'];
        $observacions   = $row['observacions'];
    }

    $stmt = $pdo->prepare("
        SELECT id, num_linia, codi_article, descripcio, preu_unitari, quantitat, num_factura
        FROM albara_linies
        WHERE albara_id = :id
        ORDER BY num_linia
    ");
    $stmt->execute(['id' => $id]);
    $linies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Albarà '.$id : 'Nou albarà' ?></title>
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background: radial-gradient(circle at top, #e0f2fe 0, var(--bg) 45%, #fefce8 100%);
            color: var(--text-main);
        }

        .wrapper {
            width: 100%;
            max-width: 1040px;
            padding: 24px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 22px 26px;
            box-shadow:
                0 18px 45px rgba(15, 23, 42, 0.15),
                0 0 0 1px rgba(148, 163, 184, 0.25);
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
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
            color: #0f172a;
            box-shadow: 0 8px 18px rgba(14, 165, 233, 0.45);
        }

        .btn-pdf {
            background-color: #bbf7d0;
            border-color: #22c55e;
            color: #14532d;
            box-shadow: 0 8px 18px rgba(34, 197, 94, 0.45);
        }

        .btn-secondary {
            background-color: #e0e7ff;
            border-color: #a5b4fc;
            color: #1e293b;
        }

        .btn-danger {
            background-color: #fee2e2;
            border-color: #fecaca;
            color: #b91c1c;
            font-size: 0.78rem;
            padding: 4px 10px;
            border-radius: 999px;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.18);
        }

        form {
            margin-top: 10px;
        }

        .grid-header {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 16px 20px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .field label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-soft);
            font-weight: 500;
        }

        .field label span.required {
            color: #dc2626;
            margin-left: 4px;
        }

        .field select,
        .field input,
        .field textarea {
            padding: 7px 10px;
            border-radius: 8px;
            border: 1px solid var(--input-border);
            font-size: 0.9rem;
            color: var(--text-main);
            background-color: #fdfdfd;
            transition:
                border-color 0.15s ease,
                box-shadow 0.15s ease,
                background-color 0.15s ease;
            width: 100%;
        }

        .field textarea {
            min-height: 70px;
            resize: vertical;
        }

        .field select:focus,
        .field input:focus,
        .field textarea:focus {
            outline: none;
            border-color: var(--input-focus);
            box-shadow: 0 0 0 1px rgba(14,165,233,0.35);
            background-color: #ffffff;
        }

        .field-full {
            grid-column: 1 / -1;
        }

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
            border: 1px solid rgba(148, 163, 184, 0.5);
            background: linear-gradient(to bottom, #f9fafb, #ffffff);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

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
            border-bottom: 1px solid rgba(148, 163, 184, 0.7);
        }

        tbody tr:nth-child(odd) {
            background-color: var(--row-odd);
        }
        tbody tr:nth-child(even) {
            background-color: var(--row-even);
        }
        tbody tr:hover {
            background-color: var(--row-hover);
        }

        td {
            border-bottom: 1px solid rgba(226, 232, 240, 0.9);
            color: #111827;
        }

        .col-num { width: 60px; }
        .col-qty,
        .col-preu,
        .col-total {
            text-align: right;
            white-space: nowrap;
        }
        .col-qty { width: 90px; }
        .col-preu { width: 100px; }
        .col-total { width: 110px; }
        .col-del { width: 80px; text-align: center; }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            background-color: #e5e7eb;
            color: #4b5563;
        }

        .small {
            font-size: 0.8rem;
            color: var(--text-soft);
        }

        .empty {
            padding: 14px 10px;
            font-size: 0.9rem;
            color: var(--text-soft);
        }

        .input-num {
            text-align: right;
        }

        td input[type="text"],
        td input[type="number"] {
            padding: 5px 7px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            font-size: 0.85rem;
            background-color: #ffffff;
        }
        td input[type="text"]:focus,
        td input[type="number"]:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 1px rgba(59,130,246,0.35);
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
        }

        .hint {
            font-size: 0.8rem;
            color: var(--text-soft);
        }

        @media (max-width: 780px) {
            .grid-header {
                grid-template-columns: 1fr;
            }
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
            .form-footer {
                flex-direction: column-reverse;
                align-items: flex-start;
            }
            .footer-buttons {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="header-title">
                <h1><?= $id ? 'Editar albarà' : 'Nou albarà' ?></h1>
                <p>Capçalera i línies en una sola pantalla.</p>
            </div>
            <div class="header-actions">
                <a href="albarans_list.php" class="btn btn-back">
                    <span class="icon">←</span> Llista
                </a>
                <?php if ($id): ?>
                    <a href="albara_pdf.php?id=<?= htmlspecialchars($id) ?>"
                       class="btn btn-pdf" target="_blank">
                        <span class="icon">📄</span> Albarà PDF
                    </a>
                    <a href="albara_sendmail.php?id=<?= htmlspecialchars($id) ?>"
                       class="btn btn-save">
                        <span class="icon">✉️</span> Enviar mail
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id ?? '') ?>">

            <!-- CAPÇALERA -->
            <div class="grid-header">
                <div class="field">
                    <label>Client <span class="required">*</span></label>
                    <select name="client_id" required>
                        <option value="">— Tria un client —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                <?= ($c['id'] == $client_id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Data <span class="required">*</span></label>
                    <input type="date" name="data_albara"
                           value="<?= htmlspecialchars($data_albara) ?>" required>
                </div>

                <div class="field field-full">
                    <label>Adreça d’entrega (guardada de la fitxa del client)</label>
                    <input type="text" value="<?= htmlspecialchars($adreca_entrega) ?>" disabled>
                </div>

                <div class="field field-full">
                    <label>Observacions</label>
                    <textarea name="observacions"><?= htmlspecialchars($observacions) ?></textarea>
                </div>
            </div>

            <!-- LÍNIES -->
            <div class="section-linies-title">Línies d’albarà</div>
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
                        <th class="col-del">Elimina</th>
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
                            <?php $import = (float)$l['preu_unitari'] * (float)$l['quantitat']; ?>
                            <tr>
                                <td class="col-num">
                                    <span class="tag">#<?= htmlspecialchars($l['num_linia']) ?></span>
                                    <input type="hidden" name="line_id[]"
                                           value="<?= htmlspecialchars($l['id']) ?>">
                                </td>
                                <td>
                                    <input type="text" name="line_codi[]"
                                           value="<?= htmlspecialchars($l['codi_article']) ?>">
                                </td>
                                <td>
                                    <input type="text" name="line_desc[]"
                                           value="<?= htmlspecialchars($l['descripcio']) ?>">
                                </td>
                                <td class="col-qty">
                                    <input class="input-num" type="number" step="0.01" min="0"
                                           name="line_quantitat[]"
                                           value="<?= htmlspecialchars($l['quantitat']) ?>">
                                </td>
                                <td class="col-preu">
                                    <input class="input-num" type="number" step="0.01" min="0"
                                           name="line_preu[]"
                                           value="<?= htmlspecialchars($l['preu_unitari']) ?>">
                                </td>
                                <td class="col-total">
                                    <?= htmlspecialchars(number_format($import, 2, ',', '.')) ?> €
                                </td>
                                <td class="col-del">
                                    <button type="submit"
                                            name="delete_line_id"
                                            value="<?= htmlspecialchars($l['id']) ?>"
                                            class="btn btn-danger"
                                            onclick="return confirm('Vols eliminar aquesta línia?');">
                                        ✖
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Afegir línia nova -->
            <div style="margin-top: 10px;">
                <div class="field" style="margin-bottom:6px;">
                    <label>Afegir nova línia</label>
                </div>
                <div class="grid-header" style="grid-template-columns: 2fr 1fr;">
                    <div class="field">
                        <select name="new_article_id">
                            <option value="">— Article —</option>
                            <?php foreach ($articles as $art): ?>
                                <option value="<?= $art['id'] ?>">
                                    <?= htmlspecialchars($art['codi'].' - '.$art['descripcio']) ?>
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

            <!-- Peu formulari -->
            <div class="form-footer">
                <div class="hint">
                    Pots afegir o eliminar línies i, quan acabis, prem Desar per tornar al llistat.
                </div>
                <div class="footer-buttons">
                    <button type="submit" name="afegir_linia" class="btn btn-secondary">
                        <span class="icon">＋</span> Afegir línia
                    </button>
                    <button type="submit" name="desar" class="btn btn-save">
                        <span class="icon">💾</span> Desar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>

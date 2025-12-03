<?php
session_start();

require_once 'db.php';
$pdo = getPDO();

// Carregar permís de gestió de l'usuari loguejat
$permisGestio = 0;
if (!empty($_SESSION['usuari_id'])) {
    $stmtUser = $pdo->prepare("SELECT permis_gestio FROM usuaris WHERE id = :id AND actiu = TRUE");
    $stmtUser->execute(['id' => $_SESSION['usuari_id']]);
    $rowUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($rowUser) {
        $permisGestio = (int)$rowUser['permis_gestio'];
    }
}

$tePermisLectura    = $permisGestio >= 1;
$tePermisEscriptura = $permisGestio >= 2;

// Si no té ni lectura, fora
if (!$tePermisLectura) {
    http_response_code(403);
    echo "<h2>Accés no permès al mòdul d'albarans.</h2>";
    exit;
}

// Llegir filtres
$clientFilter = $_GET['client_id'] ?? '';
$pendentsOnly = isset($_GET['pendents']) ? (bool)$_GET['pendents'] : false;

// Carregar llista de clients per al select
$clientsStmt = $pdo->query("SELECT id, nom FROM clients ORDER BY nom");
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Construir SQL base (sumem línies i marquem si està facturat + num factura)
$sql = "
    SELECT a.id,
           a.num_albara,
           a.data_albara,
           a.adreca_entrega,
           COALESCE(c.nom, '') AS client_nom,
           COALESCE(SUM(l.preu_unitari * l.quantitat), 0) AS import_total,
           EXISTS (
               SELECT 1
               FROM factura_albarans fa
               WHERE fa.albara_id = a.id
           ) AS esta_facturat,
           (
               SELECT f.num_factura
               FROM factura_albarans fa2
               JOIN factures f ON f.id = fa2.factura_id
               WHERE fa2.albara_id = a.id
               ORDER BY f.data_factura, f.num_factura
               LIMIT 1
           ) AS num_factura
    FROM albarans a
    LEFT JOIN clients c ON c.id = a.client_id
    LEFT JOIN albara_linies l ON l.albara_id = a.id
";

$where = [];
$params = [];

// Filtre per client
if ($clientFilter !== '' && ctype_digit((string)$clientFilter)) {
    $where[] = "a.client_id = :client_id";
    $params['client_id'] = (int)$clientFilter;
}

// Filtre “pendents de facturar”
if ($pendentsOnly) {
    $where[] = "NOT EXISTS (
        SELECT 1
        FROM factura_albarans fa2
        WHERE fa2.albara_id = a.id
    )";
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
    GROUP BY a.id, a.num_albara, a.data_albara, a.adreca_entrega, c.nom
    ORDER BY a.num_albara DESC
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v, PDO::PARAM_INT);
}
$stmt->execute();
$albarans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular total global dels imports
$totalGlobal = 0;
foreach ($albarans as $row) {
    $totalGlobal += (float)$row['import_total'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Albarans</title>
    <style>
        :root {
            --bg: #020617;
            --panel-bg: #020617;
            --card-bg: rgba(15,23,42,0.96);
            --border-soft: rgba(148,163,184,0.4);
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
            --accent: #0ea5e9;
            --accent-soft: rgba(56,189,248,0.18);
            --btn-new-bg: #0ea5e9;
            --btn-new-border: #bae6fd;
            --btn-back-border: rgba(148,163,184,0.8);
            --row-even: rgba(15,23,42,0.96);
            --row-odd: rgba(17,24,39,0.98);
            --row-hover: rgba(59,130,246,0.45);
            --filter-bg: rgba(15,23,42,0.95);
            --filter-border: rgba(148,163,184,0.7);
            --chip-disabled-bg: rgba(31,41,55,0.9);
            --chip-disabled-border: rgba(75,85,99,0.9);
            --chip-disabled-text: #6b7280;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        html, body {
            height: 100%;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgba(14,165,233,0.22) 0, rgba(15,23,42,1) 40%, #000 100%);
            color: var(--text-main);
            display: flex;
        }

        .shell {
            width: 100%;
            min-height: 100vh;
            display: flex;
            justify-content: center;
        }

        .panel {
            width: 100%;
            max-width: 1160px;
            padding: 18px 22px 18px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(31,41,55,0.9);
        }

        .title-block h1 {
            font-size: 1.25rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .title-block p {
            font-size: 0.85rem;
            color: var(--text-soft);
            margin-top: 4px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,0.7);
            font-size: 0.7rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--text-soft);
        }

        .badge-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #0ea5e9;
            box-shadow: 0 0 0 4px rgba(56,189,248,0.35);
        }

        .filters {
            margin-top: 4px;
            padding: 8px 10px;
            border-radius: 14px;
            background-color: var(--filter-bg);
            border: 1px solid var(--filter-border);
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            align-items: center;
        }

        .filters label {
            font-size: 0.8rem;
            color: var(--text-soft);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .filters select,
        .filters input[type="checkbox"] {
            margin-left: 4px;
        }

        .filters select {
            padding: 4px 6px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,0.9);
            font-size: 0.8rem;
            background-color: rgba(15,23,42,0.9);
            color: var(--text-main);
        }

        .filters button {
            margin-left: auto;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid rgba(59,130,246,0.9);
            background-color: rgba(59,130,246,0.2);
            color: #dbeafe;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            cursor: pointer;
        }

        .filters a.clear-link {
            font-size: 0.8rem;
            color: var(--text-soft);
            text-decoration: underline;
        }

        .card {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: radial-gradient(circle at top left, rgba(56,189,248,0.18), rgba(15,23,42,0.98));
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            box-shadow:
                0 22px 50px rgba(15,23,42,0.75),
                0 0 0 1px rgba(30,64,175,0.4);
            padding: 12px 14px 10px;
            overflow: hidden;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 10px;
            margin-bottom: 6px;
        }

        .card-header-left h2 {
            font-size: 0.95rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .card-header-left p {
            font-size: 0.8rem;
            color: var(--text-soft);
            margin-top: 2px;
        }

        .card-header-right {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background-color: var(--accent-soft);
            border: 1px solid rgba(56,189,248,0.6);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #e0f2fe;
        }

        .pill span.icon {
            font-size: 0.95rem;
        }

        .actions-header {
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 13px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            cursor: pointer;
            text-decoration: none;
            transition:
                transform 0.12s ease,
                box-shadow 0.12s ease,
                background-color 0.12s ease,
                border-color 0.12s ease,
                color 0.12s ease;
            white-space: nowrap;
        }

        .btn span.icon {
            margin-right: 6px;
            font-size: 0.9rem;
        }

        .btn-new {
            background: radial-gradient(circle at top left, #bae6fd, #0ea5e9);
            border-color: var(--btn-new-border);
            color: #020617;
            box-shadow: 0 12px 26px rgba(14,165,233,0.6);
        }

        .btn-new-disabled {
            background-color: var(--chip-disabled-bg);
            border-color: var(--chip-disabled-border);
            color: var(--chip-disabled-text);
            box-shadow: none;
            cursor: default;
        }

        .btn-new:hover {
            background: radial-gradient(circle at top left, #bae6fd, #0284c7);
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(8,145,178,0.8);
        }

        .table-shell {
            flex: 1;
            margin-top: 6px;
            border-radius: 14px;
            border: 1px solid rgba(55,65,81,0.9);
            background: linear-gradient(to bottom, rgba(15,23,42,0.96), rgba(15,23,42,0.99));
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .table-container {
            flex: 1;
            overflow: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        thead {
            position: sticky;
            top: 0;
            z-index: 2;
            background: linear-gradient(to right, rgba(56,189,248,0.25), rgba(129,140,248,0.32));
            backdrop-filter: blur(10px);
        }

        th, td {
            padding: 8px 10px;
            text-align: left;
        }

        th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #e5e7eb;
            border-bottom: 1px solid rgba(55,65,81,0.9);
        }

        tbody tr:nth-child(even) {
            background-color: var(--row-even);
        }

        tbody tr:nth-child(odd) {
            background-color: var(--row-odd);
        }

        tbody tr:hover {
            background-color: var(--row-hover);
        }

        td {
            border-bottom: 1px solid rgba(31,41,55,0.9);
            color: var(--text-main);
        }

        .col-id {
            width: 70px;
            font-size: 0.78rem;
            color: var(--text-soft);
        }

        .col-num {
            width: 110px;
        }

        .col-factura {
            width: 110px;
        }

        .col-data {
            width: 120px;
            white-space: nowrap;
        }

        .col-total {
            width: 110px;
            text-align: right;
            white-space: nowrap;
        }

        .col-actions {
            width: 260px;
            text-align: right;
        }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            background-color: rgba(30,64,175,0.3);
            color: #c7d2fe;
            border: 1px solid rgba(129,140,248,0.7);
        }

        .small {
            font-size: 0.8rem;
            color: var(--text-soft);
        }

        .price {
            font-variant-numeric: tabular-nums;
        }

        .actions-group {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            justify-content: flex-end;
        }

        .chip-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-decoration: none;
            cursor: pointer;
            transition:
                background-color 0.12s ease,
                border-color 0.12s ease,
                transform 0.12s ease,
                box-shadow 0.12s ease;
            white-space: nowrap;
        }

        .chip-btn span.icon {
            margin-right: 4px;
            font-size: 0.9rem;
        }

        .chip-edit {
            background-color: rgba(59,130,246,0.18);
            border-color: #60a5fa;
            color: #bfdbfe;
            box-shadow: 0 6px 14px rgba(37,99,235,0.35);
        }

        .chip-delete {
            background-color: rgba(248,113,113,0.16);
            border-color: #f97373;
            color: #fecaca;
            box-shadow: 0 6px 14px rgba(220,38,38,0.35);
        }

        .chip-pdf {
            background-color: rgba(45,212,191,0.20);
            border-color: #14b8a6;
            color: #a5f3fc;
            box-shadow: 0 6px 14px rgba(45,212,191,0.35);
        }

        .chip-mail {
            background-color: rgba(248,113,113,0.20);
            border-color: #f97373;
            color: #fecaca;
            box-shadow: 0 6px 14px rgba(220,38,38,0.35);
        }

        .chip-delete-disabled,
        .chip-disabled-generic {
            background-color: var(--chip-disabled-bg);
            border-color: var(--chip-disabled-border);
            color: var(--chip-disabled-text);
            cursor: default;
            box-shadow: none;
        }

        .chip-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15,23,42,0.6);
        }

        .chip-edit:hover {
            background-color: rgba(59,130,246,0.3);
        }

        .chip-delete:hover {
            background-color: rgba(248,113,113,0.3);
        }

        .chip-pdf:hover {
            background-color: rgba(45,212,191,0.32);
        }

        .chip-mail:hover {
            background-color: rgba(248,113,113,0.32);
        }

        .chip-delete-disabled:hover,
        .chip-disabled-generic:hover {
            transform: none;
            box-shadow: none;
            background-color: var(--chip-disabled-bg);
        }

        .card-footer {
            padding: 6px 4px 2px;
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--text-soft);
        }

.sense-factura {
    display: block;
    margin: 0 auto;
    padding: 2px 8px;
    border-radius: 999px;
    background-color: #ffffff;
    color: #b91c1c;
    border: 1px solid #b91c1c;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    text-align: center;
}


        @media (max-width: 780px) {
            .panel {
                padding: 14px 10px;
            }
            .card {
                border-radius: 0;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            .filters {
                flex-direction: column;
                align-items: flex-start;
            }
            .filters button {
                margin-left: 0;
            }
            .col-id {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="panel">
        <div class="header">
            <div class="title-block">
                <h1>Albarans</h1>
                <p>Capçaleres d’albarans vinculades a clients.</p>
            </div>
            <div class="badge">
                <span class="badge-dot"></span>
                VENDES · ALBARANS
            </div>
        </div>

        <!-- FILTRES -->
        <form method="get" class="filters">
            <div>
                <label>Client
                    <select name="client_id">
                        <option value="">Tots</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                <?= ($clientFilter !== '' && (int)$clientFilter === (int)$c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div>
                <label>
                    <input type="checkbox" name="pendents" value="1"
                        <?= $pendentsOnly ? 'checked' : '' ?>>
                    Només pendents de facturar
                </label>
            </div>
            <button type="submit">Filtrar</button>
            <a href="albarans_list.php" class="clear-link">Treure filtres</a>
        </form>

        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <h2>Llista d’albarans</h2>
                    <p>Consulta números, dates, clients, factures associades, imports i accions ràpides.</p>
                </div>
                <div class="card-header-right">
                    <div class="pill">
                        <span class="icon">📄</span>
                        Registre d’albarans
                    </div>
                    <div class="actions-header">
                        <?php if ($tePermisEscriptura): ?>
                            <a href="albarans_form.php" class="btn btn-new">
                                <span class="icon">＋</span> Nou albarà
                            </a>
                        <?php else: ?>
                            <span class="btn btn-new btn-new-disabled" title="Permís només de lectura">
                                <span class="icon">🔒</span> Només lectura
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="table-shell">
                <div class="table-container">
                    <table>
                        <thead>
                        <tr>
                            <th class="col-id">ID</th>
                            <th class="col-num">Núm. albarà</th>
                            <th class="col-factura">Factura</th>
                            <th class="col-data">Data</th>
                            <th>Client / Adreça entrega</th>
                            <th class="col-total">Import</th>
                            <th class="col-actions">Accions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($albarans)): ?>
                            <tr>
                                <td colspan="7" style="padding:18px 12px; text-align:center; color:var(--text-soft);">
                                    No s’ha trobat cap albarà amb els filtres actuals.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($albarans as $a): ?>
                                <?php
                                $estaFacturat = !empty($a['esta_facturat']);
                                $numFactura   = $a['num_factura'] ?? null;
                                ?>
                                <tr>
                                    <td class="col-id">
                                        <span class="tag">#<?= htmlspecialchars($a['id']) ?></span>
                                    </td>
                                    <td class="col-num">
                                        <?= htmlspecialchars($a['num_albara']) ?>
                                    </td>
                                    <td class="col-factura">
                                        <?php if ($numFactura !== null): ?>
                                            <span class="tag">F<?= htmlspecialchars($numFactura) ?></span>
                                        <?php else: ?>
                                            <span class="sense-factura">Sense factura</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-data">
                                        <?php
                                        $data = $a['data_albara']
                                            ? (new DateTime($a['data_albara']))->format('d/m/Y')
                                            : '';
                                        ?>
                                        <?= htmlspecialchars($data) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($a['client_nom']) ?><br>
                                        <span class="small">
                                            <?= htmlspecialchars($a['adreca_entrega']) ?>
                                            <?php if ($estaFacturat): ?>
                                                · <strong>Facturat</strong>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="col-total">
                                        <span class="price">
                                            <?= htmlspecialchars(number_format((float)$a['import_total'], 2, ',', '.')) ?> €
                                        </span>
                                    </td>
                                    <td class="col-actions">
                                        <div class="actions-group">
                                            <!-- Editar albarà -->
                                            <a class="chip-btn chip-edit"
                                               href="albarans_form.php?id=<?= (int)$a['id'] ?>">
                                                <span class="icon">✏️</span> Editar
                                            </a>

                                            <!-- PDF de l'albarà -->
                                            <a class="chip-btn chip-pdf"
                                               href="albara_pdf.php?id=<?= (int)$a['id'] ?>"
                                               target="_blank">
                                                <span class="icon">📄</span> PDF
                                            </a>

                                            <!-- Enviar mail albarà -->
                                            <a class="chip-btn chip-mail"
                                               href="albara_sendmail.php?id=<?= (int)$a['id'] ?>">
                                                <span class="icon">✉️</span> Mail
                                            </a>

                                            <!-- Esborrar (només si tePermisEscriptura i no facturat) -->
                                            <?php if (!$tePermisEscriptura): ?>
                                                <span class="chip-btn chip-disabled-generic"
                                                      title="Només lectura: no es poden esborrar albarans.">
                                                    <span class="icon">🔒</span>
                                                </span>
                                            <?php elseif ($estaFacturat): ?>
                                                <span class="chip-btn chip-disabled-generic"
                                                      title="No es pot esborrar: l’albarà ja està facturat.">
                                                    <span class="icon">🔒</span>
                                                </span>
                                            <?php else: ?>
                                                <a class="chip-btn chip-delete"
                                                   href="albarans_delete.php?id=<?= (int)$a['id'] ?>"
                                                   onclick="return confirm('Segur que vols esborrar aquest albarà i les seves línies?');">
                                                    <span class="icon">🗑️</span>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Fila total imports -->
                            <tr>
                                <td colspan="5" style="text-align:right; padding:10px 10px; font-weight:600;">
                                    Total imports:
                                </td>
                                <td class="col-total" style="font-weight:600;">
                                    <?= htmlspecialchars(number_format($totalGlobal, 2, ',', '.')) ?> €
                                </td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <span><?= count($albarans) ?> albarà(ns) trobats.</span>
                    <?php if ($clientFilter !== '' || $pendentsOnly): ?>
                        <span>Filtres actius.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

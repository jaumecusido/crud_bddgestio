<?php
session_start();

require_once 'db.php';
$pdo = getPDO();

// Carregar usuari loguejat
$usuari = null;
$permisMestres = 0;

if (!empty($_SESSION['usuari_id'])) {
    $stmtUser = $pdo->prepare("SELECT permis_mestres FROM usuaris WHERE id = :id AND actiu = TRUE");
    $stmtUser->execute(['id' => $_SESSION['usuari_id']]);
    $rowUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($rowUser) {
        $permisMestres = (int)$rowUser['permis_mestres'];
    }
}

// Si no té ni lectura (0), bloquejar accés
if ($permisMestres < 1) {
    http_response_code(403);
    echo "<h2>Accés no permès al mòdul d'articles.</h2>";
    exit;
}

$tePermisEscriptura = $permisMestres >= 2;

// Llegir filtre de descripció (GET)
$descFilter = isset($_GET['desc']) ? trim($_GET['desc']) : '';

// Consulta amb filtre opcional per descripció
$sql = "
    SELECT a.id,
           a.codi,
           a.descripcio,
           a.preu,
           a.iva,
           EXISTS (
               SELECT 1
               FROM albara_linies l
               WHERE l.article_id = a.id
           ) OR EXISTS (
               SELECT 1
               FROM factura_linies fl
               WHERE fl.article_id = a.id
           ) AS te_moviments
    FROM articles a
";
$params = [];

if ($descFilter !== '') {
    $sql .= " WHERE a.descripcio ILIKE :desc";
    $params['desc'] = '%' . $descFilter . '%';
}

$sql .= " ORDER BY a.id";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
}
$stmt->execute();
$arts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Articles</title>
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
            --btn-new-bg: #fb923c;
            --btn-new-border: #fdba74;
            --btn-back-border: rgba(148,163,184,0.8);
            --row-even: rgba(15,23,42,0.96);
            --row-odd: rgba(17,24,39,0.98);
            --row-hover: rgba(30,64,175,0.55);
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
            background: #22c55e;
            box-shadow: 0 0 0 4px rgba(34,197,94,0.25);
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

        .filters input[type="text"] {
            margin-left: 6px;
            padding: 5px 8px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,0.9);
            background-color: rgba(15,23,42,0.9);
            color: var(--text-main);
            font-size: 0.8rem;
            min-width: 220px;
        }

        .filters input::placeholder {
            color: #6b7280;
        }

        .filters button {
            margin-left: auto;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid rgba(56,189,248,0.9);
            background-color: rgba(56,189,248,0.12);
            color: #e0f2fe;
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
                0 0 0 1px rgba(15,118,110,0.35);
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
            background: radial-gradient(circle at top left, #fed7aa, #fb923c);
            border-color: var(--btn-new-border);
            color: #111827;
            box-shadow: 0 12px 26px rgba(249,115,22,0.6);
        }

        .btn-new-disabled {
            background-color: var(--chip-disabled-bg);
            border-color: var(--chip-disabled-border);
            color: var(--chip-disabled-text);
            box-shadow: none;
            cursor: default;
        }

        .btn-new:hover {
            background: radial-gradient(circle at top left, #fed7aa, #f97316);
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(248,113,22,0.75);
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

        .col-preu,
        .col-iva {
            text-align: right;
            white-space: nowrap;
        }

        .col-actions {
            width: 230px;
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

        .price {
            font-variant-numeric: tabular-nums;
        }

        .price span.symbol {
            color: #94a3b8;
            margin-right: 2px;
        }

        .empty {
            padding: 18px 12px;
            font-size: 0.9rem;
            color: var(--text-soft);
            text-align: center;
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

        .chip-delete-disabled {
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

        .chip-delete-disabled:hover {
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

        @media (max-width: 720px) {
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
            .actions-header {
                width: 100%;
                justify-content: flex-start;
            }
            .filters {
                flex-direction: column;
                align-items: flex-start;
            }
            .filters button {
                margin-left: 0;
            }
            .col-iva {
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
                <h1>Articles</h1>
                <p>Catàleg d’articles disponibles al sistema.</p>
            </div>
            <div class="badge">
                <span class="badge-dot"></span>
                MESTRES · ARTICLES
            </div>
        </div>

        <form method="get" class="filters">
            <div>
                <label>Descripció
                    <input type="text" name="desc"
                           value="<?= htmlspecialchars($descFilter) ?>"
                           placeholder="Cerca per descripció...">
                </label>
            </div>
            <button type="submit">Filtrar</button>
            <?php if ($descFilter !== ''): ?>
                <a href="articles_list.php" class="clear-link">Treure filtre</a>
            <?php endif; ?>
        </form>

        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <h2>Llista d’articles</h2>
                    <p>Gestiona codis, descripcions, preus i tipus d’IVA.</p>
                </div>
                <div class="card-header-right">
                    <div class="pill">
                        <span class="icon">📦</span>
                        Catàleg actiu
                    </div>
                    <div class="actions-header">
                        <?php if ($tePermisEscriptura): ?>
                            <a href="articles_form.php" class="btn btn-new">
                                <span class="icon">＋</span> Nou article
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
                            <th>Codi</th>
                            <th>Descripció</th>
                            <th class="col-preu">Preu</th>
                            <th class="col-iva">IVA</th>
                            <th class="col-actions">Accions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($arts)): ?>
                            <tr>
                                <td colspan="6" class="empty">
                                    No s’ha trobat cap article amb els filtres actuals.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($arts as $a): ?>
                                <?php
                                $teMoviments = !empty($a['te_moviments']);
                                ?>
                                <tr>
                                    <td class="col-id">
                                        <span class="tag">#<?= htmlspecialchars($a['id']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($a['codi']) ?></td>
                                    <td><?= htmlspecialchars($a['descripcio']) ?></td>
                                    <td class="col-preu">
                                        <span class="price">
                                            <span class="symbol">€</span>
                                            <?= htmlspecialchars(number_format((float)$a['preu'], 2, ',', '.')) ?>
                                        </span>
                                    </td>
                                    <td class="col-iva">
                                        <?= htmlspecialchars(number_format((float)$a['iva'], 2, ',', '.')) ?> %
                                    </td>
                                    <td class="col-actions">
                                        <div class="actions-group">
                                            <a class="chip-btn chip-edit"
                                               href="articles_form.php?id=<?= $a['id'] ?>">
                                                <span class="icon">✏️</span> Editar
                                            </a>

                                            <?php if (!$tePermisEscriptura): ?>
                                                <span class="chip-btn chip-delete-disabled"
                                                      title="Només lectura: no es poden esborrar articles.">
                                                    <span class="icon">🔒</span> Només lectura
                                                </span>
                                            <?php elseif ($teMoviments): ?>
                                                <span class="chip-btn chip-delete-disabled"
                                                      title="No es pot esborrar: té moviments d’albarans o factures.">
                                                    <span class="icon">🔒</span> No esborrable
                                                </span>
                                            <?php else: ?>
                                                <a class="chip-btn chip-delete"
                                                   href="articles_delete.php?id=<?= $a['id'] ?>"
                                                   onclick="return confirm('Segur que vols esborrar aquest article?');">
                                                    <span class="icon">🗑️</span> Esborrar
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <span><?= count($arts) ?> article(s) trobats.</span>
                    <?php if ($descFilter !== ''): ?>
                        <span>Filtre: «<?= htmlspecialchars($descFilter) ?>»</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

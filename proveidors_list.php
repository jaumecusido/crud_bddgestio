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
    echo "<h2>Accés no permès al mòdul de proveïdors.</h2>";
    exit;
}

// Filtre per nom (GET)
$nomFilter = isset($_GET['nom']) ? trim($_GET['nom']) : '';

// SQL amb filtre opcional per nom
$sql = "
    SELECT id, nom, nif, telefon, email
    FROM proveidors
";
$params = [];

if ($nomFilter !== '') {
    $sql .= " WHERE nom ILIKE :nom";
    $params['nom'] = '%' . $nomFilter . '%';
}

$sql .= " ORDER BY id";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
}
$stmt->execute();
$proveidors = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Proveïdors</title>
    <style>
        :root {
            --bg: #020617;
            --panel-bg: #020617;
            --card-bg: rgba(15,23,42,0.96);
            --border-soft: rgba(148,163,184,0.4);
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
            --accent: #facc15;
            --accent-soft: rgba(250,204,21,0.22);
            --btn-new-bg: #facc15;
            --btn-new-border: #fef9c3;
            --btn-back-border: rgba(148,163,184,0.8);
            --row-even: rgba(15,23,42,0.96);
            --row-odd: rgba(17,24,39,0.98);
            --row-hover: rgba(250,204,21,0.28);
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
                radial-gradient(circle at top, rgba(250,204,21,0.25) 0, rgba(15,23,42,1) 40%, #000 100%);
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
            background: #fbbf24;
            box-shadow: 0 0 0 4px rgba(250,204,21,0.35);
        }

        .filters {
            margin-top: 4px;
            padding: 8px 10px;
            border-radius: 14px;
            background-color: var(--filter-bg);
            border: 1px solid var(--filter-border);
            display: flex;
            flex-wrap: wrap;
            gap: 8px 12px;
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
            border: 1px solid rgba(250,204,21,0.9);
            background-color: rgba(250,204,21,0.18);
            color: #fef9c3;
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
            background: radial-gradient(circle at top left, rgba(250,204,21,0.22), rgba(15,23,42,0.98));
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            box-shadow:
                0 22px 50px rgba(15,23,42,0.75),
                0 0 0 1px rgba(202,138,4,0.4);
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
            border: 1px solid rgba(250,204,21,0.75);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #fef9c3;
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

        .btn-back {
            background-color: transparent;
            border-color: var(--btn-back-border);
            color: var(--text-soft);
        }

        .btn-back:hover {
            background-color: rgba(15,23,42,0.9);
            color: var(--text-main);
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15,23,42,0.75);
        }

        .btn-new {
            background: radial-gradient(circle at top left, #fef9c3, #facc15);
            border-color: var(--btn-new-border);
            color: #422006;
            box-shadow: 0 12px 26px rgba(250,204,21,0.65);
        }

        .btn-new-disabled {
            background-color: var(--chip-disabled-bg);
            border-color: var(--chip-disabled-border);
            color: var(--chip-disabled-text);
            box-shadow: none;
            cursor: default;
        }

        .btn-new:hover {
            background: radial-gradient(circle at top left, #fef9c3, #eab308);
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(234,179,8,0.85);
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
            background: linear-gradient(to right, rgba(250,204,21,0.25), rgba(254,249,195,0.32));
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

        .col-actions {
            width: 190px;
            text-align: right;
        }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            background-color: rgba(250,204,21,0.18);
            color: #fef9c3;
            border: 1px solid rgba(250,250,150,0.85);
        }

        .small {
            font-size: 0.8rem;
            color: var(--text-soft);
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
            .col-email {
                display: none;
            }
            .filters {
                flex-direction: column;
                align-items: flex-start;
            }
            .filters button {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="panel">
        <div class="header">
            <div class="title-block">
                <h1>Proveïdors</h1>
                <p>Llista de proveïdors registrats al sistema.</p>
            </div>
            <div class="badge">
                <span class="badge-dot"></span>
                MESTRES · PROVEÏDORS
            </div>
        </div>

        <form method="get" class="filters">
            <label>Nom
                <input type="text" name="nom"
                       value="<?= htmlspecialchars($nomFilter) ?>"
                       placeholder="Cerca per nom...">
            </label>
            <button type="submit">Filtrar</button>
            <?php if ($nomFilter !== ''): ?>
                <a href="proveidors_list.php" class="clear-link">Treure filtre</a>
            <?php endif; ?>
        </form>

        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <h2>Llista de proveïdors</h2>
                    <p>Gestiona dades fiscals i de contacte dels proveïdors habituals.</p>
                </div>
                <div class="card-header-right">
                    <div class="pill">
                        <span class="icon">🏭</span>
                        Xarxa de proveïdors
                    </div>
                    <div class="actions-header">
                        <?php if ($tePermisEscriptura): ?>
                            <a href="proveidors_form.php" class="btn btn-new">
                                <span class="icon">＋</span> Nou proveïdor
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
                            <th>Nom</th>
                            <th>NIF</th>
                            <th>Telèfon</th>
                            <th class="col-email">Email</th>
                            <th class="col-actions">Accions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($proveidors)): ?>
                            <tr>
                                <td colspan="6" class="empty">
                                    No s’ha trobat cap proveïdor amb el filtre actual.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($proveidors as $p): ?>
                                <tr>
                                    <td class="col-id">
                                        <span class="tag">#<?= htmlspecialchars($p['id']) ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($p['nom']) ?><br>
                                        <span class="small"><?= htmlspecialchars($p['email']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($p['nif']) ?></td>
                                    <td><?= htmlspecialchars($p['telefon']) ?></td>
                                    <td class="col-email"><?= htmlspecialchars($p['email']) ?></td>
                                    <td class="col-actions">
                                        <div class="actions-group">
                                            <a class="chip-btn chip-edit"
                                               href="proveidors_form.php?id=<?= $p['id'] ?>">
                                                <span class="icon">✏️</span> Editar
                                            </a>
                                            <?php if ($tePermisEscriptura): ?>
                                                <a class="chip-btn chip-delete"
                                                   href="proveidors_delete.php?id=<?= $p['id'] ?>"
                                                   onclick="return confirm('Segur que vols esborrar aquest proveïdor?');">
                                                    <span class="icon">🗑️</span> Esborrar
                                                </a>
                                            <?php else: ?>
                                                <span class="chip-btn chip-delete-disabled"
                                                      title="Només lectura: no es poden esborrar proveïdors.">
                                                    <span class="icon">🔒</span> Només lectura
                                                </span>
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
                    <span><?= count($proveidors) ?> proveïdor(s) trobats.</span>
                    <?php if ($nomFilter !== ''): ?>
                        <span>Filtre: «<?= htmlspecialchars($nomFilter) ?>»</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

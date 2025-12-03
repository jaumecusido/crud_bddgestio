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
    echo "<h2>Accés no permès al mòdul de clients.</h2>";
    exit;
}

// Filtre per nom (GET)
$nomFilter = isset($_GET['nom']) ? trim($_GET['nom']) : '';

// Carregar clients i si tenen moviments (albarans o factures)
$sql = "
    SELECT c.id,
           c.nom,
           c.nif,
           c.telefon,
           c.email,
           EXISTS (
               SELECT 1
               FROM albarans a
               WHERE a.client_id = c.id
           ) OR EXISTS (
               SELECT 1
               FROM factures f
               WHERE f.client_id = c.id
           ) AS te_moviments
    FROM clients c
";
$params = [];

if ($nomFilter !== '') {
    $sql .= " WHERE c.nom ILIKE :nom";
    $params['nom'] = '%' . $nomFilter . '%';
}

$sql .= " ORDER BY c.id";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
}
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Clients</title>
    <style>
        :root {
            --bg: #020617;
            --panel-bg: #020617;
            --card-bg: rgba(15,23,42,0.96);
            --border-soft: rgba(148,163,184,0.4);
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
            --accent: #4f46e5;
            --accent-soft: rgba(129,140,248,0.22);
            --btn-new-bg: #22c55e;
            --btn-new-border: #bbf7d0;
            --btn-back-border: rgba(148,163,184,0.8);
            --row-even: rgba(15,23,42,0.96);
            --row-odd: rgba(17,24,39,0.98);
            --row-hover: rgba(34,197,94,0.35);
            --chip-disabled-bg: rgba(31,41,55,0.9);
            --chip-disabled-border: rgba(75,85,99,0.9);
            --chip-disabled-text: #6b7280;
            --filter-bg: rgba(15,23,42,0.95);
            --filter-border: rgba(148,163,184,0.7);
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
                radial-gradient(circle at top, rgba(52,211,153,0.22) 0, rgba(15,23,42,1) 40%, #000 100%);
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
            border: 1px solid rgba(34,197,94,0.9);
            background-color: rgba(34,197,94,0.16);
            color: #bbf7d0;
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
            background: radial-gradient(circle at top left, rgba(52,211,153,0.18), rgba(15,23,42,0.98));
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            box-shadow:
                0 22px 50px rgba(15,23,42,0.75),
                0 0 0 1px rgba(21,128,61,0.4);
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
            border: 1px solid rgba(129,140,248,0.7);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #e0e7ff;
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
            background: radial-gradient(circle at top left, #bbf7d0, #22c55e);
            border-color: var(--btn-new-border);
            color: #052e16;
            box-shadow: 0 12px 26px rgba(34,197,94,0.6);
        }

        .btn-new-disabled {
            background-color: var(--chip-disabled-bg);
            border-color: var(--chip-disabled-border);
            color: var(--chip-disabled-text);
            box-shadow: none;
            cursor: default;
        }

        .btn-new:hover {
            background: radial-gradient(circle at top left, #bbf7d0, #16a34a);
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(22,163,74,0.8);
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
            background: linear-gradient(to right, rgba(34,197,94,0.25), rgba(74,222,128,0.32));
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
            width: 220px;
            text-align: right;
        }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            background-color: rgba(22,163,74,0.18);
            color: #bbf7d0;
            border: 1px solid rgba(74,222,128,0.85);
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
                <h1>Clients</h1>
                <p>Llista de clients registrats al sistema.</p>
            </div>
            <div class="badge">
                <span class="badge-dot"></span>
                MESTRES · CLIENTS
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
                <a href="clients_list.php" class="clear-link">Treure filtre</a>
            <?php endif; ?>
        </form>

        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <h2>Llista de clients</h2>
                    <p>Gestiona dades fiscals, contacte i ús en albarans i factures.</p>
                </div>
                <div class="card-header-right">
                    <div class="pill">
                        <span class="icon">👥</span>
                        Base de clients
                    </div>
                    <div class="actions-header">
                        <?php if ($tePermisEscriptura): ?>
                            <a href="clients_form.php" class="btn btn-new">
                                <span class="icon">＋</span> Nou client
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
                        <?php if (empty($clients)): ?>
                            <tr>
                                <td colspan="6" class="empty">
                                    No s’ha trobat cap client amb el filtre actual.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clients as $c): ?>
                                <?php $teMoviments = !empty($c['te_moviments']); ?>
                                <tr>
                                    <td class="col-id">
                                        <span class="tag">#<?= htmlspecialchars($c['id']) ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($c['nom']) ?><br>
                                        <span class="small"><?= htmlspecialchars($c['email']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($c['nif']) ?></td>
                                    <td><?= htmlspecialchars($c['telefon']) ?></td>
                                    <td class="col-email"><?= htmlspecialchars($c['email']) ?></td>
                                    <td class="col-actions">
                                        <div class="actions-group">
                                            <a class="chip-btn chip-edit"
                                               href="clients_form.php?id=<?= $c['id'] ?>">
                                                <span class="icon">✏️</span> Editar
                                            </a>

                                            <?php if (!$tePermisEscriptura): ?>
                                                <span class="chip-btn chip-delete-disabled"
                                                      title="Només lectura: no es poden esborrar clients.">
                                                    <span class="icon">🔒</span> Només lectura
                                                </span>
                                            <?php elseif ($teMoviments): ?>
                                                <span class="chip-btn chip-delete-disabled"
                                                      title="No es pot esborrar: el client té albarans o factures associades.">
                                                    <span class="icon">🔒</span> No esborrable
                                                </span>
                                            <?php else: ?>
                                                <a class="chip-btn chip-delete"
                                                   href="clients_delete.php?id=<?= $c['id'] ?>"
                                                   onclick="return confirm('Segur que vols esborrar aquest client?');">
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
                    <span><?= count($clients) ?> client(s) trobats.</span>
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

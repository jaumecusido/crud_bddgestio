
<?php
require_once 'db.php';
$pdo = getPDO();

$stmt = $pdo->query('SELECT id, codi, descripcio, preu, iva FROM articles ORDER BY id');
$arts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Articles</title>
    <style>
        :root {
            --bg: #f5f7fb;
            --card-bg: #ffffff;
            --text-main: #334155;
            --text-soft: #64748b;
            --accent: #0ea5e9;
            --accent-soft: #e0f2fe;
            --btn-new-bg: #fed7aa;      /* taronja pastel */
            --btn-new-border: #fb923c;
            --btn-back-bg: #e5e7eb;
            --btn-back-border: #cbd5f5;
            --row-hover: #eef2ff;
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
            max-width: 960px;
            width: 100%;
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
            align-items: flex-end;
            gap: 16px;
            margin-bottom: 18px;
        }

        .title-block h1 {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .title-block p {
            font-size: 0.9rem;
            color: var(--text-soft);
        }

        .actions {
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

        .btn-new {
            background-color: var(--btn-new-bg);
            border-color: var(--btn-new-border);
            color: #1e293b;
            box-shadow: 0 8px 18px rgba(251, 146, 60, 0.45);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.18);
        }

        .table-wrapper {
            margin-top: 10px;
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
            background: linear-gradient(to right, #bae6fd, #fee2e2);
        }

        th, td {
            padding: 10px 12px;
            text-align: left;
        }

        th {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #4b5563;
            border-bottom: 1px solid rgba(148, 163, 184, 0.7);
        }

        tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }

        tbody tr:hover {
            background-color: var(--row-hover);
        }

        td {
            border-bottom: 1px solid rgba(226, 232, 240, 0.9);
            color: #111827;
        }

        .col-id {
            width: 60px;
            color: #6b7280;
            font-size: 0.8rem;
        }

        .col-actions {
            width: 170px;
            text-align: right;
        }

        .col-preu, .col-iva {
            text-align: right;
            white-space: nowrap;
        }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            background-color: #e5e7eb;
            color: #4b5563;
        }

        .price {
            font-variant-numeric: tabular-nums;
        }

        .price span.symbol {
            color: #9ca3af;
            margin-right: 2px;
        }

        .empty {
            padding: 16px 12px;
            font-size: 0.9rem;
            color: var(--text-soft);
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
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.04em;
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
            background-color: #dbeafe;      /* blau pastel */
            border-color: #60a5fa;
            color: #1d4ed8;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
        }

        .chip-delete {
            background-color: #fee2e2;      /* vermell pastel */
            border-color: #f97373;
            color: #b91c1c;
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.25);
        }

        .chip-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.25);
        }

        .chip-edit:hover {
            background-color: #bfdbfe;
        }

        .chip-delete:hover {
            background-color: #fecaca;
        }

        @media (max-width: 720px) {
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

            .actions {
                justify-content: flex-start;
            }

            .col-iva {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="title-block">
                <h1>Articles</h1>
                <p>Catàleg d’articles disponibles a BddGestio.</p>
            </div>
            <div class="actions">
                <a href="index.php" class="btn btn-back">
                    <span class="icon">←</span> Menú
                </a>
                <a href="articles_form.php" class="btn btn-new">
                    <span class="icon">＋</span> Nou article
                </a>
            </div>
        </div>

        <div class="table-wrapper">
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
                            Encara no hi ha cap article. Fes clic a «Nou article» per afegir-ne el primer.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($arts as $a): ?>
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
                                    <a class="chip-btn chip-delete"
                                       href="articles_delete.php?id=<?= $a['id'] ?>"
                                       onclick="return confirm('Segur que vols esborrar aquest article?');">
                                        <span class="icon">🗑️</span> Esborrar
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
```
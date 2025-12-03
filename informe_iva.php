<?php
require_once 'db.php';
$pdo = getPDO();

$anyActual = (int)date('Y');
$any       = isset($_GET['any']) ? (int)$_GET['any'] : $anyActual;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;
if ($trimestre < 1 || $trimestre > 4) {
    $trimestre = 1;
}

$sqlTotalClients = "
    SELECT COALESCE(SUM(f.import_total), 0) AS total
    FROM factures f
    WHERE EXTRACT(YEAR FROM f.data_factura) = :any
      AND EXTRACT(QUARTER FROM f.data_factura) = :tri
";

$sqlTotalProv = "
    SELECT COALESCE(SUM(fp.import_total), 0) AS total
    FROM factures_proveidors fp
    WHERE EXTRACT(YEAR FROM fp.data_factura) = :any
      AND EXTRACT(QUARTER FROM fp.data_factura) = :tri
";

$sqlDetallClients = "
    SELECT f.id,
           f.num_factura,
           f.data_factura,
           f.import_total,
           c.nom AS client_nom
    FROM factures f
    LEFT JOIN clients c ON c.id = f.client_id
    WHERE EXTRACT(YEAR FROM f.data_factura) = :any
      AND EXTRACT(QUARTER FROM f.data_factura) = :tri
    ORDER BY f.data_factura, f.num_factura
";

$sqlDetallProv = "
    SELECT fp.id,
           fp.num_factura,
           fp.data_factura,
           fp.import_total,
           p.nom AS proveidor_nom
    FROM factures_proveidors fp
    LEFT JOIN proveidors p ON p.id = fp.proveidor_id
    WHERE EXTRACT(YEAR FROM fp.data_factura) = :any
      AND EXTRACT(QUARTER FROM fp.data_factura) = :tri
    ORDER BY fp.data_factura, fp.num_factura
";

$params = ['any' => $any, 'tri' => $trimestre];

$stmt = $pdo->prepare($sqlTotalClients);
$stmt->execute($params);
$totalClients = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare($sqlTotalProv);
$stmt->execute($params);
$totalProv = (float)$stmt->fetchColumn();

$resultat = $totalClients - $totalProv;

/**
 * Percentatge del resultat sobre el facturat (marge de benefici).
 * margin = (benefici / facturat) * 100, si el facturat és > 0. [web:31][web:41]
 */
if ($totalClients > 0) {
    $pctResultat = round(($resultat / $totalClients) * 100, 1);
} else {
    $pctResultat = 0;
}

$stmt = $pdo->prepare($sqlDetallClients);
$stmt->execute($params);
$facturesClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare($sqlDetallProv);
$stmt->execute($params);
$facturesProv = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatEuros(float $n): string {
    return number_format($n, 2, ',', '.') . ' €';
}

function labelTrimestre(int $t): string {
    return 'T' . $t;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Informe trimestral resultat</title>
    <style>
        :root {
            --bg: #020617;
            --card-bg: rgba(15,23,42,0.96);
            --border-soft: rgba(148,163,184,0.5);
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
            --accent-pos: #22c55e;
            --accent-neg: #ef4444;
            --filter-bg: rgba(15,23,42,0.9);
            --filter-border: rgba(148,163,184,0.75);

            /* Colors totals */
            --clients-main: #60a5fa;   /* blau */
            --prov-main:    #fb923c;   /* taronja */
            --result-pos:   #22c55e;   /* verd */
            --result-neg:   #ef4444;   /* vermell */

            /* Paletes per a files */
            --client-row-even: rgba(30,64,175,0.40);
            --client-row-odd:  rgba(30,64,175,0.18);
            --prov-row-even:   rgba(249,115,22,0.35);
            --prov-row-odd:    rgba(249,115,22,0.16);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgba(34,197,94,0.20) 0, rgba(15,23,42,1) 45%, #000 100%);
            color: var(--text-main);
            padding: 14px 16px;
        }

        .page {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            border-bottom: 1px solid rgba(31,41,55,0.9);
            padding-bottom: 6px;
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
            background-color: rgba(34,197,94,0.16);
            border: 1px solid rgba(34,197,94,0.6);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #bbf7d0;
        }

        .filters {
            margin-top: 4px;
            padding: 8px 10px;
            border-radius: 14px;
            background-color: var(--filter-bg);
            border: 1px solid var(--filter-border);
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            align-items: center;
            font-size: 0.8rem;
        }

        .filters label {
            color: var(--text-soft);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .filters input[type="number"],
        .filters select {
            margin-left: 4px;
            padding: 4px 6px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,0.9);
            background-color: rgba(15,23,42,0.9);
            color: var(--text-main);
            font-size: 0.8rem;
            width: 90px;
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

        .summary-row {
            margin-top: 8px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 10px;
        }

        .card {
            background-color: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-soft);
            padding: 10px 10px 8px;
            box-shadow:
                0 18px 40px rgba(15,23,42,0.8),
                0 0 0 1px rgba(30,64,175,0.5);
        }

        .card h2 {
            font-size: 0.8rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-bottom: 4px;
            text-align: center;
        }

        .card .big-number {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .card .subtitle {
            font-size: 0.75rem;
            color: var(--text-soft);
        }

        .big-number.pos { color: var(--result-pos); }
        .big-number.neg { color: var(--result-neg); }

        .lists-row {
            margin-top: 10px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 10px;
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
            color: #d1d5db;
            border-bottom: 1px solid rgba(55,65,81,0.9);
            background: linear-gradient(to right, rgba(30,64,175,0.8), rgba(15,23,42,0.95));
        }

        td {
            border-bottom: 1px solid rgba(31,41,55,0.9);
            color: #e5e7eb;
        }

        /* Alternança blavosa per a clients */
        .table-clients tbody tr:nth-child(even) {
            background-color: var(--client-row-even);
        }

        .table-clients tbody tr:nth-child(odd) {
            background-color: var(--client-row-odd);
        }

        /* Alternança ataronjada per a proveïdors */
        .table-prov tbody tr:nth-child(even) {
            background-color: var(--prov-row-even);
        }

        .table-prov tbody tr:nth-child(odd) {
            background-color: var(--prov-row-odd);
        }

        .right { text-align: right; }
        .nowrap { white-space: nowrap; }

        .empty {
            font-size: 0.8rem;
            color: var(--text-soft);
        }

        @media (max-width: 900px) {
            .summary-row { grid-template-columns: 1fr; }
            .lists-row { grid-template-columns: 1fr; }
            .filters { flex-direction: column; align-items: flex-start; }
            .filters button { margin-left: 0; }
        }

        .totals-line {
            display: flex;
            align-items: baseline;
            gap: 6px;
            justify-content: center;
        }

        .pct {
            font-size: 0.78rem;
            color: var(--text-soft);
        }

        .clients-color {
            color: var(--clients-main);
        }

        .prov-color {
            color: var(--prov-main);
        }

        .res-pos {
            color: var(--result-pos);
        }

        .res-neg {
            color: var(--result-neg);
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="title-block">
            <h1>Informe resultat trimestral</h1>
            <p>Factures de clients i proveïdors, amb resum de resultat per període.</p>
        </div>
        <div class="pill">
            📊 Any <?= htmlspecialchars($any) ?> · <?= htmlspecialchars(labelTrimestre($trimestre)) ?>
        </div>
    </div>

    <form method="get" class="filters">
        <label>Any
            <input type="number" name="any" min="2000" max="2100"
                   value="<?= htmlspecialchars($any) ?>">
        </label>
        <label>Trimestre
            <select name="trimestre">
                <?php for ($t = 1; $t <= 4; $t++): ?>
                    <option value="<?= $t ?>" <?= $t === $trimestre ? 'selected' : '' ?>>
                        <?= 'T' . $t ?>
                    </option>
                <?php endfor; ?>
            </select>
        </label>
        <button type="submit">Aplicar filtres</button>
    </form>

    <div class="summary-row">
        <!-- Clients en blau -->
        <section class="card">
            <h2>Clients</h2>
            <div class="big-number clients-color" style="text-align:center;">
                <?= htmlspecialchars(formatEuros($totalClients)) ?>
            </div>
            <p class="subtitle" style="text-align:center;">Total facturat a clients en el període seleccionat.</p>
        </section>

        <!-- Proveïdors en taronja -->
        <section class="card">
            <h2>Proveïdors</h2>
            <div class="big-number prov-color" style="text-align:center;">
                <?= htmlspecialchars(formatEuros($totalProv)) ?>
            </div>
            <p class="subtitle" style="text-align:center;">Total de compres a proveïdors en el període seleccionat.</p>
        </section>

        <!-- Resultat amb % sobre facturat -->
        <section class="card">
            <h2>Resultat</h2>
            <?php
            $classeRes = $resultat >= 0 ? 'res-pos' : 'res-neg';
            ?>
            <div class="totals-line">
                <div class="big-number <?= $classeRes ?>">
                    <?= htmlspecialchars(formatEuros($resultat)) ?>
                </div>
                <div class="pct <?= $classeRes ?>">
                    (<?= htmlspecialchars(number_format($pctResultat, 1, ',', '.')) ?> %)
                </div>
            </div>
            <p class="subtitle" style="text-align:center;">Benefici sobre el total facturat a clients.</p>
        </section>
    </div>

    <div class="lists-row">
        <section class="card">
            <h2>Factures de clients</h2>
            <?php if (empty($facturesClients)): ?>
                <p class="empty">No hi ha factures de clients en aquest període.</p>
            <?php else: ?>
                <table class="table-clients">
                    <thead>
                    <tr>
                        <th class="nowrap">Núm.</th>
                        <th class="nowrap">Data</th>
                        <th>Client</th>
                        <th class="right nowrap">Import</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($facturesClients as $f): ?>
                        <tr>
                            <td class="nowrap"><?= htmlspecialchars($f['num_factura']) ?></td>
                            <td class="nowrap">
                                <?= htmlspecialchars(
                                    $f['data_factura']
                                        ? (new DateTime($f['data_factura']))->format('d/m/Y')
                                        : ''
                                ) ?>
                            </td>
                            <td><?= htmlspecialchars($f['client_nom']) ?></td>
                            <td class="right nowrap">
                                <?= htmlspecialchars(number_format((float)$f['import_total'], 2, ',', '.')) ?> €
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Factures de proveïdors</h2>
            <?php if (empty($facturesProv)): ?>
                <p class="empty">No hi ha factures de proveïdors en aquest període.</p>
            <?php else: ?>
                <table class="table-prov">
                    <thead>
                    <tr>
                        <th class="nowrap">Núm.</th>
                        <th class="nowrap">Data</th>
                        <th>Proveïdor</th>
                        <th class="right nowrap">Import</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($facturesProv as $f): ?>
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
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>

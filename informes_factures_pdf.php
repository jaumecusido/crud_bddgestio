<?php
require_once 'db.php';
$pdo = getPDO();

// Ajusta el path segons on tinguis TCPDF
require_once __DIR__ . '/tcpdf/tcpdf.php';

// FILTRES
$dataInici = $_GET['data_inici'] ?? '';
$dataFi    = $_GET['data_fi'] ?? '';
$clientId  = $_GET['client_id'] ?? '';
$action    = $_GET['action'] ?? '';

// Carregar clients per al select
$clientsStmt = $pdo->query("SELECT id, nom FROM clients ORDER BY nom");
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Construir condicions
$where  = [];
$params = [];

if ($dataInici !== '') {
    $where[] = "f.data_factura >= :data_inici";
    $params['data_inici'] = $dataInici;
}
if ($dataFi !== '') {
    $where[] = "f.data_factura <= :data_fi";
    $params['data_fi'] = $dataFi;
}
if ($clientId !== '' && ctype_digit((string)$clientId)) {
    $where[] = "f.client_id = :client_id";
    $params['client_id'] = (int)$clientId;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT f.id,
           f.num_factura,
           f.data_factura,
           f.import_total,
           c.nom AS client_nom
    FROM factures f
    LEFT JOIN clients c ON c.id = f.client_id
    $whereSql
    ORDER BY f.data_factura, f.num_factura
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Si demanen PDF, generem i sortim
if ($action === 'pdf') {
    $pdf = new TCPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->AddPage();

    $title = 'Llistat de factures';
    if ($dataInici || $dataFi || $clientId) {
        $title .= ' (filtres aplicats)';
    }

    $html  = '<h2 style="font-family:helvetica;font-size:14px;">'.$title.'</h2>';
    $html .= '<table border="1" cellspacing="0" cellpadding="4" width="100%">';
    $html .= '<tr style="font-weight:bold;background-color:#eeeeee;">
                <td width="18%">Núm. factura</td>
                <td width="16%">Data</td>
                <td width="46%">Client</td>
                <td width="20%" align="right">Import</td>
              </tr>';

    foreach ($factures as $f) {
        $dataTxt = $f['data_factura']
            ? (new DateTime($f['data_factura']))->format('d/m/Y')
            : '';
        $html .= '<tr>
                    <td>'.htmlspecialchars($f['num_factura']).'</td>
                    <td>'.$dataTxt.'</td>
                    <td>'.htmlspecialchars($f['client_nom']).'</td>
                    <td align="right">'.number_format((float)$f['import_total'], 2, ',', '.').' €</td>
                  </tr>';
    }

    if (empty($factures)) {
        $html .= '<tr><td colspan="4" align="center">No hi ha factures per als filtres indicats.</td></tr>';
    }

    $html .= '</table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('llistat_factures.pdf', 'D');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Llistat de factures (PDF)</title>
    <style>
        :root {
            --bg: #020617;
            --card-bg: rgba(15,23,42,0.96);
            --border-soft: rgba(148,163,184,0.5);
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
            --accent: #22c55e;
            --filter-bg: rgba(15,23,42,0.9);
            --filter-border: rgba(148,163,184,0.75);
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
                radial-gradient(circle at top, rgba(34,197,94,0.2) 0, rgba(15,23,42,1) 45%, #000 100%);
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

        .filters input[type="date"],
        .filters select {
            margin-left: 4px;
            padding: 4px 6px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,0.9);
            background-color: rgba(15,23,42,0.9);
            color: var(--text-main);
            font-size: 0.8rem;
        }

        .filters .buttons {
            margin-left: auto;
            display: flex;
            gap: 8px;
        }

        .btn {
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

        .btn-secondary {
            border-color: rgba(148,163,184,0.9);
            background-color: transparent;
            color: #e5e7eb;
        }

        .list-card {
            margin-top: 8px;
            background-color: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-soft);
            padding: 10px;
            box-shadow:
                0 18px 40px rgba(15,23,42,0.8),
                0 0 0 1px rgba(30,64,175,0.5);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th, td {
            padding: 6px 8px;
            text-align: left;
        }

        th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #cbd5f5;
            border-bottom: 1px solid rgba(55,65,81,0.9);
            background: linear-gradient(to right, rgba(30,64,175,0.7), rgba(15,23,42,0.9));
        }

        tbody tr:nth-child(even) {
            background-color: rgba(15,23,42,0.95);
        }

        tbody tr:nth-child(odd) {
            background-color: rgba(17,24,39,0.98);
        }

        td {
            border-bottom: 1px solid rgba(31,41,55,0.9);
            color: #e5e7eb;
        }

        .right { text-align: right; }

        .empty {
            padding: 16px 8px;
            text-align: center;
            color: var(--text-soft);
        }

        @media (max-width: 720px) {
            .filters {
                flex-direction: column;
                align-items: flex-start;
            }
            .filters .buttons {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="title-block">
            <h1>Llistat de factures</h1>
            <p>Filtra per rang de dates i client i exporta el resultat a PDF.</p>
        </div>
        <div class="pill">
            🧾 Informe facturació
        </div>
    </div>

    <form method="get" class="filters">
        <label>Data inici
            <input type="date" name="data_inici"
                   value="<?= htmlspecialchars($dataInici) ?>">
        </label>
        <label>Data fi
            <input type="date" name="data_fi"
                   value="<?= htmlspecialchars($dataFi) ?>">
        </label>
        <label>Client
            <select name="client_id">
                <option value="">Tots</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>"
                        <?= ($clientId !== '' && (int)$clientId === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="buttons">
            <button type="submit" class="btn-secondary btn">Veure</button>
            <button type="submit" name="action" value="pdf" class="btn">
                PDF
            </button>
        </div>
    </form>

    <div class="list-card">
        <?php if (empty($factures)): ?>
            <div class="empty">No hi ha factures per als filtres indicats.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Núm. factura</th>
                    <th>Data</th>
                    <th>Client</th>
                    <th class="right">Import</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($factures as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['num_factura']) ?></td>
                        <td>
                            <?= htmlspecialchars(
                                $f['data_factura']
                                    ? (new DateTime($f['data_factura']))->format('d/m/Y')
                                    : ''
                            ) ?>
                        </td>
                        <td><?= htmlspecialchars($f['client_nom']) ?></td>
                        <td class="right">
                            <?= htmlspecialchars(number_format((float)$f['import_total'], 2, ',', '.')) ?> €
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

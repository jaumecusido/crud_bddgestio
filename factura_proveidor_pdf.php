<?php
require_once 'db.php';
require_once 'config.php';

// require_once 'vendor/autoload.php'; // Dompdf o la llibreria que facis servir

$pdo = getPDO();

$id = $_GET['factura_id'] ?? null;
if (!$id || !ctype_digit((string)$id)) {
    die('Identificador de factura no vàlid.');
}

// Carregar capçalera factura de proveïdor
$sql = "
    SELECT fp.*,
           p.nom      AS proveidor_nom,
           p.nif      AS proveidor_nif,
           p.adreca   AS proveidor_adreca,
           p.email    AS proveidor_email
      FROM factures_proveidors fp
      JOIN proveidors p ON p.id = fp.proveidor_id
     WHERE fp.id = :id
";
$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) {
    die('No s\'ha trobat la factura de proveïdor.');
}

// Carregar línies
$sql = "
    SELECT num_linia,
           codi_article,
           descripcio,
           quantitat,
           preu_unitari,
           (quantitat * preu_unitari) AS import
      FROM factura_proveidor_linies
     WHERE factura_id = :id
     ORDER BY num_linia
";
$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $id]);
$linies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recalcular total per seguretat
$total = 0;
foreach ($linies as $l) {
    $total += (float)$l['import'];
}

// ----------- Construcció HTML (copia l’estil del teu factura_pdf de client) -----------
ob_start();
?>
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
        }
        .header {
            width: 100%;
            margin-bottom: 20px;
        }
        .header-left { float: left; width: 55%; }
        .header-right { float: right; width: 40%; text-align: right; }
        .title {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .meta span {
            display: block;
        }
        .box {
            border: 1px solid #e5e7eb;
            padding: 8px 10px;
            margin-top: 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }
        th, td {
            padding: 6px 5px;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f3f4f6;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        .right { text-align: right; }
        .total-row td {
            border-top: 1px solid #9ca3af;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <div class="title">Factura proveïdor</div>
        <div class="meta">
            <span><strong>Proveïdor:</strong> <?= htmlspecialchars($factura['proveidor_nom']) ?></span>
            <span><strong>NIF:</strong> <?= htmlspecialchars($factura['proveidor_nif'] ?? '') ?></span>
            <span><strong>Adreça:</strong> <?= htmlspecialchars($factura['proveidor_adreca'] ?? '') ?></span>
            <span><strong>Email:</strong> <?= htmlspecialchars($factura['proveidor_email'] ?? '') ?></span>
        </div>
    </div>
    <div class="header-right">
        <div class="box">
            <span><strong>Núm. interna:</strong> <?= htmlspecialchars($factura['id']) ?></span>
            <span><strong>Núm. factura proveïdor:</strong> <?= htmlspecialchars($factura['num_factura_ext'] ?? '') ?></span>
            <span><strong>Data:</strong> <?= htmlspecialchars(
                $factura['data_factura']
                    ? (new DateTime($factura['data_factura']))->format('d/m/Y')
                    : ''
            ) ?></span>
        </div>
    </div>
    <div style="clear:both;"></div>
</div>

<?php if (!empty($factura['observacions'])): ?>
    <div class="box">
        <strong>Observacions:</strong><br>
        <?= nl2br(htmlspecialchars($factura['observacions'])) ?>
    </div>
<?php endif; ?>

<table>
    <thead>
    <tr>
        <th style="width:40px;">Línia</th>
        <th style="width:80px;">Codi</th>
        <th>Descripció</th>
        <th style="width:70px;" class="right">Quantitat</th>
        <th style="width:70px;" class="right">Preu</th>
        <th style="width:80px;" class="right">Import</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($linies)): ?>
        <tr>
            <td colspan="6">No hi ha línies a aquesta factura de proveïdor.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($linies as $l): ?>
            <tr>
                <td><?= htmlspecialchars($l['num_linia']) ?></td>
                <td><?= htmlspecialchars($l['codi_article']) ?></td>
                <td><?= htmlspecialchars($l['descripcio']) ?></td>
                <td class="right"><?= htmlspecialchars(number_format((float)$l['quantitat'], 2, ',', '.')) ?></td>
                <td class="right"><?= htmlspecialchars(number_format((float)$l['preu_unitari'], 4, ',', '.')) ?></td>
                <td class="right"><?= htmlspecialchars(number_format((float)$l['import'], 2, ',', '.')) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="5" class="right">Total</td>
            <td class="right"><?= htmlspecialchars(number_format($total, 2, ',', '.')) ?> €</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

// ---------- Generació PDF (adapta-ho a la teva llibreria) ----------

// Exemple amb Dompdf:
// use Dompdf\Dompdf;
// use Dompdf\Options;
//
// $options = new Options();
// $options->set('isRemoteEnabled', true);
// $dompdf = new Dompdf($options);
// $dompdf->loadHtml($html, 'UTF-8');
// $dompdf->setPaper('A4', 'portrait');
// $dompdf->render();
//
// $filename = 'factura_proveidor_' . $id . '.pdf';
// $dompdf->stream($filename, ['Attachment' => false]);

echo $html; // mentre no activis la sortida PDF real

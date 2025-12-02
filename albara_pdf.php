<?php
// Opcional: amagar només avisos "deprecated" de llibreries de tercers

error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/vendor/autoload.php';
require_once 'db.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$pdo = getPDO();

// Validar id d'albarà
$id = $_GET['id'] ?? null;
if (!$id || !ctype_digit((string)$id)) {
    die('Albarà no especificat');
}

// Carregar capçalera de l'albarà + client
$stmt = $pdo->prepare("
    SELECT a.id,
           a.num_albara,
           a.data_albara,
           a.adreca_entrega,
           a.observacions,
           c.nom  AS client_nom,
           c.nif  AS client_nif
    FROM albarans a
    JOIN clients c ON c.id = a.client_id
    WHERE a.id = :id
");
$stmt->execute(['id' => $id]);
$alb = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$alb) {
    die('Albarà no trobat');
}

// Carregar línies
$stmt = $pdo->prepare("
    SELECT num_linia, codi_article, descripcio, preu_unitari, quantitat
    FROM albara_linies
    WHERE albara_id = :id
    ORDER BY num_linia
");
$stmt->execute(['id' => $id]);
$linies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ruta del logo (ajusta si cal)
$logoPath = __DIR__ . '/img/logo.png';
$logoDataUri = '';
if (file_exists($logoPath)) {
    $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);
    $logoData = file_get_contents($logoPath);
    $logoBase64 = base64_encode($logoData);
    $logoDataUri = 'data:image/'.$logoType.';base64,'.$logoBase64;
}

// Generar HTML per al PDF
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 20mm;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
        }
        h1 {
            font-size: 18px;
            margin: 0 0 6px 0;
        }
        .small {
            font-size: 10px;
            color: #4b5563;
        }
        .header-top {
            display: table;
            width: 100%;
        }
        .header-left,
        .header-right {
            display: table-cell;
            vertical-align: top;
        }
        .header-right {
            text-align: right;
        }
        .logo {
            margin-bottom: 6px;
        }
        .logo img {
            max-height: 40px;
        }
        .block {
            margin-top: 8px;
        }
        .label {
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 4px 6px;
        }
        th {
            background: #e5e7eb;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .right {
            text-align: right;
        }
        .center {
            text-align: center;
        }
        .totals-row td {
            border-top: 2px solid #4b5563;
        }
        .observ {
            margin-top: 10px;
            font-size: 10px;
        }
    </style>
</head>
<body>
<?php
$data = $alb['data_albara']
    ? (new DateTime($alb['data_albara']))->format('d/m/Y')
    : '';
?>
<div class="header-top">
    <div class="header-left">
        <?php if (!empty($logoDataUri)): ?>
            <div class="logo">
                <img src="<?= $logoDataUri ?>" alt="Logo">
            </div>
        <?php endif; ?>
        <h1>Albarà <?= htmlspecialchars($alb['num_albara']) ?></h1>
        <div class="small">
            Data: <?= htmlspecialchars($data) ?><br>
        </div>
    </div>
    <div class="header-right small">
        <div><span class="label">Client:</span> <?= htmlspecialchars($alb['client_nom']) ?></div>
        <div><span class="label">NIF:</span> <?= htmlspecialchars($alb['client_nif']) ?></div>
    </div>
</div>

<div class="block small">
    <span class="label">Adreça d'entrega:</span>
    <?= htmlspecialchars($alb['adreca_entrega']) ?>
</div>

<table>
    <thead>
    <tr>
        <th style="width: 40px;">Línia</th>
        <th style="width: 70px;">Codi</th>
        <th>Descripció</th>
        <th class="right" style="width: 70px;">Quantitat</th>
        <th class="right" style="width: 70px;">Preu</th>
        <th class="right" style="width: 80px;">Import</th>
    </tr>
    </thead>
    <tbody>
    <?php
    $total = 0;
    if (empty($linies)):
    ?>
        <tr>
            <td colspan="6" class="center small">
                Sense línies.
            </td>
        </tr>
    <?php
    else:
        foreach ($linies as $l):
            $imp = (float)$l['preu_unitari'] * (float)$l['quantitat'];
            $total += $imp;
    ?>
        <tr>
            <td class="center"><?= htmlspecialchars($l['num_linia']) ?></td>
            <td><?= htmlspecialchars($l['codi_article']) ?></td>
            <td><?= htmlspecialchars($l['descripcio']) ?></td>
            <td class="right"><?= number_format((float)$l['quantitat'], 2, ',', '.') ?></td>
            <td class="right"><?= number_format((float)$l['preu_unitari'], 2, ',', '.') ?></td>
            <td class="right"><?= number_format($imp, 2, ',', '.') ?></td>
        </tr>
    <?php
        endforeach;
    endif;
    ?>
    <tr class="totals-row">
        <td colspan="5" class="right"><strong>Total</strong></td>
        <td class="right"><strong><?= number_format($total, 2, ',', '.') ?></strong></td>
    </tr>
    </tbody>
</table>

<?php if (!empty($alb['observacions'])): ?>
    <div class="observ">
        <span class="label">Observacions:</span><br>
        <?= nl2br(htmlspecialchars($alb['observacions'])) ?>
    </div>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

// Configurar Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); // per permetre data URI i fitxers locals

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Mostrar inline al navegador (no forçar descarrega)
$filename = 'albara_'.$alb['num_albara'].'.pdf';
$dompdf->stream($filename, ['Attachment' => 0]);

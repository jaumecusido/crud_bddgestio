<?php
require_once 'db.php';
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';
session_start();
// En aquest script, amaguem els avisos deprecats de llibreries de tercers
error_reporting(E_ALL & ~E_DEPRECATED);

use Dompdf\Dompdf;

$pdo = getPDO();

/**
 * Permís mínim: veure factures (mateix que al llistat)
 */
function user_can_veure_factures_clients(PDO $pdo): bool {
    return tePermisGestio($pdo, 1);
}

if (!user_can_veure_factures_clients($pdo)) {
    http_response_code(403);
    echo "No tens permisos per veure aquesta factura.";
    exit;
}

// -------------------------------
// 1) Obtenir id de factura
// -------------------------------
$facturaId = isset($_GET['factura_id']) ? (int)$_GET['factura_id'] : 0;
if ($facturaId <= 0) {
    echo "Factura no especificada.";
    exit;
}

// -------------------------------
// 2) Capçalera de factura + client
// -------------------------------
$sqlFactura = "
    SELECT f.id,
           f.num_factura,
           f.data_factura,
           COALESCE(f.import_total, 0) AS import_total,
           COALESCE(f.observacions, '') AS observacions,
           COALESCE(c.nom, '')         AS client_nom,
           COALESCE(c.nif, '')         AS client_nif,
           COALESCE(c.adreca, '')      AS client_adreca,
           COALESCE(c.email, '')       AS client_email,
           COALESCE(c.telefon, '')     AS client_telefon
    FROM factures f
    LEFT JOIN clients c ON c.id = f.client_id
    WHERE f.id = :id
    LIMIT 1
";
$stmt = $pdo->prepare($sqlFactura);
$stmt->execute([':id' => $facturaId]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) {
    echo "Factura no trobada.";
    exit;
}

// -------------------------------
// 3) Línies de factura (factura_linies + articles)
// -------------------------------
$sqlLinies = "
    SELECT
        fl.num_linia,
        COALESCE(fl.descripcio, a.descripcio) AS descripcio,
        fl.quantitat,
        fl.preu_unitari,
        (fl.quantitat * fl.preu_unitari)      AS import_linia
    FROM factura_linies fl
    LEFT JOIN articles a ON a.id = fl.article_id
    WHERE fl.factura_id = :id
    ORDER BY fl.num_linia ASC
";
$stmtLinies = $pdo->prepare($sqlLinies);
$stmtLinies->execute([':id' => $facturaId]);
$linies = $stmtLinies->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------
// 4) Carregar logo com a base64
// -------------------------------
$logoPath = __DIR__ . '/img/logo.png';   // adapta si cal
$logoDataUri = '';
if (file_exists($logoPath)) {
    $ext       = pathinfo($logoPath, PATHINFO_EXTENSION);
    $data      = file_get_contents($logoPath);
    $base64    = base64_encode($data);
    $mime      = 'image/' . strtolower($ext ?: 'png');
    $logoDataUri = 'data:' . $mime . ';base64,' . $base64;
}

// -------------------------------
// 4b) Llegir dades empresa de la taula empresa_params
// -------------------------------
$empresaNom        = 'Jaume Cusidó Morral';
$empresaNif        = '33909085R';
$empresaAdreca     = 'República, 31';
$empresaCpPobl     = '08202';
$empresaLocalitat  = '';   // NOVA variable
$empresaEmail      = 'jaume.cusido@gmail.com';
$empresaTelf       = '+34600015227';

try {
    // Llegeix UNA fila amb totes les dades de l'empresa (inclosa la localitat)
    $stmtEmp = $pdo->query("
        SELECT nom, nif, adreca, cp, email, telefon, localitat
        FROM empresa_params
        LIMIT 1
    ");
    $empresaRow = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    if ($empresaRow) {
        $empresaNom        = $empresaRow['nom']       ?? $empresaNom;
        $empresaNif        = $empresaRow['nif']       ?? $empresaNif;
        $empresaAdreca     = $empresaRow['adreca']    ?? $empresaAdreca;
        $empresaCpPobl     = $empresaRow['cp']        ?? $empresaCpPobl;
        $empresaLocalitat  = $empresaRow['localitat'] ?? $empresaLocalitat;
        $empresaEmail      = $empresaRow['email']     ?? $empresaEmail;
        $empresaTelf       = $empresaRow['telefon']   ?? $empresaTelf;
    }
} catch (Exception $e) {
    // Si falla la lectura, continuem amb els valors per defecte
}

// -------------------------------
// 5) Preparar Dompdf
// -------------------------------
$dompdf = new Dompdf();
$dompdf->setPaper('A4', 'portrait');

// Data formatada
$dataFactura = '';
if (!empty($factura['data_factura'])) {
    $dataObj = new DateTime($factura['data_factura']);
    $dataFactura = $dataObj->format('d/m/Y');
}

// -------------------------------
// 6) HTML del PDF
// -------------------------------
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
        h1 {
            font-size: 18px;
            margin: 0 0 6px 0;
        }
        .capcalera {
            width: 100%;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .empresa, .client {
            vertical-align: top;
            font-size: 11px;
        }
        .empresa strong {
            font-size: 13px;
        }
        .logo {
            margin-bottom: 4px;
        }
        .logo img {
            max-height: 40px;
        }
        .factura-meta {
            margin-top: 4px;
            font-size: 11px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        .linies th, .linies td {
            border: 1px solid #e5e7eb;
            padding: 4px 6px;
        }
        .linies th {
            background: #f3f4f6;
            text-align: left;
        }
        .text-right {
            text-align: right;
        }
        .totals {
            margin-top: 10px;
            width: 40%;
            float: right;
            border-collapse: collapse;
        }
        .totals th, .totals td {
            border: 1px solid #e5e7eb;
            padding: 4px 6px;
            font-size: 11px;
        }
        .totals th {
            background: #f9fafb;
            text-align: left;
        }
        .observacions {
            margin-top: 14px;
            font-size: 10px;
        }
    </style>
</head>
<body>
<div class="capcalera">
    <table width="100%">
        <tr>
            <td class="empresa" width="55%">
                <?php if (!empty($logoDataUri)): ?>
                    <div class="logo">
                        <img src="<?= $logoDataUri ?>" alt="Logo empresa">
                    </div>
                <?php endif; ?>
                <strong><?= htmlspecialchars($empresaNom) ?></strong><br>
                <?php if (!empty($empresaNif)): ?>
                    NIF: <?= htmlspecialchars($empresaNif) ?><br>
                <?php endif; ?>
                <?php if (!empty($empresaAdreca)): ?>
                    <?= htmlspecialchars($empresaAdreca) ?><br>
                <?php endif; ?>
                <?php if (!empty($empresaCpPobl) || !empty($empresaLocalitat)): ?>
                    <?= htmlspecialchars(trim($empresaCpPobl . ' ' . $empresaLocalitat)) ?><br>
                <?php endif; ?>
                <?php if (!empty($empresaEmail)): ?>
                    Email: <?= htmlspecialchars($empresaEmail) ?><br>
                <?php endif; ?>
                <?php if (!empty($empresaTelf)): ?>
                    Tel: <?= htmlspecialchars($empresaTelf) ?><br>
                <?php endif; ?>
            </td>
            <td class="client" width="45%">
                <strong>Client</strong><br>
                <?= htmlspecialchars($factura['client_nom']) ?><br>
                NIF: <?= htmlspecialchars($factura['client_nif']) ?><br>
                <?= htmlspecialchars($factura['client_adreca']) ?><br>
                <?php if (!empty($factura['client_email'])): ?>
                    Email: <?= htmlspecialchars($factura['client_email']) ?><br>
                <?php endif; ?>
                <?php if (!empty($factura['client_telefon'])): ?>
                    Tel: <?= htmlspecialchars($factura['client_telefon']) ?><br>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="factura-meta">
        <h1>Factura <?= htmlspecialchars($factura['num_factura']) ?></h1>
        Data: <?= htmlspecialchars($dataFactura) ?>
    </div>
</div>

<table class="linies">
    <thead>
    <tr>
        <th style="width:8%;">Línia</th>
        <th style="width:47%;">Concepte</th>
        <th style="width:15%;" class="text-right">Quantitat</th>
        <th style="width:15%;" class="text-right">Preu</th>
        <th style="width:15%;" class="text-right">Import</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($linies)): ?>
        <tr>
            <td colspan="5">No hi ha línies per aquesta factura.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($linies as $linia): ?>
            <tr>
                <td><?= (int)$linia['num_linia'] ?></td>
                <td><?= htmlspecialchars($linia['descripcio']) ?></td>
                <td class="text-right">
                    <?= htmlspecialchars(number_format((float)$linia['quantitat'], 3, ',', '.')) ?>
                </td>
                <td class="text-right">
                    <?= htmlspecialchars(number_format((float)$linia['preu_unitari'], 2, ',', '.')) ?> €
                </td>
                <td class="text-right">
                    <?= htmlspecialchars(number_format((float)$linia['import_linia'], 2, ',', '.')) ?> €
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<table class="totals">
    <tr>
        <th>Total factura</th>
        <td class="text-right">
            <?= htmlspecialchars(number_format((float)$factura['import_total'], 2, ',', '.')) ?> €
        </td>
    </tr>
</table>

<?php if (!empty(trim($factura['observacions']))): ?>
    <div class="observacions">
        <strong>Observacions:</strong><br>
        <?= nl2br(htmlspecialchars($factura['observacions'])) ?>
    </div>
<?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

// -------------------------------
// 7) Generar i enviar el PDF
// -------------------------------
$dompdf->loadHtml($html);
$dompdf->render();

$nomFitxer = 'factura_' . preg_replace('/[^0-9A-Za-z_\-]/', '_', $factura['num_factura'] ?? $facturaId) . '.pdf';

// Mostra el PDF al navegador (sense forçar descarrega)
$dompdf->stream($nomFitxer, ['Attachment' => false]);

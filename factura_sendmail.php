<?php
// Opcional: amagar avisos "deprecated" de llibreries de tercers
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';
require_once 'db.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = getPDO();

// --- si és la primera càrrega, mostrem spinner i recarreguem amb ?run=1 ---
if (!isset($_GET['run'])) {
    $id = $_GET['id'] ?? null;
    if (!$id || !ctype_digit((string)$id)) {
        die('Factura no especificada');
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Enviant factura...</title>
        <style>
            :root {
                --bg: #020617;
                --text-main: #e5e7eb;
                --text-soft: #9ca3af;
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
                background:
                    radial-gradient(circle at top, rgba(56,189,248,0.25), transparent 55%),
                    radial-gradient(circle at bottom, rgba(34,197,94,0.2), transparent 55%),
                    #020617;
                color: var(--text-main);
            }
            .shell {
                text-align: center;
            }
            .spinner {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                border: 6px solid rgba(148,163,184,0.3);
                border-top-color: #38bdf8;
                animation: spin 1s linear infinite;
                margin: 0 auto 16px auto;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            h1 {
                font-size: 1.1rem;
                margin-bottom: 4px;
            }
            p {
                font-size: 0.9rem;
                color: var(--text-soft);
            }
        </style>
        <script>
            window.addEventListener('load', function () {
                const url = new URL(window.location.href);
                url.searchParams.set('run', '1');
                window.location.href = url.toString();
            });
        </script>
    </head>
    <body>
    <div class="shell">
        <div class="spinner"></div>
        <h1>Enviant factura per correu...</h1>
        <p>Espera uns segons mentre es genera el PDF i s'envia el missatge.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// --- a partir d’aquí, run=1: fem tota la feina i mostrem resultat ---

// Validar id de factura
$id = $_GET['id'] ?? null;
if (!$id || !ctype_digit((string)$id)) {
    die('Factura no especificada');
}

// Carregar capçalera de la factura + client
$stmt = $pdo->prepare("
    SELECT f.id,
           f.num_factura,
           f.data_factura,
           f.import_total,
           f.observacions,
           f.email      AS factura_email,
           c.nom        AS client_nom,
           c.nif        AS client_nif,
           c.email      AS client_email
    FROM factures f
    JOIN clients c ON c.id = f.client_id
    WHERE f.id = :id
");
$stmt->execute(['id' => $id]);
$fac = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fac) {
    die('Factura no trobada');
}

// Email destinatari: primer el de la factura, sinó el del client
$destinatariEmail = null;
if (!empty($fac['factura_email'])) {
    $destinatariEmail = $fac['factura_email'];
} elseif (!empty($fac['client_email'])) {
    $destinatariEmail = $fac['client_email'];
}
if (empty($destinatariEmail)) {
    die('No hi ha cap email informat ni a la factura ni al client.');
}

// Carregar línies de factura
$stmt = $pdo->prepare("
    SELECT fl.num_linia,
           fl.codi_article,
           COALESCE(fl.descripcio, a.descripcio) AS descripcio,
           fl.preu_unitari,
           fl.quantitat
    FROM factura_linies fl
    LEFT JOIN articles a ON a.id = fl.article_id
    WHERE fl.factura_id = :id
    ORDER BY fl.num_linia
");
$stmt->execute(['id' => $id]);
$linies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Carregar logo i preparar data URI
$logoPath = __DIR__ . '/img/logo.png';
$logoDataUri = '';
if (file_exists($logoPath)) {
    $logoType   = pathinfo($logoPath, PATHINFO_EXTENSION);
    $logoData   = file_get_contents($logoPath);
    $logoBase64 = base64_encode($logoData);
    $logoDataUri = 'data:image/'.$logoType.';base64,'.$logoBase64;
}

// Llegir dades empresa de empresa_params
$empresaNom        = 'Jaume Cusidó Morral';
$empresaNif        = '33909085R';
$empresaAdreca     = 'República, 31';
$empresaCp         = '08202';
$empresaLocalitat  = '';
$empresaEmail      = 'jaume.cusido@gmail.com';
$empresaTelf       = '+34600015227';

try {
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
        $empresaCp         = $empresaRow['cp']        ?? $empresaCp;
        $empresaLocalitat  = $empresaRow['localitat'] ?? $empresaLocalitat;
        $empresaEmail      = $empresaRow['email']     ?? $empresaEmail;
        $empresaTelf       = $empresaRow['telefon']   ?? $empresaTelf;
    }
} catch (Exception $e) {
    // continuar amb valors per defecte
}

// 1) Generar PDF en memòria amb Dompdf
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 20mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
        }
        h1 { font-size: 18px; margin: 0 0 6px 0; }
        .small { font-size: 10px; color: #4b5563; }
        .header-top { display: table; width: 100%; }
        .header-left, .header-right { display: table-cell; vertical-align: top; }
        .header-right { text-align: right; }
        .logo { margin-bottom: 6px; }
        .logo img { max-height: 40px; }
        .label { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 4px 6px; }
        th {
            background: #e5e7eb;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .right { text-align: right; }
        .center { text-align: center; }
        .totals-row td { border-top: 2px solid #4b5563; }
        .observ { margin-top: 10px; font-size: 10px; }
    </style>
</head>
<body>
<?php
$data = $fac['data_factura']
    ? (new DateTime($fac['data_factura']))->format('d/m/Y')
    : '';
$totalFactura = (float)($fac['import_total'] ?? 0);
?>
<div class="header-top">
    <div class="header-left small">
        <?php if (!empty($logoDataUri)): ?>
            <div class="logo">
                <img src="<?= $logoDataUri ?>" alt="Logo">
            </div>
        <?php endif; ?>
        <div><span class="label"><?= htmlspecialchars($empresaNom) ?></span></div>
        <?php if (!empty($empresaNif)): ?>
            <div>NIF: <?= htmlspecialchars($empresaNif) ?></div>
        <?php endif; ?>
        <?php if (!empty($empresaAdreca)): ?>
            <div><?= htmlspecialchars($empresaAdreca) ?></div>
        <?php endif; ?>
        <?php if (!empty($empresaCp) || !empty($empresaLocalitat)): ?>
            <div><?= htmlspecialchars(trim($empresaCp . ' ' . $empresaLocalitat)) ?></div>
        <?php endif; ?>
        <?php if (!empty($empresaEmail)): ?>
            <div>Email: <?= htmlspecialchars($empresaEmail) ?></div>
        <?php endif; ?>
        <?php if (!empty($empresaTelf)): ?>
            <div>Tel: <?= htmlspecialchars($empresaTelf) ?></div>
        <?php endif; ?>
        <h1>Factura <?= htmlspecialchars($fac['num_factura']) ?></h1>
        <div class="small">
            Data: <?= htmlspecialchars($data) ?><br>
        </div>
    </div>
    <div class="header-right small">
        <div><span class="label">Client:</span> <?= htmlspecialchars($fac['client_nom']) ?></div>
        <div><span class="label">NIF:</span> <?= htmlspecialchars($fac['client_nif']) ?></div>
        <div><span class="label">Email client:</span> <?= htmlspecialchars($fac['client_email']) ?></div>
        <div><span class="label">Email factura:</span> <?= htmlspecialchars($fac['factura_email']) ?></div>
    </div>
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
        <td colspan="5" class="right"><strong>Total línies</strong></td>
        <td class="right"><strong><?= number_format($total, 2, ',', '.') ?></strong></td>
    </tr>
    <tr>
        <td colspan="5" class="right small"><strong>Total factura (camp import_total)</strong></td>
        <td class="right small"><strong><?= number_format($totalFactura, 2, ',', '.') ?></strong></td>
    </tr>
    </tbody>
</table>

<?php if (!empty($fac['observacions'])): ?>
    <div class="observ">
        <span class="label">Observacions:</span><br>
        <?= nl2br(htmlspecialchars($fac['observacions'])) ?>
    </div>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

// Dompdf: generar PDF en memòria
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); 

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfOutput = $dompdf->output();
$filename  = 'factura_'.$fac['num_factura'].'.pdf';

// 2) Enviar mail amb PHPMailer
$mail = new PHPMailer(true);

try {
    // CONFIGURACIÓ SMTP (ADAPTA-HO)
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'jaume.cusido@gmail.com';
    $mail->Password   = 'ieax dcof ffbf nquc';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587; 

    $mail->setFrom('jaume.cusido@gmail.com', 'BddGestio');
    $mail->addAddress($destinatariEmail, $fac['client_nom']);

    $mail->addStringAttachment($pdfOutput, $filename, 'base64', 'application/pdf'); 

    $mail->isHTML(true);
    $mail->Subject = 'Factura '.$fac['num_factura'];
    $mail->Body    = 'Bon dia,<br><br>Adjunt tens la factura <strong>'.
                     htmlspecialchars($fac['num_factura']).'</strong>.'.
                     '<br><br>Salutacions,<br>'.htmlspecialchars($empresaNom);
    $mail->AltBody = 'Bon dia,'."\n\n".
                     'Adjunt tens la factura '.$fac['num_factura'].".\n\n".
                     'Salutacions, '.$empresaNom;

    $mail->send();

    // --- Pantalla d’èxit ---
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Factura enviada</title>
        <style>
            :root {
                --bg: #020617;
                --text-main: #e5e7eb;
                --text-soft: #9ca3af;
                --accent: #22c55e;
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
                background:
                    radial-gradient(circle at top, rgba(74,222,128,0.28), transparent 55%),
                    radial-gradient(circle at bottom, rgba(56,189,248,0.18), transparent 55%),
                    #020617;
                color: var(--text-main);
            }
            .card {
                position: relative;
                background: radial-gradient(circle at top left, rgba(34,197,94,0.20), rgba(15,23,42,0.98));
                border-radius: 22px;
                padding: 24px 26px 22px;
                max-width: 480px;
                width: 100%;
                box-shadow:
                    0 26px 70px rgba(15, 23, 42, 0.95),
                    0 0 0 1px rgba(148, 163, 184, 0.45);
                text-align: left;
                overflow: hidden;
            }
            .card-inner { position: relative; z-index: 1; }
            .icon-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 10px;
            }
            .icon-circle {
                width: 52px;
                height: 52px;
                border-radius: 999px;
                display: flex;
                align-items: center;
                justify-content: center;
                background-color: #14532d;
                color: var(--accent);
                font-size: 26px;
                box-shadow: 0 14px 28px rgba(22,163,74,0.8);
            }
            .tag {
                padding: 2px 10px;
                border-radius: 999px;
                border: 1px solid rgba(148,163,184,0.9);
                font-size: 0.7rem;
                text-transform: uppercase;
                letter-spacing: 0.16em;
                color: #e5e7eb;
            }
            h1 { font-size: 1.25rem; margin-bottom: 4px; }
            .subtitle {
                font-size: 0.85rem;
                color: var(--text-soft);
                margin-bottom: 14px;
            }
            .grid {
                display: grid;
                grid-template-columns: 1.2fr 1.2fr;
                gap: 10px 18px;
                margin-top: 8px;
            }
            .label {
                color: #9ca3af;
                text-transform: uppercase;
                letter-spacing: 0.12em;
                font-size: 0.7rem;
                display: block;
            }
            .value {
                font-size: 0.95rem;
            }
            .value strong { font-size: 1rem; }
            .footer {
                margin-top: 18px;
                display: flex;
                justify-content: flex-end;
            }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 8px 18px;
                border-radius: 999px;
                border: 1px solid #16a34a;
                background-color: #22c55e;
                color: #ecfdf5;
                font-size: 0.9rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.10em;
                cursor: pointer;
                text-decoration: none;
                box-shadow: 0 10px 24px rgba(22, 163, 74, 0.85);
            }
            .btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 14px 30px rgba(22, 163, 74, 1);
                background-color: #16a34a;
            }
        </style>
    </head>
    <body>
    <div class="card">
        <div class="card-inner">
            <div class="icon-row">
                <div class="icon-circle">✉️</div>
                <div class="tag">Factura enviada</div>
            </div>
            <h1>Correu enviat correctament</h1>
            <div class="subtitle">
                La factura s'ha enviat com a adjunt PDF al client.
            </div>
            <div class="grid">
                <div>
                    <span class="label">Factura</span>
                    <span class="value"><strong>#<?= htmlspecialchars($fac['num_factura']) ?></strong></span>
                </div>
                <div>
                    <span class="label">Import total</span>
                    <span class="value"><?= number_format($totalFactura, 2, ',', '.') ?> €</span>
                </div>
                <div>
                    <span class="label">Client</span>
                    <span class="value"><?= htmlspecialchars($fac['client_nom']) ?></span>
                </div>
                <div>
                    <span class="label">Email destinatari</span>
                    <span class="value"><?= htmlspecialchars($destinatariEmail) ?></span>
                </div>
            </div>
            <div class="footer">
                <button class="btn" onclick="window.location.href='factures_clients_list.php';">
                    Tornar al llistat
                </button>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;

} catch (Exception $e) {
    // Pantalla d'error d'enviament
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Error en enviar correu</title>
        <style>
            :root {
                --bg: #fee2e2;
                --text-main: #111827;
                --text-soft: #6b7280;
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
                background:
                    radial-gradient(circle at top, rgba(248,113,113,0.40), transparent 55%),
                    #fee2e2;
                color: var(--text-main);
            }
            .card {
                background: #ffffff;
                border-radius: 18px;
                padding: 22px 24px;
                max-width: 520px;
                width: 100%;
                box-shadow:
                    0 20px 45px rgba(153, 27, 27, 0.35),
                    0 0 0 1px rgba(248, 113, 113, 0.7);
            }
            h1 {
                font-size: 1.15rem;
                margin-bottom: 6px;
                color: #b91c1c;
            }
            p {
                font-size: 0.9rem;
                margin-bottom: 6px;
            }
            .row { margin-top: 6px; }
            .row .label { font-weight: 600; }
            .error-msg {
                margin-top: 10px;
                padding: 8px 10px;
                border-radius: 10px;
                background: #fee2e2;
                border: 1px solid #f97373;
                font-size: 0.8rem;
                color: #7f1d1d;
            }
            .footer {
                margin-top: 14px;
                display: flex;
                justify-content: flex-end;
            }
            .btn {
                padding: 7px 16px;
                border-radius: 999px;
                border: 1px solid #b91c1c;
                background-color: #fecaca;
                color: #7f1d1d;
                font-size: 0.85rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                cursor: pointer;
            }
            .btn:hover {
                background-color: #fca5a5;
            }
        </style>
    </head>
    <body>
    <div class="card">
        <h1>No s'ha pogut enviar el correu</h1>
        <p>
            Hi ha hagut un problema en enviar la factura
            <strong>#<?= htmlspecialchars($fac['num_factura']) ?></strong>
            al client <strong><?= htmlspecialchars($fac['client_nom']) ?></strong>.
        </p>
        <div class="row">
            <span class="label">Email destinatari:</span>
            <span><?= htmlspecialchars($destinatariEmail ?? $fac['client_email'] ?? '') ?></span>
        </div>
        <div class="error-msg">
            <div class="label" style="font-weight:600; margin-bottom:2px;">Detall de l'error del servidor SMTP:</div>
            <div class="small"><?= htmlspecialchars($mail->ErrorInfo) ?></div>
        </div>
        <div class="footer">
            <button class="btn" onclick="window.location.href='factures_clients_list.php';">
                Tornar al llistat
            </button>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

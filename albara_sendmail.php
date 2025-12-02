
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

// Validar id d'albarà
$id = $_GET['id'] ?? null;
if (!$id || !ctype_digit((string)$id)) {
    die('Albarà no especificat');
}

// Carregar capçalera de l'albarà + client (incloem email)
$stmt = $pdo->prepare("
    SELECT a.id,
           a.num_albara,
           a.data_albara,
           a.adreca_entrega,
           a.observacions,
           c.nom   AS client_nom,
           c.nif   AS client_nif,
           c.email AS client_email
    FROM albarans a
    JOIN clients c ON c.id = a.client_id
    WHERE a.id = :id
");
$stmt->execute(['id' => $id]);
$alb = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$alb) {
    die('Albarà no trobat');
}

if (empty($alb['client_email'])) {
    die('El client no té email informat a la fitxa.');
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

// Carregar logo i preparar data URI
$logoPath = __DIR__ . '/img/logo.png';
$logoDataUri = '';
if (file_exists($logoPath)) {
    $logoType   = pathinfo($logoPath, PATHINFO_EXTENSION);
    $logoData   = file_get_contents($logoPath);
    $logoBase64 = base64_encode($logoData);
    $logoDataUri = 'data:image/'.$logoType.';base64,'.$logoBase64;
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
        .block { margin-top: 8px; }
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
        <div><span class="label">Email:</span> <?= htmlspecialchars($alb['client_email']) ?></div>
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

// Dompdf: generar PDF en memòria
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); // permet data URI i recursos locals

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfOutput = $dompdf->output();
$filename = 'albara_'.$alb['num_albara'].'.pdf';

// 2) Enviar mail amb PHPMailer
$mail = new PHPMailer(true);

try {
    // CONFIGURACIÓ SMTP (ADAPTA-HO AL TEU CAS)
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'jaume.cusido@gmail.com';
    $mail->Password   = 'ieax dcof ffbf nquc';   // app password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Remitent i destinatari
    $mail->setFrom('jaume.cusido@gmail.com', 'BddGestio');
    $mail->addAddress($alb['client_email'], htmlspecialchars($alb['client_nom']));

    // Adjuntar PDF
    $mail->addStringAttachment($pdfOutput, $filename, 'base64', 'application/pdf');

    // Contingut
    $mail->isHTML(true);
    $mail->Subject = 'Albarà '.$alb['num_albara'];
    $mail->Body    = 'Bon dia,<br><br>Adjunt tens l\'albarà <strong>'.
                     htmlspecialchars($alb['num_albara']).'</strong>.'.
                     '<br><br>Salutacions,<br>BddGestio';
    $mail->AltBody = 'Bon dia,'."\n\n".
                     'Adjunt tens l\'albarà '.$alb['num_albara'].".\n\n".
                     'Salutacions, BddGestio';

    $mail->send();

    // -------- Pantalla bonica de confirmació --------
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Correu enviat</title>
        <style>
            :root {
                --bg: #f5f7fb;
                --card-bg: #ffffff;
                --text-main: #111827;
                --text-soft: #64748b;
                --accent: #22c55e;
                --accent-soft: #dcfce7;
                --btn-bg: #22c55e;
                --btn-border: #16a34a;
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
                background: radial-gradient(circle at top, #bbf7d0 0, var(--bg) 45%, #fefce8 100%);
                color: var(--text-main);
            }
            .card {
                background: var(--card-bg);
                border-radius: 18px;
                padding: 24px 28px;
                max-width: 420px;
                width: 100%;
                box-shadow:
                    0 18px 45px rgba(15, 23, 42, 0.18),
                    0 0 0 1px rgba(148, 163, 184, 0.3);
                text-align: center;
            }
            .icon-circle {
                width: 52px;
                height: 52px;
                border-radius: 999px;
                margin: 0 auto 10px auto;
                display: flex;
                align-items: center;
                justify-content: center;
                background-color: var(--accent-soft);
                color: var(--accent);
                font-size: 26px;
            }
            h1 {
                font-size: 1.2rem;
                margin-bottom: 6px;
            }
            p {
                font-size: 0.9rem;
                color: var(--text-soft);
                margin-bottom: 4px;
            }
            .highlight {
                font-weight: 600;
                color: var(--text-main);
            }
            .footer {
                margin-top: 16px;
            }
            .btn {
                margin-top: 14px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 8px 18px;
                border-radius: 999px;
                border: 1px solid var(--btn-border);
                background-color: var(--btn-bg);
                color: #ecfdf5;
                font-size: 0.9rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                cursor: pointer;
                text-decoration: none;
                box-shadow: 0 10px 22px rgba(22, 163, 74, 0.45);
                transition:
                    transform 0.12s ease,
                    box-shadow 0.12s ease,
                    background-color 0.12s ease,
                    border-color 0.12s ease;
            }
            .btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 14px 26px rgba(22, 163, 74, 0.55);
                background-color: #16a34a;
            }
        </style>
    </head>
    <body>
    <div class="card">
        <div class="icon-circle">✉️</div>
        <h1>Albarà enviat correctament</h1>
        <p>
            S'ha enviat l'albarà
            <span class="highlight">#<?= htmlspecialchars($alb['num_albara']) ?></span>
            a
            <span class="highlight"><?= htmlspecialchars($alb['client_nom']) ?></span>.
        </p>
        <p class="small">
            Correu destinat a: <?= htmlspecialchars($alb['client_email']) ?>
        </p>
        <div class="footer">
            <button class="btn" onclick="window.location.href='albarans_list.php';">
                Acceptar
            </button>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;

} catch (Exception $e) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Error en enviar correu</title>
        <style>
            body {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #fef2f2;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                color: #111827;
            }
            .card {
                background: #ffffff;
                border-radius: 18px;
                padding: 20px 24px;
                max-width: 420px;
                width: 100%;
                box-shadow:
                    0 18px 45px rgba(153, 27, 27, 0.25),
                    0 0 0 1px rgba(248, 113, 113, 0.7);
                text-align: center;
            }
            h1 {
                font-size: 1.1rem;
                margin-bottom: 8px;
                color: #b91c1c;
            }
            p {
                font-size: 0.9rem;
                margin-bottom: 6px;
            }
            .btn {
                margin-top: 12px;
                padding: 8px 18px;
                border-radius: 999px;
                border: 1px solid #b91c1c;
                background-color: #fee2e2;
                color: #7f1d1d;
                font-size: 0.9rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                cursor: pointer;
            }
            .btn:hover {
                background-color: #fecaca;
            }
        </style>
    </head>
    <body>
    <div class="card">
        <h1>No s'ha pogut enviar el correu</h1>
        <p>Hi ha hagut un problema en enviar l'albarà #<?= htmlspecialchars($alb['num_albara']) ?>.</p>
        <p class="small"><?= htmlspecialchars($mail->ErrorInfo) ?></p>
        <button class="btn" onclick="window.location.href='albarans_form.php?id=<?= htmlspecialchars($id) ?>';">
            Tornar a l'albarà
        </button>
    </div>
    </body>
    </html>
    <?php
    exit;
}

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

// ---------- FASE 0: pantalla de "enviant" amb spinner ----------
if (!isset($_GET['run'])) {
    $id = $_GET['id'] ?? null;
    if (!$id || !ctype_digit((string)$id)) {
        die('Albarà no especificat');
    }
    ?>
    <!DOCTYPE html>
    <html lang="ca">
    <head>
        <meta charset="UTF-8">
        <title>Enviant albarà...</title>
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
        <h1>Enviant albarà per correu...</h1>
        <p>Espera uns segons mentre es genera el PDF i s'envia el missatge.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------- FASE 1: ja amb run=1, fem tota la feina ----------

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
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); // permet data URI [web:139]

$dompdf = new Dompdf($options);
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

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfOutput = $dompdf->output();
$filename = 'albara_'.$alb['num_albara'].'.pdf';

// 2) Enviar mail amb PHPMailer
$mail = new PHPMailer(true);

try {
    // CONFIGURACIÓ SMTP
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'jaume.cusido@gmail.com';
    $mail->Password   = 'ieax dcof ffbf nquc';   // app password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;                    // [web:170]

    // Remitent i destinatari
    $mail->setFrom('jaume.cusido@gmail.com', 'BddGestio');
    $mail->addAddress($alb['client_email'], $alb['client_nom']);

    // Contingut del correu
    $subject = 'Albarà '.$alb['num_albara'];
    $bodyHtml = 'Bon dia,<br><br>Adjunt tens l\'albarà <strong>'.
                htmlspecialchars($alb['num_albara']).'</strong>.'.
                '<br><br>Salutacions,<br>BddGestio';
    $bodyText = "Bon dia,\n\nAdjunt tens l'albarà ".$alb['num_albara'].".\n\nSalutacions, BddGestio";

    // Adjuntar PDF
    $mail->addStringAttachment($pdfOutput, $filename, 'base64', 'application/pdf'); // [web:162]

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $bodyHtml;
    $mail->AltBody = $bodyText;

    $mail->send();

    // -------- Pantalla bonica de confirmació + vista del mail --------
    ?>
    <!DOCTYPE html>
    <html lang="ca">
    <head>
        <meta charset="UTF-8">
        <title>Albarà enviat</title>
        <style>
            :root {
                --bg: #020617;
                --text-main: #e5e7eb;
                --text-soft: #9ca3af;
                --accent: #22c55e;
            }
            *{
                box-sizing:border-box;margin:0;padding:0;
                font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            }
            body{
                min-height:100vh;display:flex;align-items:center;justify-content:center;
                background:
                    radial-gradient(circle at top,rgba(74,222,128,0.28),transparent 55%),
                    radial-gradient(circle at bottom,rgba(56,189,248,0.18),transparent 55%),
                    #020617;
                color:var(--text-main);
            }
            .card{
                position:relative;background:radial-gradient(circle at top left,rgba(34,197,94,0.20),rgba(15,23,42,0.98));
                border-radius:22px;padding:24px 26px 18px;max-width:620px;width:100%;
                box-shadow:0 26px 70px rgba(15,23,42,0.95),0 0 0 1px rgba(148,163,184,0.45);
                overflow:hidden;
            }
            .card-inner{position:relative;z-index:1;}
            .icon-row{
                display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;
            }
            .icon-circle{
                width:52px;height:52px;border-radius:999px;
                display:flex;align-items:center;justify-content:center;
                background-color:#14532d;color:var(--accent);font-size:26px;
                box-shadow:0 14px 28px rgba(22,163,74,0.8);
            }
            .tag{
                padding:2px 10px;border-radius:999px;border:1px solid rgba(148,163,184,0.9);
                font-size:.7rem;text-transform:uppercase;letter-spacing:.16em;color:#e5e7eb;
            }
            h1{font-size:1.25rem;margin-bottom:4px;}
            .subtitle{font-size:.85rem;color:var(--text-soft);margin-bottom:14px;}
            .grid{
                display:grid;grid-template-columns:1.2fr 1.2fr;
                gap:8px 18px;margin-bottom:10px;
            }
            .label{
                color:#9ca3af;text-transform:uppercase;letter-spacing:.12em;
                font-size:.7rem;display:block;
            }
            .value{font-size:.95rem;}
            .value strong{font-size:1rem;}
            .mail-preview{
                margin-top:8px;padding:10px 12px;border-radius:12px;
                background-color:rgba(15,23,42,0.9);
                border:1px solid rgba(148,163,184,0.7);
                font-size:.85rem;
            }
            .mail-header-row{
                font-size:.8rem;color:var(--text-soft);margin-bottom:4px;
            }
            .mail-header-row span.label{font-weight:600;color:#cbd5f5;}
            .mail-body{
                margin-top:6px;padding-top:6px;border-top:1px dashed rgba(75,85,99,0.9);
                color:#e5e7eb;font-size:.85rem;
            }
            .footer{
                margin-top:14px;display:flex;justify-content:flex-end;
            }
            .btn{
                display:inline-flex;align-items:center;justify-content:center;
                padding:8px 18px;border-radius:999px;border:1px solid #16a34a;
                background-color:#22c55e;color:#ecfdf5;font-size:.9rem;
                font-weight:600;text-transform:uppercase;letter-spacing:.10em;
                cursor:pointer;text-decoration:none;
                box-shadow:0 10px 24px rgba(22,163,74,0.85);
                transition:transform .12s,box-shadow .12s,background-color .12s,border-color .12s;
            }
            .btn:hover{
                transform:translateY(-1px);
                box-shadow:0 14px 30px rgba(22,163,74,1);
                background-color:#16a34a;
            }
        </style>
    </head>
    <body>
    <div class="card">
        <div class="card-inner">
            <div class="icon-row">
                <div class="icon-circle">✉️</div>
                <div class="tag">Albarà enviat</div>
            </div>
            <h1>Correu enviat correctament</h1>
            <div class="subtitle">
                L'albarà s'ha enviat com a adjunt PDF al client.
            </div>

            <div class="grid">
                <div>
                    <span class="label">Albarà</span>
                    <span class="value"><strong>#<?= htmlspecialchars($alb['num_albara']) ?></strong></span>
                </div>
                <div>
                    <span class="label">Total línies</span>
                    <span class="value"><?= number_format($total, 2, ',', '.') ?> €</span>
                </div>
                <div>
                    <span class="label">Client</span>
                    <span class="value"><?= htmlspecialchars($alb['client_nom']) ?></span>
                </div>
                <div>
                    <span class="label">Email destinatari</span>
                    <span class="value"><?= htmlspecialchars($alb['client_email']) ?></span>
                </div>
            </div>

            <!-- Vista del mail enviat -->
            <div class="mail-preview">
                <div class="mail-header-row">
                    <span class="label">Assumpte:</span>
                    <span class="value"><?= htmlspecialchars($subject) ?></span>
                </div>
                <div class="mail-header-row">
                    <span class="label">A:</span>
                    <span class="value"><?= htmlspecialchars($alb['client_email']) ?> (<?= htmlspecialchars($alb['client_nom']) ?>)</span>
                </div>
                <div class="mail-body">
                    <?= $bodyHtml ?>
                </div>
            </div>

            <div class="footer">
                <button class="btn" onclick="window.location.href='albarans_list.php';">
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
    // -------- Pantalla d'error --------
    ?>
    <!DOCTYPE html>
    <html lang="ca">
    <head>
        <meta charset="UTF-8">
        <title>Error en enviar correu</title>
        <style>
            :root {
                --bg: #fee2e2;
                --text-main: #111827;
                --text-soft: #6b7280;
            }
            *{
                box-sizing:border-box;margin:0;padding:0;
                font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            }
            body{
                min-height:100vh;display:flex;align-items:center;justify-content:center;
                background:
                    radial-gradient(circle at top,rgba(248,113,113,0.40),transparent 55%),
                    #fee2e2;
                color:var(--text-main);
            }
            .card{
                background:#ffffff;border-radius:18px;padding:22px 24px;
                max-width:520px;width:100%;
                box-shadow:0 20px 45px rgba(153,27,27,0.35),
                           0 0 0 1px rgba(248,113,113,0.7);
            }
            h1{font-size:1.15rem;margin-bottom:6px;color:#b91c1c;}
            p{font-size:.9rem;margin-bottom:6px;}
            .row{margin-top:6px;}
            .row .label{font-weight:600;}
            .error-msg{
                margin-top:10px;padding:8px 10px;border-radius:10px;
                background:#fee2e2;border:1px solid #f97373;
                font-size:.8rem;color:#7f1d1d;
            }
            .footer{margin-top:14px;display:flex;justify-content:flex-end;}
            .btn{
                padding:7px 16px;border-radius:999px;border:1px solid #b91c1c;
                background-color:#fecaca;color:#7f1d1d;font-size:.85rem;
                font-weight:600;text-transform:uppercase;letter-spacing:.08em;
                cursor:pointer;
            }
            .btn:hover{background-color:#fca5a5;}
        </style>
    </head>
    <body>
    <div class="card">
        <h1>No s'ha pogut enviar el correu</h1>
        <p>
            Hi ha hagut un problema en enviar l'albarà
            <strong>#<?= htmlspecialchars($alb['num_albara']) ?></strong>
            al client <strong><?= htmlspecialchars($alb['client_nom']) ?></strong>.
        </p>
        <div class="row">
            <span class="label">Email destinatari:</span>
            <span><?= htmlspecialchars($alb['client_email']) ?></span>
        </div>
        <div class="error-msg">
            <div class="label" style="font-weight:600; margin-bottom:2px;">Detall de l'error del servidor SMTP:</div>
            <div class="small"><?= htmlspecialchars($mail->ErrorInfo) ?></div>
        </div>
        <div class="footer">
            <button class="btn" onclick="window.location.href='albarans_form.php?id=<?= htmlspecialchars($id) ?>';">
                Tornar a l'albarà
            </button>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

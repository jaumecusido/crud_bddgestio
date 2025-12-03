<?php
session_start();

require_once 'db.php';
require_once 'config.php';
$pdo = getPDO();

// -----------------------------------------------------------------------------
// Config B2Brouter (si vols, mou això a config.php)
// -----------------------------------------------------------------------------
const B2B_API_URL   = 'https://api.b2brouter.net/invoices';   // EXEMPLE, substitueix per l’endpoint real
const B2B_API_TOKEN = 'POSA_AQUI_EL_TEUS_TOKEN';              // EXEMPLE, substitueix pel teu token

// ---------- PERMISOS ----------
$permisGestio = 0;
if (!empty($_SESSION['usuari_id'])) {
    $stmtUser = $pdo->prepare("SELECT permis_gestio FROM usuaris WHERE id = :id AND actiu = TRUE");
    $stmtUser->execute(['id' => $_SESSION['usuari_id']]);
    $rowUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($rowUser) {
        $permisGestio = (int)$rowUser['permis_gestio'];
    }
}

$tePermisLectura    = $permisGestio >= 1;
$tePermisEscriptura = $permisGestio >= 2;

// Si no té ni lectura, fora
if (!$tePermisLectura) {
    http_response_code(403);
    echo "<h2>Accés no permès al mòdul de factures.</h2>";
    exit;
}

// ---------- CÀRREGA INICIAL ----------
$id = $_GET['id'] ?? ($_POST['id'] ?? null);

// 1) Carregar llista de clients
$clientsStmt = $pdo->query("SELECT id, nom FROM clients ORDER BY nom");
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Missatge flash senzill via GET
$flash = $_GET['msg'] ?? '';
$flashIsError = (isset($_GET['error']) && $_GET['error'] === '1');

// 2) Processar POST (capçalera + línies + enviament B2Brouter) només si té escriptura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tePermisEscriptura) {

    $id             = $_POST['id'] ?? null;
    $client_id      = $_POST['client_id'] ?? null;
    $data_factura   = $_POST['data_factura'] ?? date('Y-m-d');
    $observacions   = $_POST['observacions'] ?? '';

    // permetre modificar nif i email a la pròpia factura
    $factura_nif    = trim($_POST['factura_nif'] ?? '');
    $factura_email  = trim($_POST['factura_email'] ?? '');

    // botons
    $accio_afegir_linia   = isset($_POST['afegir_linia']);
    $accio_desar          = isset($_POST['desar']);
    $accio_enviar_b2b     = isset($_POST['enviar_b2brouter']);

    // Si no té escriptura o la factura està marcada com a definitiva, bloquejar
    if ($id) {
        $stmtChk = $pdo->prepare("SELECT enviadaportal FROM factures WHERE id = :id");
        $stmtChk->execute(['id' => $id]);
        $rowChk = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if ($rowChk && !empty($rowChk['enviadaportal'])) {
            http_response_code(403);
            echo "<h2>Aquesta factura ja s'ha enviat al portal i no es pot modificar.</h2>";
            exit;
        }
    }

    // si ve d'un botó eliminar, marquem aquesta línia a esborrar
    if (!empty($_POST['delete_line_id'])) {
        $_POST['delete_line'] = [$_POST['delete_line_id']];
    }

    // agafem dades del client (adreça, email) per mostrar i per API (valors per defecte)
    $stmt = $pdo->prepare("SELECT nom, nif, adreca, email FROM clients WHERE id = :id");
    $stmt->execute(['id' => $client_id]);
    $cli = $stmt->fetch(PDO::FETCH_ASSOC);
    $adreca_client_def = $cli['adreca'] ?? '';
    $email_client_def  = $cli['email']  ?? '';
    $nom_client_def    = $cli['nom']    ?? '';
    $nif_client_def    = $cli['nif']    ?? '';

    // Si al formulari han posat nif/email, tenen prioritat sobre la fitxa de client
    $adreca_client = $adreca_client_def;
    $nom_client    = $nom_client_def;
    $nif_client    = $factura_nif !== '' ? $factura_nif : $nif_client_def;
    $email_client  = $factura_email !== '' ? $factura_email : $email_client_def;

    $pdo->beginTransaction();
    try {
        // 2.0) Desa/actualitza capçalera (incloent nif i email de factura)
        if ($id) {
            $sql = "
                UPDATE factures
                SET client_id    = :client_id,
                    data_factura = :data_factura,
                    observacions = :observacions,
                    nif          = :nif,
                    email        = :email
                WHERE id = :id
            ";
            $params = [
                'id'           => $id,
                'client_id'    => $client_id,
                'data_factura' => $data_factura,
                'observacions' => $observacions,
                'nif'          => $nif_client,
                'email'        => $email_client,
            ];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $facturaId = $id;
        } else {
            $sql = "
                INSERT INTO factures (client_id, data_factura, observacions, nif, email)
                VALUES (:client_id, :data_factura, :observacions, :nif, :email)
                RETURNING id
            ";
            $params = [
                'client_id'    => $client_id,
                'data_factura' => $data_factura,
                'observacions' => $observacions,
                'nif'          => $nif_client,
                'email'        => $email_client,
            ];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $facturaId = $stmt->fetchColumn();
            $id = $facturaId;
        }

        // 2.1) Esborrar línies marcades
        if (!empty($_POST['delete_line']) && is_array($_POST['delete_line'])) {
            $idsToDelete = array_filter($_POST['delete_line'], 'ctype_digit');
            if ($idsToDelete) {
                $in  = implode(',', array_fill(0, count($idsToDelete), '?'));
                $sql = "DELETE FROM factura_linies WHERE factura_id = ? AND id IN ($in)";
                $stmt = $pdo->prepare($sql);
                $params = array_merge([$facturaId], $idsToDelete);
                $stmt->execute($params);
            }
        }

        // 2.2) Guardar / actualitzar línies existents
        if (!empty($_POST['line_id']) && is_array($_POST['line_id'])) {
            foreach ($_POST['line_id'] as $idx => $lineId) {
                $lineId = $_POST['line_id'][$idx] ?? null;
                if (!$lineId) {
                    continue;
                }

                $qty  = $_POST['line_quantitat'][$idx] ?? null;
                $preu = $_POST['line_preu'][$idx] ?? null;

                if ($qty === null || $qty === '' || $preu === null || $preu === '') {
                    continue;
                }

                $qty  = (float)$qty;
                $preu = (float)$preu;
                if ($qty < 0 || $preu < 0) {
                    continue;
                }

                $desc   = $_POST['line_desc'][$idx] ?? '';
                $codi   = $_POST['line_codi'][$idx] ?? '';

                $sql = "
                    UPDATE factura_linies
                    SET quantitat = :quantitat,
                        preu_unitari = :preu_unitari,
                        descripcio = :descripcio,
                        codi_article = :codi_article
                    WHERE id = :id AND factura_id = :factura_id
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'quantitat'    => $qty,
                    'preu_unitari' => $preu,
                    'descripcio'   => $desc,
                    'codi_article' => $codi,
                    'id'           => $lineId,
                    'factura_id'   => $facturaId,
                ]);
            }
        }

        // 2.3) Afegir línia nova
        $new_article_id = $_POST['new_article_id'] ?? null;
        $new_qty        = $_POST['new_quantitat'] ?? null;

        // Només actuem si l’usuari ha premut Afegir línia
        if ($accio_afegir_linia) {
            if ($new_article_id && $new_qty !== null && $new_qty !== '') {
                $new_qty = (float)$new_qty;
                if ($new_qty > 0) {
                    $stmt = $pdo->prepare("SELECT id, codi, descripcio, preu FROM articles WHERE id = :id");
                    $stmt->execute(['id' => $new_article_id]);
                    $art = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($art) {
                        $stmt = $pdo->prepare("SELECT COALESCE(MAX(num_linia),0)+1 FROM factura_linies WHERE factura_id = :id");
                        $stmt->execute(['id' => $facturaId]);
                        $num_linia = (int)$stmt->fetchColumn();

                        $sql = "
                            INSERT INTO factura_linies
                                (factura_id, num_linia, article_id, codi_article, descripcio, preu_unitari, quantitat)
                            VALUES
                                (:factura_id, :num_linia, :article_id, :codi_article, :descripcio, :preu_unitari, :quantitat)
                        ";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            'factura_id'   => $facturaId,
                            'num_linia'    => $num_linia,
                            'article_id'   => $art['id'],
                            'codi_article' => $art['codi'],
                            'descripcio'   => $art['descripcio'],
                            'preu_unitari' => $art['preu'],
                            'quantitat'    => $new_qty,
                        ]);
                    }
                }
            }
            // Després d’afegir (o si estava buit), tornem al formulari
            $pdo->commit();
            header('Location: factures_form.php?id=' . $facturaId);
            exit;
        }

        // 2.4) Si s'ha premut "Enviar a B2Brouter", validem i després construïm JSON + API
        if ($accio_enviar_b2b) {
            // Validacions: ha de tenir nif, email, línies i import > 0
            $stmt = $pdo->prepare("
                SELECT nif, email, COALESCE(import_total,0) AS import_total, enviada_portal
                FROM factures
                WHERE id = :id
            ");
            $stmt->execute(['id' => $facturaId]);
            $fchk = $stmt->fetch(PDO::FETCH_ASSOC);

            $errorEnviar = '';

            if (!$fchk) {
                $errorEnviar = 'La factura no existeix.';
            } elseif (!empty($fchk['enviada_portal'])) {
                $errorEnviar = 'La factura ja s\'ha enviat al portal i no es pot tornar a enviar.';
            } elseif (empty(trim($fchk['nif'] ?? ''))) {
                $errorEnviar = 'No es pot enviar la factura: falta el NIF del client.';
            } elseif (empty(trim($fchk['email'] ?? ''))) {
                $errorEnviar = 'No es pot enviar la factura: falta el correu electrònic del client.';
            } else {
                // Comprovar línies i import
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS num_linies,
                           COALESCE(SUM(quantitat * preu_unitari),0) AS total
                    FROM factura_linies
                    WHERE factura_id = :id
                ");
                $stmt->execute(['id' => $facturaId]);
                $lres = $stmt->fetch(PDO::FETCH_ASSOC);

                if (empty($lres['num_linies']) || (int)$lres['num_linies'] === 0) {
                    $errorEnviar = 'No es pot enviar la factura: no té cap línia.';
                } elseif ((float)$lres['total'] <= 0) {
                    $errorEnviar = 'No es pot enviar la factura: l\'import total és 0.';
                }
            }

            if ($errorEnviar !== '') {
                // No enviem, però deixem tot desat i tornem al formulari amb missatge
                $pdo->commit();
                $msg = urlencode($errorEnviar);
                header('Location: factures_form.php?id='.$facturaId.'&msg='.$msg.'&error=1');
                exit;
            }

            // Carregar línies actualitzades per a l'enviament
            $stmt = $pdo->prepare("
                SELECT num_linia, codi_article, descripcio, preu_unitari, quantitat
                FROM factura_linies
                WHERE factura_id = :id
                ORDER BY num_linia
            ");
            $stmt->execute(['id' => $facturaId]);
            $liniesForApi = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Carregar capçalera actualitzada (incloure num_factura si el tens)
            $stmt = $pdo->prepare("
                SELECT f.*, c.nom AS client_nom, c.nif AS client_nif, c.adreca AS client_adreca
                FROM factures f
                JOIN clients c ON c.id = f.client_id
                WHERE f.id = :id
            ");
            $stmt->execute(['id' => $facturaId]);
            $factApi = $stmt->fetch(PDO::FETCH_ASSOC);

            $nif_api   = $factApi['nif']    ?: ($factApi['client_nif'] ?? $nif_client_def);
            $email_api = $factApi['email']  ?: ($email_client ?? $email_client_def);

            // Construir estructura bàsica de factura per l'API (exemple genèric)
            $items = [];
            $total = 0;
            foreach ($liniesForApi as $l) {
                $linTotal = (float)$l['preu_unitari'] * (float)$l['quantitat'];
                $total += $linTotal;
                $items[] = [
                    'lineNumber' => (int)$l['num_linia'],
                    'itemCode'   => $l['codi_article'],
                    'description'=> $l['descripcio'],
                    'quantity'   => (float)$l['quantitat'],
                    'unitPrice'  => (float)$l['preu_unitari'],
                    'lineTotal'  => $linTotal,
                ];
            }

            $payload = [
                'invoiceNumber'  => $factApi['num_factura'] ?? ('LOCAL-'.$facturaId),
                'issueDate'      => $factApi['data_factura'] ?? date('Y-m-d'),
                'customer'       => [
                    'name'    => $factApi['client_nom'] ?? $nom_client_def,
                    'taxId'   => $nif_api,
                    'address' => $factApi['client_adreca'] ?? $adreca_client_def,
                    'email'   => $email_api,
                ],
                'currency'       => 'EUR',
                'lines'          => $items,
                'totalAmount'    => $total,
                'internalId'     => $facturaId,
                'notes'          => $factApi['observacions'] ?? $observacions,
            ];

            // Enviament via cURL JSON
            $ch      = curl_init(B2B_API_URL);
            $json    = json_encode($payload);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer '.B2B_API_TOKEN,
            ]);

            $respBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            // Text resum d'estat que guardarem a estat_portal i marquem definitiva
            if ($curlErr) {
                $estatPortal = 'Error cURL B2Brouter: '.$curlErr;
                $enviada     = 0;
            } elseif ($httpCode >= 200 && $httpCode < 300) {
                $estatPortal = 'Enviada a B2Brouter (HTTP '.$httpCode.')';
                $enviada     = 1;
            } else {
                $estatPortal = 'Error B2Brouter (HTTP '.$httpCode.')';
                $enviada     = 0;
            }

            $stmt = $pdo->prepare("
                UPDATE factures
                SET estat_portal   = :estat,
                    enviada_portal = :enviada
                WHERE id = :id
            ");
            $stmt->execute([
                'estat'   => mb_substr($estatPortal, 0, 250),
                'enviada' => $enviada,
                'id'      => $facturaId,
            ]);

            $pdo->commit();

            // Missatge amable segons resultat
            if ($enviada) {
                $msg = urlencode('Factura enviada correctament a B2Brouter. A partir d\'ara ja no es podrà modificar ni esborrar.');
            } else {
                $msg = urlencode('Hi ha hagut un error en enviar la factura a B2Brouter: revisa les dades o torna-ho a provar més tard.');
            }

            header('Location: factures_form.php?id='.$facturaId.'&msg='.$msg);
            exit;
        }

        // Si només era desar
        $pdo->commit();

        if ($accio_desar) {
            header('Location: factures_clients_list.php');
            exit;
        }

        header('Location: factures_form.php?id=' . $facturaId);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error desant: '.$e->getMessage());
    }
}

// 3) Si no hi ha POST: carregar dades per mostrar formulari
$client_id      = '';
$data_factura   = date('Y-m-d');
$observacions   = '';
$linies         = [];
$articles       = [];
$email_client   = '';
$adreca_client  = '';
$estat_portal   = '';
$factura_nif    = '';
$factura_email  = '';
$enviada_portal = 0;

// Carregar articles per al select de nova línia
$artsStmt = $pdo->query("SELECT id, codi, descripcio FROM articles ORDER BY codi");
$articles = $artsStmt->fetchAll(PDO::FETCH_ASSOC);

if ($id) {
    $stmt = $pdo->prepare("
        SELECT f.*, c.nom AS client_nom, c.email AS client_email, c.adreca AS client_adreca, c.nif AS client_nif
        FROM factures f
        JOIN clients c ON c.id = f.client_id
        WHERE f.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $client_id      = $row['client_id'];
        $data_factura   = $row['data_factura'];
        $observacions   = $row['observacions'];
        $factura_email  = $row['email'] ?? '';
        $factura_nif    = $row['nif']   ?? '';
        $email_client   = $row['client_email'] ?? '';
        $adreca_client  = $row['client_adreca'] ?? '';
        $estat_portal   = $row['estat_portal'] ?? '';
        $enviada_portal = (int)($row['enviada_portal'] ?? 0);
    }

    $stmt = $pdo->prepare("
        SELECT id, num_linia, codi_article, descripcio, preu_unitari, quantitat
        FROM factura_linies
        WHERE factura_id = :id
        ORDER BY num_linia
    ");
    $stmt->execute(['id' => $id]);
    $linies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Si encara no tenim email (nova factura però amb client triat)
if (!$factura_email && $client_id) {
    $stmt = $pdo->prepare("SELECT email, adreca, nif FROM clients WHERE id = :id");
    $stmt->execute(['id' => $client_id]);
    $cli = $stmt->fetch(PDO::FETCH_ASSOC);
    $email_client   = $cli['email']  ?? '';
    $adreca_client  = $cli['adreca'] ?? '';
    if (!$factura_nif) {
        $factura_nif = $cli['nif'] ?? '';
    }
    if (!$factura_email) {
        $factura_email = $cli['email'] ?? '';
    }
}

// variable per al HTML: atribut disabled/readonly si només lectura
// si està enviada al portal, també desactivem edició encara que tingui permís
if ($enviada_portal) {
    $attrDisabled = 'disabled';
    $attrReadOnly = 'readonly';
} else {
    $attrDisabled = $tePermisEscriptura ? '' : 'disabled';
    $attrReadOnly = $tePermisEscriptura ? '' : 'readonly';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Factura '.$id : 'Nova factura' ?></title>
    <style>
        :root {
            --bg: #f5f7fb;
            --card-bg: #ffffff;
            --text-main: #334155;
            --text-soft: #64748b;
            --btn-save-bg: #bae6fd;
            --btn-save-border: #0ea5e9;
            --btn-back-bg: #e5e7eb;
            --btn-back-border: #cbd5f5;
            --input-border: #d4d4d8;
            --input-focus: #0ea5e9;
            --row-hover: #eff6ff;
            --row-even: #f9fafb;
            --row-odd: #fefce8;
            --btn-disabled-bg: #e5e7eb;
            --btn-disabled-border: #d4d4d8;
            --btn-disabled-text: #9ca3af;
        }
        *{
            box-sizing:border-box;margin:0;padding:0;
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        }
        body{
            min-height:100vh;display:flex;align-items:flex-start;justify-content:center;
            background:radial-gradient(circle at top,#e0f2fe 0,var(--bg) 45%,#fefce8 100%);
            color:var(--text-main);
        }
        .wrapper{width:100%;max-width:1040px;padding:24px;}
        .card{
            background:var(--card-bg);border-radius:18px;padding:22px 26px;
            box-shadow:0 18px 45px rgba(15,23,42,0.15),0 0 0 1px rgba(148,163,184,0.25);
        }
        .header{display:flex;justify-content:space-between;gap:16px;margin-bottom:16px;flex-wrap:wrap;}
        .header-title h1{font-size:1.3rem;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;margin-bottom:4px;}
        .header-title p{font-size:0.9rem;color:var(--text-soft);}
        .header-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;align-items:center;}
        .btn{
            display:inline-flex;align-items:center;justify-content:center;
            padding:8px 14px;border-radius:999px;border:1px solid transparent;
            text-decoration:none;font-size:0.85rem;font-weight:600;
            letter-spacing:0.04em;text-transform:uppercase;cursor:pointer;
            transition:transform .12s,box-shadow .12s,background-color .12s,border-color .12s;
            white-space:nowrap;
            height:34px;
        }
        .btn span.icon{margin-right:6px;}
        .btn-back{background:var(--btn-back-bg);border-color:var(--btn-back-border);color:var(--text-main);}
        .btn-save{
            background:var(--btn-save-bg);border-color:var(--btn-save-border);color:#0f172a;
            box-shadow:0 8px 18px rgba(14,165,233,0.45);
        }
        .btn-pdf{
            background:#bbf7d0;border-color:#22c55e;color:#14532d;
            box-shadow:0 8px 18px rgba(34,197,94,0.45);
        }
        .btn-secondary{background:#e0e7ff;border-color:#a5b4fc;color:#1e293b;}
        .btn-b2b{
            background:#fee2e2;border-color:#f97373;color:#7f1d1d;
            box-shadow:0 8px 18px rgba(248,113,113,0.45);
        }
        .btn-danger{
            background:#fee2e2;border-color:#fecaca;color:#b91c1c;
            font-size:.78rem;padding:4px 10px;border-radius:999px;
        }
        .btn-disabled{
            background:var(--btn-disabled-bg);border-color:var(--btn-disabled-border);
            color:var(--btn-disabled-text);box-shadow:none;cursor:default;
        }
        .btn:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(15,23,42,0.18);}
        .btn-disabled:hover{transform:none;box-shadow:none;}
        form{margin-top:10px;}
        .grid-header{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 20px;}
        .field{display:flex;flex-direction:column;gap:4px;}
        .field label{
            font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;
            color:var(--text-soft);font-weight:500;
        }
        .field label span.required{color:#dc2626;margin-left:4px;}
        .field select,.field input,.field textarea{
            padding:7px 10px;border-radius:8px;border:1px solid var(--input-border);
            font-size:.9rem;color:var(--text-main);background:#fdfdfd;
            transition:border-color .15s,box-shadow .15s,background-color .15s;
            width:100%;
        }
        .field textarea{min-height:70px;resize:vertical;}
        .field select:focus,.field input:focus,.field textarea:focus{
            outline:none;border-color:var(--input-focus);
            box-shadow:0 0 0 1px rgba(14,165,233,.35);background:#fff;
        }
        .field-full{grid-column:1 / -1;}
        .section-linies-title{
            margin-top:20px;margin-bottom:6px;font-size:.9rem;
            text-transform:uppercase;letter-spacing:.08em;color:var(--text-soft);
        }
        .table-wrapper{
            border-radius:14px;overflow:hidden;
            border:1px solid rgba(148,163,184,.5);
            background:linear-gradient(to bottom,#f9fafb,#fff);
        }
        table{width:100%;border-collapse:collapse;font-size:.9rem;}
        thead{background:linear-gradient(to right,#e0f2fe,#ede9fe);}
        th,td{padding:7px 8px;text-align:left;}
        th{
            font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;
            color:#4b5563;border-bottom:1px solid rgba(148,163,184,.7);
        }
        tbody tr:nth-child(odd){background:var(--row-odd);}
        tbody tr:nth-child(even){background:var(--row-even);}
        tbody tr:hover{background:var(--row-hover);}
        td{border-bottom:1px solid rgba(226,232,240,.9);color:#111827;}
        .col-num{width:60px;}
        .col-qty,.col-preu,.col-total{text-align:right;white-space:nowrap;}
        .col-qty{width:90px;}
        .col-preu{width:100px;}
        .col-total{width:110px;}
        .col-del{width:80px;text-align:center;}
        .tag{
            display:inline-block;padding:2px 8px;border-radius:999px;
            font-size:.75rem;background:#e5e7eb;color:#4b5563;
        }
        .small{font-size:.8rem;color:var(--text-soft);}
        .empty{padding:14px 10px;font-size:.9rem;color:var(--text-soft);}
        .input-num{text-align:right;}
        td input[type="text"],td input[type="number"]{
            padding:5px 7px;border-radius:6px;border:1px solid #e5e7eb;
            font-size:.85rem;background:#fff;
        }
        td input[type="text"]:focus,td input[type="number"]:focus{
            border-color:#93c5fd;box-shadow:0 0 0 1px rgba(59,130,246,.35);outline:none;
        }
        .form-footer{
            margin-top:18px;display:flex;justify-content:space-between;
            align-items:center;gap:10px;flex-wrap:wrap;
        }
        .footer-buttons{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
        .hint{font-size:.8rem;color:var(--text-soft);}
        .estat-portal{
            margin-top:8px;font-size:.8rem;color:#4b5563;
            background:#fefce8;border-radius:999px;padding:4px 10px;
            border:1px solid #fde68a;display:inline-flex;align-items:center;gap:6px;
        }
        .flash{
            margin-bottom:10px;padding:8px 12px;border-radius:10px;
            background:#ecfeff;color:#0f172a;border:1px solid #67e8f9;
            font-size:.85rem;
        }
        .flash-error{
            background:#fef2f2;
            color:#7f1d1d;
            border-color:#fecaca;
            box-shadow:0 0 0 1px rgba(248,113,113,0.5);
        }
        .flash-error::before{
            content:"⚠️  Atenció: ";
            font-weight:700;
            margin-right:4px;
        }
        @media (max-width:780px){.grid-header{grid-template-columns:1fr;}}
        @media (max-width:640px){
            .wrapper{padding:16px;}
            .card{padding:18px 16px;}
            .header{flex-direction:column;align-items:stretch;}
            .form-footer{flex-direction:column-reverse;align-items:flex-start;}
            .footer-buttons{width:100%;justify-content:flex-start;}
        }
    </style>
    <script>
        function confirmEnviarB2B() {
            // Validació bàsica al client: NIF, email, línies i total > 0
            const nif   = (document.querySelector('input[name="factura_nif"]') || {}).value || '';
            const email = (document.querySelector('input[name="factura_email"]') || {}).value || '';

            let numLinies = 0;
            let total = 0;

            document.querySelectorAll('tbody tr').forEach(function (tr) {
                const qtyInput  = tr.querySelector('input[name="line_quantitat[]"]');
                const preuInput = tr.querySelector('input[name="line_preu[]"]');
                if (qtyInput && preuInput) {
                    const q = parseFloat(qtyInput.value.replace(',', '.')) || 0;
                    const p = parseFloat(preuInput.value.replace(',', '.')) || 0;
                    if (q > 0 && p >= 0) {
                        numLinies++;
                        total += q * p;
                    }
                }
            });

            let errors = [];
            if (!nif.trim()) {
                errors.push("· Falta el NIF del client.");
            }
            if (!email.trim()) {
                errors.push("· Falta el correu electrònic del client.");
            }
            if (numLinies === 0) {
                errors.push("· La factura no té cap línia amb quantitat.");
            }
            if (total <= 0) {
                errors.push("· L'import total de la factura és 0.");
            }

            if (errors.length > 0) {
                alert(
                    "NO S'HA ENVIAT LA FACTURA\n\n" +
                    "Per poder enviar-la a B2Brouter cal completar aquestes dades:\n\n" +
                    errors.join("\n") +
                    "\n\nRevisa la informació, desa els canvis i torna-ho a provar."
                );
                return false;
            }

            const missatge =
                "Estàs a punt d’enviar aquesta factura a B2Brouter.\n\n" +
                "Després de l’enviament:\n" +
                "· La factura quedarà registrada al portal.\n" +
                "· Ja no es podrà modificar ni esborrar des d’aquest programa.\n\n" +
                "Si tot és correcte i vols continuar amb l’enviament definitiu, prem «D’acord».";

            return confirm(missatge);
        }
    </script>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <?php if ($flash): ?>
            <div class="flash<?= $flashIsError ? ' flash-error' : '' ?>">
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <div class="header">
            <div class="header-title">
                <h1><?= $id ? 'Editar factura' : 'Nova factura' ?></h1>
                <p>
                    <?=
                        $enviada_portal
                            ? 'Aquesta factura ja s’ha enviat al portal i està en mode només lectura.'
                            : ($tePermisEscriptura
                                ? 'Capçalera i línies en una sola pantalla. Pots enviar la factura al portal B2Brouter.'
                                : 'Vista en mode només lectura: no es poden modificar dades ni enviar al portal.')
                    ?>
                </p>
                <?php if ($id && $estat_portal !== ''): ?>
                    <div class="estat-portal">
                        <span>🌐 Estat portal:</span>
                        <strong><?= htmlspecialchars($estat_portal) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-actions">
                <a href="factures_clients_list.php" class="btn btn-back">
                    <span class="icon">←</span> Llista
                </a>
                <?php if ($id): ?>
                    <a href="factura_pdf.php?factura_id=<?= htmlspecialchars($id) ?>"
                       class="btn btn-pdf" target="_blank">
                        <span class="icon">📄</span> Factura PDF
                    </a>
                    <a href="factura_sendmail.php?id=<?= htmlspecialchars($id) ?>"
                       class="btn btn-save">
                        <span class="icon">✉️</span> Enviar mail
                    </a>
                    <?php if ($tePermisEscriptura && !$enviada_portal): ?>
                        <!-- Formulari independent per enviar a B2Brouter sobre la mateixa factura -->
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                            <input type="hidden" name="client_id" value="<?= htmlspecialchars($client_id) ?>">
                            <input type="hidden" name="data_factura" value="<?= htmlspecialchars($data_factura) ?>">
                            <input type="hidden" name="observacions" value="<?= htmlspecialchars($observacions) ?>">
                            <input type="hidden" name="factura_nif" value="<?= htmlspecialchars($factura_nif) ?>">
                            <input type="hidden" name="factura_email" value="<?= htmlspecialchars($factura_email) ?>">
                            <button type="submit" name="enviar_b2brouter" class="btn btn-b2b"
                                    onclick="return confirmEnviarB2B();">
                                <span class="icon">🌐</span> Enviar a B2Brouter
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id ?? '') ?>">

            <!-- CAPÇALERA -->
            <div class="grid-header">
                <div class="field">
                    <label>Client <span class="required">*</span></label>
                    <select name="client_id" required <?= $attrDisabled ?>>
                        <option value="">— Tria un client —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                <?= ($c['id'] == $client_id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Data factura <span class="required">*</span></label>
                    <input type="date" name="data_factura"
                           value="<?= htmlspecialchars($data_factura) ?>"
                           required <?= $attrDisabled ?>>
                </div>

                <div class="field">
                    <label>NIF per enviar al portal <span class="required">*</span></label>
                    <input type="text" name="factura_nif"
                           value="<?= htmlspecialchars($factura_nif) ?>"
                           placeholder="NIF fiscal del client" <?= $attrReadOnly ?>>
                </div>

                <div class="field">
                    <label>Email per enviar factura <span class="required">*</span></label>
                    <input type="email" name="factura_email"
                           value="<?= htmlspecialchars($factura_email) ?>"
                           placeholder="Correu electrònic destinatari" <?= $attrReadOnly ?>>
                </div>

                <div class="field field-full">
                    <label>Adreça de facturació (referència fitxa client)</label>
                    <input type="text" value="<?= htmlspecialchars($adreca_client) ?>" disabled>
                </div>

                <div class="field field-full">
                    <label>Observacions</label>
                    <textarea name="observacions" <?= $attrDisabled ?>><?= htmlspecialchars($observacions) ?></textarea>
                </div>
            </div>

            <!-- LÍNIES -->
            <div class="section-linies-title">Línies de factura</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th class="col-num">Línia</th>
                        <th>Codi</th>
                        <th>Descripció</th>
                        <th class="col-qty">Quantitat</th>
                        <th class="col-preu">Preu</th>
                        <th class="col-total">Import</th>
                        <th class="col-del">Elimina</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($linies)): ?>
                        <tr>
                            <td colspan="7" class="empty">
                                Encara no hi ha línies. <?= ($tePermisEscriptura && !$enviada_portal) ? 'Afegeix-ne una més avall.' : 'No tens permís per afegir línies.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($linies as $idx => $l): ?>
                            <?php $import = (float)$l['preu_unitari'] * (float)$l['quantitat']; ?>
                            <tr>
                                <td class="col-num">
                                    <span class="tag">#<?= htmlspecialchars($l['num_linia']) ?></span>
                                    <input type="hidden" name="line_id[]"
                                           value="<?= htmlspecialchars($l['id']) ?>">
                                </td>
                                <td>
                                    <input type="text" name="line_codi[]"
                                           value="<?= htmlspecialchars($l['codi_article']) ?>"
                                           <?= $attrReadOnly ?>>
                                </td>
                                <td>
                                    <input type="text" name="line_desc[]"
                                           value="<?= htmlspecialchars($l['descripcio']) ?>"
                                           <?= $attrReadOnly ?>>
                                </td>
                                <td class="col-qty">
                                    <input class="input-num" type="number" step="0.01" min="0"
                                           name="line_quantitat[]"
                                           value="<?= htmlspecialchars($l['quantitat']) ?>"
                                           <?= $attrReadOnly ?>>
                                </td>
                                <td class="col-preu">
                                    <input class="input-num" type="number" step="0.01" min="0"
                                           name="line_preu[]"
                                           value="<?= htmlspecialchars($l['preu_unitari']) ?>"
                                           <?= $attrReadOnly ?>>
                                </td>
                                <td class="col-total">
                                    <?= htmlspecialchars(number_format($import, 2, ',', '.')) ?> €
                                </td>
                                <td class="col-del">
                                    <?php if ($tePermisEscriptura && !$enviada_portal): ?>
                                        <button type="submit"
                                                name="delete_line_id"
                                                value="<?= htmlspecialchars($l['id']) ?>"
                                                class="btn btn-danger"
                                                onclick="return confirm('Vols eliminar aquesta línia?');">
                                            ✖
                                        </button>
                                    <?php else: ?>
                                        <span class="small">🔒</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Afegir línia nova -->
            <div style="margin-top: 10px;">
                <div class="field" style="margin-bottom:6px;">
                    <label>Afegir nova línia</label>
                </div>
                <div class="grid-header" style="grid-template-columns: 2fr 1fr;">
                    <div class="field">
                        <select name="new_article_id" <?= ($tePermisEscriptura && !$enviada_portal) ? '' : 'disabled' ?>>
                            <option value="">— Article —</option>
                            <?php foreach ($articles as $art): ?>
                                <option value="<?= $art['id'] ?>">
                                    <?= htmlspecialchars($art['codi'].' - '.$art['descripcio']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <input class="input-num" type="number" name="new_quantitat"
                               step="0.01" min="0" placeholder="Quantitat" <?= ($tePermisEscriptura && !$enviada_portal) ? '' : 'disabled' ?>>
                    </div>
                </div>
            </div>

            <!-- Peu formulari -->
            <div class="form-footer">
                <div class="hint">
                    <?=
                        $enviada_portal
                            ? 'Factura enviada al portal: ja no es pot modificar ni esborrar des d\'aquí.'
                            : ($tePermisEscriptura
                                ? 'Pots afegir o eliminar línies i, quan acabis, prem Desar o Enviar a B2Brouter.'
                                : 'Mode només lectura: no es poden modificar ni desar canvis.')
                    ?>
                </div>
                <div class="footer-buttons">
                    <?php if ($tePermisEscriptura && !$enviada_portal): ?>
                        <button type="submit" name="afegir_linia" class="btn btn-secondary">
                            <span class="icon">＋</span> Afegir línia
                        </button>
                        <button type="submit" name="desar" class="btn btn-save">
                            <span class="icon">💾</span> Desar
                        </button>
                    <?php else: ?>
                        <span class="btn btn-secondary btn-disabled">
                            <span class="icon">🔒</span> Sense permís d'edició
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>

<?php
require_once 'db.php';
require_once 'config.php';
session_start();
$pdo = getPDO();

/**
 * Permisos segons el teu model
 */
function user_can_facturar_albarans(PDO $pdo): bool {
    return tePermisGestio($pdo, 2);
}

// Helpers
function getPendingAlbaransGrouped(PDO $pdo): array {
    $sql = "
        SELECT 
            a.id,
            a.num_albara,
            a.data_albara,
            c.id   AS client_id,
            c.nom  AS client_nom,
            COALESCE(SUM(l.quantitat * l.preu_unitari), 0) AS import_total
        FROM albarans a
        JOIN clients c       ON c.id = a.client_id
        LEFT JOIN albara_linies l ON l.albara_id = a.id
        LEFT JOIN factura_albarans fa ON fa.albara_id = a.id
        WHERE fa.id IS NULL
        GROUP BY a.id, a.num_albara, a.data_albara, c.id, c.nom
        ORDER BY c.nom, a.data_albara, a.id
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $r) {
        $cid = (int)$r['client_id'];
        if (!isset($result[$cid])) {
            $result[$cid] = [
                'client_id'   => $cid,
                'client_nom'  => $r['client_nom'],
                'subtotal'    => 0,
                'albarans'    => [],
            ];
        }
        $import = (float)$r['import_total'];
        $result[$cid]['subtotal'] += $import;
        $result[$cid]['albarans'][] = [
            'id'          => $r['id'],
            'num_albara'  => $r['num_albara'],
            'data_albara' => $r['data_albara'],
            'import'      => $import,
        ];
    }

    return $result;
}

// PROCESSAR POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_can_facturar_albarans($pdo)) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="ca">
        <head>
            <meta charset="UTF-8">
            <title>Permís denegat</title>
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
                        radial-gradient(circle at top, rgba(248,113,113,0.30), transparent 55%),
                        radial-gradient(circle at bottom, rgba(127,29,29,0.50), transparent 55%),
                        var(--bg);
                    color: var(--text-main);
                }
                .deny-card {
                    max-width: 420px;
                    width: 90%;
                    background: radial-gradient(circle at top left, rgba(248,113,113,0.16), rgba(15,23,42,0.98));
                    border-radius: 18px;
                    box-shadow:
                        0 22px 55px rgba(0,0,0,0.9),
                        0 0 0 1px rgba(248,113,113,0.45);
                    padding: 20px 22px 18px;
                    text-align: center;
                }
                .deny-icon {
                    width: 46px;
                    height: 46px;
                    border-radius: 999px;
                    margin: 0 auto 10px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: radial-gradient(circle at top, rgba(248,113,113,0.7), rgba(127,29,29,1));
                    color: #fee2e2;
                    font-size: 22px;
                    box-shadow: 0 0 0 6px rgba(248,113,113,0.3);
                }
                .deny-title {
                    font-size: 1.05rem;
                    font-weight: 700;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                    margin-bottom: 6px;
                    color: #fecaca;
                }
                .deny-text {
                    font-size: 0.9rem;
                    color: #fee2e2;
                    margin-bottom: 10px;
                }
                .deny-small {
                    font-size: 0.78rem;
                    color: #fed7d7;
                    margin-bottom: 14px;
                }
                .deny-actions {
                    display: flex;
                    justify-content: center;
                    gap: 8px;
                }
                .btn-soft {
                    padding: 7px 14px;
                    border-radius: 999px;
                    border: 1px solid rgba(248,113,113,0.9);
                    background: linear-gradient(to right, #b91c1c, #ef4444);
                    color: #fef2f2;
                    font-size: 0.8rem;
                    font-weight: 600;
                    letter-spacing: 0.09em;
                    text-transform: uppercase;
                    text-decoration: none;
                    cursor: pointer;
                    box-shadow: 0 14px 30px rgba(248,113,113,0.8);
                    transition: transform 0.12s, box-shadow 0.12s, background-color 0.12s;
                }
                .btn-soft:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 18px 38px rgba(248,113,113,1);
                }
            </style>
        </head>
        <body>
        <div class="deny-card">
            <div class="deny-icon">!</div>
            <div class="deny-title">Accés restringit</div>
            <div class="deny-text">
                No disposes de permisos per facturar albarans en aquest usuari.
            </div>
            <div class="deny-small">
                Si creus que és un error, posa’t en contacte amb l’administrador del sistema.
            </div>
            <div class="deny-actions">
                <a href="factures_clients_list.php" class="btn-soft">Tornar a factures</a>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Facturar tot el pendent
    if (isset($_POST['facturar_tot'])) {
        $clientsPendents = getPendingAlbaransGrouped($pdo);
        if (empty($clientsPendents)) {
            die('No hi ha albarans pendents de facturar.');
        }

        try {
            $pdo->beginTransaction();

            foreach ($clientsPendents as $client) {
                $client_id = $client['client_id'];
                $albara_ids = array_column($client['albarans'], 'id');
                if (empty($albara_ids)) {
                    continue;
                }

                $in = implode(',', array_fill(0, count($albara_ids), '?'));

                $sqlTotal = "
                    SELECT COALESCE(SUM(l.quantitat * l.preu_unitari), 0) AS total
                    FROM albara_linies l
                    WHERE l.albara_id IN ($in)
                ";
                $stmt = $pdo->prepare($sqlTotal);
                $stmt->execute($albara_ids);
                $total = (float)$stmt->fetchColumn();

                if ($total <= 0) {
                    continue;
                }

                $numFactura = 'F' . date('YmdHis') . '_' . $client_id;

                $sqlFact = "
                    INSERT INTO factures
                        (num_factura, client_id, data_factura, import_total)
                    VALUES
                        (:num_factura, :client_id, :data_factura, :import_total)
                    RETURNING id
                ";
                $stmt = $pdo->prepare($sqlFact);
                $stmt->execute([
                    'num_factura'  => $numFactura,
                    'client_id'    => $client_id,
                    'data_factura' => date('Y-m-d'),
                    'import_total' => $total,
                ]);
                $facturaId = (int)$stmt->fetchColumn();

                $sqlRel = "
                    INSERT INTO factura_albarans (factura_id, albara_id)
                    VALUES (:factura_id, :albara_id)
                ";
                $stmtRel = $pdo->prepare($sqlRel);
                foreach ($albara_ids as $aid) {
                    $stmtRel->execute([
                        'factura_id' => $facturaId,
                        'albara_id'  => $aid,
                    ]);
                }

                $sqlLiniesAlb = "
                    SELECT l.*
                    FROM albara_linies l
                    WHERE l.albara_id = :albara_id
                    ORDER BY l.num_linia
                ";
                $stmtLinAlb = $pdo->prepare($sqlLiniesAlb);

                $sqlInsertFacLin = "
                    INSERT INTO factura_linies
                        (factura_id, num_linia, article_id, codi_article,
                         descripcio, preu_unitari, quantitat)
                    VALUES
                        (:factura_id, :num_linia, :article_id, :codi_article,
                         :descripcio, :preu_unitari, :quantitat)
                ";
                $stmtInsFacLin = $pdo->prepare($sqlInsertFacLin);

                $num_linia = 1;
                foreach ($albara_ids as $aid) {
                    $stmtLinAlb->execute(['albara_id' => $aid]);
                    $liniesAlb = $stmtLinAlb->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($liniesAlb as $l) {
                        $stmtInsFacLin->execute([
                            'factura_id'   => $facturaId,
                            'num_linia'    => $num_linia++,
                            'article_id'   => $l['article_id'] ?? null,
                            'codi_article' => $l['codi_article'],
                            'descripcio'   => $l['descripcio'],
                            'preu_unitari' => $l['preu_unitari'],
                            'quantitat'    => $l['quantitat'],
                        ]);
                    }
                }
            }

            $pdo->commit();
            header('Location: factures_clients_list.php');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            die('Error facturant tot el pendent: ' . $e->getMessage());
        }
    }

    // Facturar només seleccionats
    $albara_ids = $_POST['albara_ids'] ?? [];
    if (!is_array($albara_ids) || count($albara_ids) === 0) {
        die('No s\'ha seleccionat cap albarà.');
    }

    $albara_ids = array_values(array_filter($albara_ids, fn($v) => ctype_digit((string)$v)));
    if (count($albara_ids) === 0) {
        die('Llista d\'albarans no vàlida.');
    }

    $in = implode(',', array_fill(0, count($albara_ids), '?'));
    $sql = "
        SELECT a.id,
               a.client_id,
               a.num_albara,
               a.data_albara,
               c.nom AS client_nom
        FROM albarans a
        JOIN clients c ON c.id = a.client_id
        WHERE a.id IN ($in)
        ORDER BY a.data_albara, a.id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($albara_ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        die('No s\'han trobat albarans.');
    }

    $client_id = $rows[0]['client_id'];
    foreach ($rows as $r) {
        if ((int)$r['client_id'] !== (int)$client_id) {
            die('Tots els albarans seleccionats han de ser del mateix client.');
        }
    }

    try {
        $pdo->beginTransaction();

        $sqlTotal = "
            SELECT COALESCE(SUM(l.quantitat * l.preu_unitari), 0) AS total
            FROM albara_linies l
            WHERE l.albara_id IN ($in)
        ";
        $stmt = $pdo->prepare($sqlTotal);
        $stmt->execute($albara_ids);
        $total = (float)$stmt->fetchColumn();

        $numFactura = 'F' . date('YmdHis');

        $sqlFact = "
            INSERT INTO factures
                (num_factura, client_id, data_factura, import_total)
            VALUES
                (:num_factura, :client_id, :data_factura, :import_total)
            RETURNING id
        ";
        $stmt = $pdo->prepare($sqlFact);
        $stmt->execute([
            'num_factura'  => $numFactura,
            'client_id'    => $client_id,
            'data_factura' => date('Y-m-d'),
            'import_total' => $total,
        ]);
        $facturaId = (int)$stmt->fetchColumn();

        $sqlRel = "
            INSERT INTO factura_albarans (factura_id, albara_id)
            VALUES (:factura_id, :albara_id)
        ";
        $stmtRel = $pdo->prepare($sqlRel);
        foreach ($albara_ids as $aid) {
            $stmtRel->execute([
                'factura_id' => $facturaId,
                'albara_id'  => $aid,
            ]);
        }

        $sqlLiniesAlb = "
            SELECT l.*
            FROM albara_linies l
            WHERE l.albara_id = :albara_id
            ORDER BY l.num_linia
        ";
        $stmtLinAlb = $pdo->prepare($sqlLiniesAlb);

        $sqlInsertFacLin = "
            INSERT INTO factura_linies
                (factura_id, num_linia, article_id, codi_article,
                 descripcio, preu_unitari, quantitat)
            VALUES
                (:factura_id, :num_linia, :article_id, :codi_article,
                 :descripcio, :preu_unitari, :quantitat)
        ";
        $stmtInsFacLin = $pdo->prepare($sqlInsertFacLin);

        $num_linia = 1;
        foreach ($albara_ids as $aid) {
            $stmtLinAlb->execute(['albara_id' => $aid]);
            $liniesAlb = $stmtLinAlb->fetchAll(PDO::FETCH_ASSOC);
            foreach ($liniesAlb as $l) {
                $stmtInsFacLin->execute([
                    'factura_id'   => $facturaId,
                    'num_linia'    => $num_linia++,
                    'article_id'   => $l['article_id'] ?? null,
                    'codi_article' => $l['codi_article'],
                    'descripcio'   => $l['descripcio'],
                    'preu_unitari' => $l['preu_unitari'],
                    'quantitat'    => $l['quantitat'],
                ]);
            }
        }

        $pdo->commit();
        header('Location: factures_form.php?id=' . $facturaId);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error creant factura: ' . $e->getMessage());
    }
}

// GET
$clientsPendents = getPendingAlbaransGrouped($pdo);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Facturar albarans pendents</title>
    <style>
        :root {
            --bg: #020617;
            --panel-bg: rgba(15,23,42,0.98);
            --border-soft: rgba(55,65,81,0.9);
            --accent: #22c55e;
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        html, body { height: 100%; }
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgba(56,189,248,0.26), transparent 55%),
                radial-gradient(circle at bottom, rgba(22,163,74,0.32), transparent 55%),
                var(--bg);
            color: var(--text-main);
            display: flex;
        }
        .shell {
            width: 100%;
            min-height: 100vh;
            display: flex;
            justify-content: center;
        }
        .panel {
            width: 100%;
            max-width: 1160px;
            background: radial-gradient(circle at top left, rgba(15,23,42,0.9), rgba(15,23,42,0.98));
            border-left: 1px solid var(--border-soft);
            padding: 18px 22px 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow:
                0 22px 60px rgba(0,0,0,0.9),
                0 0 0 1px rgba(31,41,55,0.9);
        }
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(55,65,81,0.9);
        }
        .title-block h1 {
            font-size: 1.2rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--text-main);
        }
        .title-block p {
            font-size: 0.85rem;
            color: var(--text-soft);
            margin-top: 4px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid rgba(34,197,94,0.75);
            font-size: 0.7rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #bbf7d0;
            background: radial-gradient(circle at left, rgba(22,163,74,0.4), rgba(15,23,42,0.9));
        }
        .badge-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #22c55e;
            box-shadow: 0 0 0 4px rgba(34,197,94,0.35);
        }
        .card {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: radial-gradient(circle at top left, rgba(15,23,42,0.9), rgba(15,23,42,1));
            border-radius: 18px;
            border: 1px solid rgba(55,65,81,0.95);
            box-shadow:
                0 20px 55px rgba(0,0,0,1),
                0 0 0 1px rgba(30,64,175,0.5);
            padding: 12px 14px 14px;
            overflow: hidden;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 8px;
            gap: 8px;
        }
        .card-header-left h2 {
            font-size: 0.95rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--text-main);
        }
        .card-header-left p {
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
            background-color: rgba(15,23,42,0.9);
            border: 1px solid rgba(56,189,248,0.7);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #e0f2fe;
        }
        .pill span.icon { font-size: 0.95rem; }
        .table-shell {
            flex: 1;
            margin-top: 4px;
            border-radius: 14px;
            border: 1px solid rgba(55,65,81,0.95);
            background: linear-gradient(to bottom, rgba(15,23,42,1), rgba(15,23,42,0.98));
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .table-container {
            flex: 1;
            overflow: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        thead {
            position: sticky;
            top: 0;
            z-index: 2;
            background: linear-gradient(to right, rgba(30,64,175,0.95), rgba(22,163,74,0.9));
        }
        th, td {
            padding: 8px 10px;
            text-align: left;
        }
        th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #e5e7eb;
            border-bottom: 1px solid rgba(55,65,81,0.9);
        }
        tbody tr:nth-child(odd)  { background-color: rgba(17,24,39,0.96); }
        tbody tr:nth-child(even) { background-color: rgba(15,23,42,0.96); }
        tbody tr:hover { background-color: rgba(30,64,175,0.5); }
        .center { text-align: center; }
        .right  { text-align: right; }
        .col-check { width: 42px; }
        .col-num   { width: 120px; }
        .col-date  { width: 110px; white-space: nowrap; }
        .col-total { width: 120px; white-space: nowrap; }
        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #22c55e;
            cursor: pointer;
        }
        .group-row      { font-weight: 600; }
        .group-row-0    { background: linear-gradient(to right, rgba(15,23,42,0.98), rgba(15,23,42,0.9)); }
        .group-row-1    { background: linear-gradient(to right, rgba(30,64,175,0.45), rgba(15,23,42,0.95)); }
        .group-row-2    { background: linear-gradient(to right, rgba(22,163,74,0.40),  rgba(15,23,42,0.96)); }
        .group-row-3    { background: linear-gradient(to right, rgba(234,179,8,0.35),  rgba(15,23,42,0.96)); }
        .subtotal-row {
            background: rgba(15,23,42,0.96);
            font-weight: 600;
        }
        .empty {
            text-align: center;
            padding: 18px 12px;
            color: var(--text-soft);
            font-size: 0.9rem;
        }
        .card-footer {
            padding: 8px 10px 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            border-top: 1px solid rgba(55,65,81,0.9);
            background: linear-gradient(to right, rgba(15,23,42,0.98), rgba(15,23,42,0.95));
        }
        .hint {
            font-size: 0.78rem;
            color: var(--text-soft);
        }
        .hint strong { color: #e5e7eb; }
        .actions {
            display: flex;
            gap: 8px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 13px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.12s, box-shadow 0.12s,
                        background-color 0.12s, border-color 0.12s, color 0.12s;
            white-space: nowrap;
        }
        .btn span.icon {
            margin-right: 6px;
            font-size: 0.9rem;
        }
        .btn-primary {
            background: linear-gradient(to right, #22c55e, #16a34a);
            border-color: #22c55e;
            color: #ecfdf5;
            box-shadow: 0 12px 26px rgba(22,163,74,0.9);
        }
        .btn-primary:hover {
            background: linear-gradient(to right, #16a34a, #15803d);
            transform: translateY(-1px);
            box-shadow: 0 16px 34px rgba(22,163,74,1);
        }
        .btn-secondary {
            background-color: rgba(17,24,39,0.96);
            border-color: rgba(148,163,184,0.9);
            color: var(--text-main);
        }
        .btn-secondary:hover {
            background-color: rgba(31,41,55,1);
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15,23,42,0.9);
        }
        .btn-danger {
            background-color: rgba(127,29,29,0.96);
            border-color: rgba(248,113,113,0.9);
            color: #fee2e2;
            box-shadow: 0 10px 24px rgba(248,113,113,0.8);
        }
        .btn-danger:hover {
            background-color: #b91c1c;
            transform: translateY(-1px);
            box-shadow: 0 14px 32px rgba(248,113,113,1);
        }
        .no-perms {
            margin: 10px;
            padding: 10px 12px;
            border-radius: 14px;
            border: 1px dashed rgba(248,113,113,0.7);
            background: linear-gradient(to right, rgba(127,29,29,0.96), rgba(69,10,10,0.98));
            color: #fee2e2;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .no-perms-icon {
            width: 26px;
            height: 26px;
            border-radius: 999px;
            background: rgba(248,113,113,0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #450a0a;
        }
        @media (max-width: 768px) {
            .panel { padding: 14px 10px; }
            .card  { border-radius: 10px; }
            .card-footer { flex-direction: column; align-items: flex-start; }
            .actions { width: 100%; justify-content: flex-end; flex-wrap: wrap; }
        }
    </style>
    <script>
        function toggleClient(clientId, checked) {
            document.querySelectorAll('.factura-client-' + clientId)
                .forEach(function (cb) { cb.checked = checked; });
        }
        function confirmFacturarTot() {
            return confirm(
                "Això crearà una factura per a cada client amb tots els seus albarans pendents.\n\n" +
                "Vols continuar amb la facturació de tot el pendent?"
            );
        }
    </script>
</head>
<body>
<div class="shell">
    <div class="panel">
        <div class="panel-header">
            <div class="title-block">
                <h1>Facturació des d’albarans</h1>
                <p>Selecciona els albarans pendents agrupats per client per generar una factura.</p>
            </div>
            <div class="badge">
                <span class="badge-dot"></span>
                PENDENTS DE FACTURAR
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <h2>Albarans per client</h2>
                    <p>Pots marcar albarans individuals o bé totes les línies d’un client.</p>
                </div>
                <div class="card-header-right">
                    <div class="pill">
                        <span class="icon">🧾</span>
                        Pas 1 · Selecció d’albarans
                    </div>
                </div>
            </div>

            <div class="table-shell">
                <?php if (empty($clientsPendents)): ?>
                    <div class="empty">
                        No hi ha cap albarà pendent de facturar en aquest moment.
                    </div>
                <?php else: ?>
                    <?php if (!user_can_facturar_albarans($pdo)): ?>
                        <div class="no-perms">
                            <div class="no-perms-icon">!</div>
                            <div>
                                No tens permisos per facturar aquests albarans amb l’usuari actual.<br>
                                <span style="font-size:0.8rem;">Si ho necessites, contacta amb l’administrador perquè t’hi doni accés.</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="post" class="table-container" id="facturaForm">
                            <table>
                                <thead>
                                <tr>
                                    <th class="col-check center"></th>
                                    <th>Client / Albarà</th>
                                    <th class="col-num">Núm. albarà</th>
                                    <th class="col-date">Data</th>
                                    <th class="col-total right">Import</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $groupIndex = 0; ?>
                                <?php foreach ($clientsPendents as $client): ?>
                                    <?php $rowClass = 'group-row-' . ($groupIndex % 4); ?>
                                    <tr class="group-row <?= $rowClass ?>">
                                        <td class="center col-check">
                                            <input type="checkbox"
                                                   onclick="toggleClient(<?= (int)$client['client_id'] ?>, this.checked);">
                                        </td>
                                        <td colspan="3">
                                            <?= htmlspecialchars($client['client_nom']) ?>
                                            <span style="font-size:0.75rem;color:var(--text-soft);margin-left:6px;">
                                                (<?= count($client['albarans']) ?> albarans)
                                            </span>
                                        </td>
                                        <td class="right">
                                            Subtotal previst:
                                            <?= htmlspecialchars(number_format((float)$client['subtotal'], 2, ',', '.')) ?> €
                                        </td>
                                    </tr>
                                    <?php foreach ($client['albarans'] as $a): ?>
                                        <tr>
                                            <td class="center col-check">
                                                <input type="checkbox"
                                                       class="factura-client-<?= (int)$client['client_id'] ?>"
                                                       name="albara_ids[]"
                                                       value="<?= htmlspecialchars($a['id']) ?>">
                                            </td>
                                            <td><?= htmlspecialchars($client['client_nom']) ?></td>
                                            <td class="col-num"><?= htmlspecialchars($a['num_albara']) ?></td>
                                            <td class="col-date">
                                                <?= htmlspecialchars(
                                                    $a['data_albara']
                                                        ? (new DateTime($a['data_albara']))->format('d/m/Y')
                                                        : ''
                                                ) ?>
                                            </td>
                                            <td class="col-total right">
                                                <?= htmlspecialchars(number_format((float)$a['import'], 2, ',', '.')) ?> €
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="subtotal-row">
                                        <td></td>
                                        <td colspan="3">Subtotal del client</td>
                                        <td class="col-total right">
                                            <?= htmlspecialchars(number_format((float)$client['subtotal'], 2, ',', '.')) ?> €
                                        </td>
                                    </tr>
                                    <?php $groupIndex++; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="card-footer">
                                <div class="hint">
                                    Els <strong>albarans seleccionats han de ser del mateix client</strong> si crees una factura a mida.<br>
                                    El botó de “Facturar tot el pendent” generarà una factura per a cada client amb tot el que tingui pendent.
                                </div>
                                <div class="actions">
                                    <a href="factures_clients_list.php" class="btn btn-secondary">
                                        <span class="icon">←</span> Tornar
                                    </a>
                                    <button type="submit" name="facturar_tot" value="1"
                                            class="btn btn-danger"
                                            onclick="return confirmFacturarTot();">
                                        <span class="icon">⚠</span> Facturar tot el pendent
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <span class="icon">➜</span> Crear factura
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>

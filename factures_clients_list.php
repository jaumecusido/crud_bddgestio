<?php
require_once 'db.php';
require_once 'config.php';
session_start();
$pdo = getPDO();

/**
 * Permisos segons el teu model
 * - getUsuariActual($pdo)
 * - tePermisMestres($pdo, $nivellMinim)
 * - tePermisGestio($pdo, $nivellMinim)
 * Ja definits a config.php
 */

// Funció específica per veure factures de clients (per ex. gestió nivell 1)
function user_can_veure_factures_clients(PDO $pdo): bool {
    return tePermisGestio($pdo, 1);
}

// Funció específica per crear/editar factures de clients (per ex. gestió nivell 2)
function user_can_editar_factures_clients(PDO $pdo): bool {
    return tePermisGestio($pdo, 2);
}

// ---------- CONTROLADOR ESBORRAR FACTURA ----------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accio'], $_POST['factura_id'])
    && $_POST['accio'] === 'delete'
) {
    if (!user_can_editar_factures_clients($pdo)) {
        http_response_code(403);
        echo "<h2>Permís insuficient per esborrar factures.</h2>";
        exit;
    }

    $facturaId = (int)$_POST['factura_id'];
    if ($facturaId <= 0) {
        header('Location: factures_clients_list.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1) Eliminar relació amb albarans perquè quedin lliures
        $stmt = $pdo->prepare("DELETE FROM factura_albarans WHERE factura_id = :id");
        $stmt->execute(['id' => $facturaId]);

        // 2) Eliminar línies de la factura
        $stmt = $pdo->prepare("DELETE FROM factura_linies WHERE factura_id = :id");
        $stmt->execute(['id' => $facturaId]);

        // 3) Eliminar capçalera de factura
        $stmt = $pdo->prepare("DELETE FROM factures WHERE id = :id");
        $stmt->execute(['id' => $facturaId]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        // En un projecte gran es podria registrar l'error i mostrar un missatge amable
    }

    header('Location: factures_clients_list.php');
    exit;
}

// Si directament no pot veure factures, mostra una pàgina amable
if (!user_can_veure_factures_clients($pdo)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ca">
    <head>
        <meta charset="UTF-8">
        <title>Accés restringit · Factures clients</title>
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: radial-gradient(circle at top, #e0f2fe 0, #fdf2ff 40%, #ffffff 100%);
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                color: #0f172a;
            }
            .deny-card {
                max-width: 460px;
                width: 92%;
                background: #ffffff;
                border-radius: 18px;
                box-shadow:
                    0 20px 45px rgba(148, 163, 184, 0.45),
                    0 0 0 1px rgba(191, 219, 254, 0.9);
                border: 1px solid rgba(191, 219, 254, 0.9);
                padding: 22px 22px 18px;
                text-align: center;
            }
            .deny-icon {
                width: 52px;
                height: 52px;
                border-radius: 999px;
                margin: 0 auto 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: radial-gradient(circle at top, #bfdbfe, #e0f2fe);
                color: #1d4ed8;
                font-size: 24px;
                box-shadow: 0 0 0 6px rgba(191, 219, 254, 0.7);
            }
            .deny-title {
                font-size: 1.05rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                margin-bottom: 6px;
                color: #1e293b;
            }
            .deny-text {
                font-size: 0.9rem;
                color: #334155;
                margin-bottom: 10px;
            }
            .deny-small {
                font-size: 0.8rem;
                color: #64748b;
                margin-bottom: 16px;
            }
            .deny-actions {
                display: flex;
                justify-content: center;
                gap: 8px;
            }
            .btn-soft {
                padding: 7px 14px;
                border-radius: 999px;
                border: 1px solid rgba(129, 140, 248, 0.9);
                background: linear-gradient(to right, #eef2ff, #e0f2fe);
                color: #1e293b;
                font-size: 0.8rem;
                font-weight: 600;
                letter-spacing: 0.09em;
                text-transform: uppercase;
                text-decoration: none;
                cursor: pointer;
                box-shadow: 0 10px 24px rgba(129, 140, 248, 0.55);
                transition:
                    transform 0.12s ease,
                    box-shadow 0.12s ease,
                    background-color 0.12s ease,
                    border-color 0.12s ease;
            }
            .btn-soft:hover {
                transform: translateY(-1px);
                box-shadow: 0 14px 30px rgba(129, 140, 248, 0.75);
            }
        </style>
    </head>
    <body>
    <div class="deny-card">
        <div class="deny-icon">🔒</div>
        <div class="deny-title">Accés restringit</div>
        <div class="deny-text">
            No disposes de permisos per consultar les factures de clients amb l’usuari actual.
        </div>
        <div class="deny-small">
            Si creus que és un error, posa’t en contacte amb l’administrador del sistema perquè revisi els teus permisos.
        </div>
        <div class="deny-actions">
            <a href="index.php" class="btn-soft">Tornar a l’inici</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// Carregar factures (usem import_total de factures)
$sql = "
    SELECT f.id,
           f.num_factura,
           f.data_factura,
           COALESCE(c.nom, '') AS client_nom,
           COALESCE(f.estat_portal, '') AS estat_portal,
           COALESCE(f.import_total, 0) AS import_total
    FROM factures f
    LEFT JOIN clients c ON c.id = f.client_id
    ORDER BY f.data_factura DESC, f.id DESC
";
$stmt = $pdo->query($sql);
$factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total global
$totalGlobal = 0;
foreach ($factures as $f) {
    $totalGlobal += (float)$f['import_total'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Factures a clients</title>
    <style>
        :root {
            --bg: #020617;
            --panel-bg: #020617;
            --card-bg: rgba(15,23,42,0.96);
            --border-soft: rgba(148,163,184,0.4);
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;
            --accent: #22c55e;
            --accent-soft: rgba(34,197,94,0.20);
            --btn-new-bg: #22c55e;
            --btn-new-border: #bbf7d0;
            --btn-back-border: rgba(148,163,184,0.8);
            --row-even: rgba(15,23,42,0.96);
            --row-odd: rgba(17,24,39,0.98);
            --row-hover: rgba(34,197,94,0.35);

            --tag-ok-bg: #14532d;
            --tag-ok-border: #22c55e;
            --tag-ok-text: #bbf7d0;

            --tag-pending-bg: #451a03;
            --tag-pending-border: #facc15;
            --tag-pending-text: #fef9c3;

            --tag-error-bg: #450a0a;
            --tag-error-border: #ef4444;
            --tag-error-text: #fecaca;

            --danger: #fb7185;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        html, body {
            height: 100%;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgba(34,197,94,0.22) 0, rgba(15,23,42,1) 40%, #000 100%);
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
            padding: 18px 22px 18px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(31,41,55,0.9);
        }

        .title-block h1 {
            font-size: 1.25rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
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
            border: 1px solid rgba(148,163,184,0.7);
            font-size: 0.7rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--text-soft);
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
            background: radial-gradient(circle at top left, rgba(34,197,94,0.20), rgba(15,23,42,0.98));
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            box-shadow:
                0 22px 50px rgba(15,23,42,0.75),
                0 0 0 1px rgba(21,128,61,0.5);
            padding: 12px 14px 10px;
            overflow: hidden;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 10px;
            margin-bottom: 6px;
        }

        .card-header-left h2 {
            font-size: 0.95rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .card-header-left p {
            font-size: 0.8rem;
            color: var(--text-soft);
            margin-top: 2px;
        }

        .card-header-right {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background-color: var(--accent-soft);
            border: 1px solid rgba(34,197,94,0.7);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #bbf7d0;
        }

        .pill span.icon {
            font-size: 0.95rem;
        }

        .actions-header {
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
            transition:
                transform 0.12s ease,
                box-shadow 0.12s ease,
                background-color 0.12s ease,
                border-color 0.12s ease,
                color 0.12s ease;
            white-space: nowrap;
        }

        .btn span.icon {
            margin-right: 6px;
            font-size: 0.9rem;
        }

        .btn-new {
            background: radial-gradient(circle at top left, #bbf7d0, #22c55e);
            border-color: var(--btn-new-border);
            color: #022c22;
            box-shadow: 0 12px 26px rgba(34,197,94,0.6);
        }

        .btn-new:hover {
            background: radial-gradient(circle at top left, #bbf7d0, #16a34a);
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(22,163,74,0.8);
        }

        .btn-new.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            box-shadow: none;
        }

        .table-shell {
            flex: 1;
            margin-top: 6px;
            border-radius: 14px;
            border: 1px solid rgba(55,65,81,0.9);
            background: linear-gradient(to bottom, rgba(15,23,42,0.96), rgba(15,23,42,0.99));
            display: flex;
            flex-direction: column;
            overflow: hidden;
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
            background: linear-gradient(to right, rgba(34,197,94,0.26), rgba(74,222,128,0.32));
            backdrop-filter: blur(10px);
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

        tbody tr:nth-child(even) {
            background-color: var(--row-even);
        }

        tbody tr:nth-child(odd) {
            background-color: var(--row-odd);
        }

        tbody tr:hover {
            background-color: var(--row-hover);
        }

        td {
            border-bottom: 1px solid rgba(31,41,55,0.9);
            color: var(--text-main);
        }

        .col-id {
            width: 70px;
            font-size: 0.78rem;
            color: var(--text-soft);
        }

        .col-num {
            width: 130px;
        }

        .col-data {
            width: 120px;
            white-space: nowrap;
        }

        .col-total {
            width: 120px;
            text-align: right;
            white-space: nowrap;
        }

        .col-estat {
            width: 150px;
        }

        .col-actions {
            width: 260px;
            text-align: right;
        }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            background-color: rgba(22,163,74,0.18);
            color: #bbf7d0;
            border: 1px solid rgba(74,222,128,0.85);
        }

        .small {
            font-size: 0.8rem;
            color: var(--text-soft);
        }

        .price {
            font-variant-numeric: tabular-nums;
        }

        .actions-group {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            justify-content: flex-end;
        }

        .chip-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-decoration: none;
            cursor: pointer;
            transition:
                background-color 0.12s ease,
                border-color 0.12s ease,
                transform 0.12s ease,
                box-shadow 0.12s ease;
            white-space: nowrap;
        }

        .chip-btn span.icon {
            margin-right: 4px;
            font-size: 0.9rem;
        }

        .chip-edit {
            background-color: rgba(59,130,246,0.18);
            border-color: #60a5fa;
            color: #bfdbfe;
            box-shadow: 0 6px 14px rgba(37,99,235,0.35);
        }

        .chip-pdf {
            background-color: rgba(45,212,191,0.20);
            border-color: #14b8a6;
            color: #a5f3fc;
            box-shadow: 0 6px 14px rgba(45,212,191,0.35);
        }

        .chip-mail {
            background-color: rgba(248,113,113,0.20);
            border-color: #f97373;
            color: #fecaca;
            box-shadow: 0 6px 14px rgba(220,38,38,0.35);
        }

        .chip-delete {
            background-color: rgba(248,113,113,0.16);
            border-color: #f97373;
            color: #fecaca;
            box-shadow: 0 6px 14px rgba(220,38,38,0.35);
        }

        .chip-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15,23,42,0.7);
        }

        .chip-edit:hover {
            background-color: rgba(59,130,246,0.3);
        }

        .chip-pdf:hover {
            background-color: rgba(45,212,191,0.32);
        }

        .chip-mail:hover {
            background-color: rgba(248,113,113,0.32);
        }

        .chip-delete:hover {
            background-color: rgba(248,113,113,0.3);
        }

        .chip-disabled {
            opacity: 0.35;
            cursor: not-allowed;
            box-shadow: none;
        }

        .chip-disabled:hover {
            transform: none;
        }

        .tag-estat {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            border: 1px solid transparent;
        }

        .tag-ok {
            background-color: var(--tag-ok-bg);
            border-color: var(--tag-ok-border);
            color: var(--tag-ok-text);
        }

        .tag-pending {
            background-color: var(--tag-pending-bg);
            border-color: var(--tag-pending-border);
            color: var(--tag-pending-text);
        }

        .tag-error {
            background-color: var(--tag-error-bg);
            border-color: var(--tag-error-border);
            color: var(--tag-error-text);
        }

        .empty {
            padding: 18px 12px;
            font-size: 0.9rem;
            color: var(--text-soft);
            text-align: center;
        }

        .card-footer {
            padding: 6px 4px 2px;
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--text-soft);
        }

        .hint-perms {
            font-size: 0.75rem;
            color: var(--danger);
            margin-top: 4px;
            text-align: right;
        }

        /* MODAL ESBORRAR */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at top, rgba(15,23,42,0.9), rgba(0,0,0,0.95));
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 40;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-card {
            max-width: 460px;
            width: 92%;
            border-radius: 20px;
            padding: 18px 18px 14px;
            background: linear-gradient(135deg, rgba(15,23,42,0.96), rgba(30,64,175,0.95));
            border: 1px solid rgba(148,163,184,0.7);
            box-shadow:
                0 26px 60px rgba(15,23,42,0.95),
                0 0 0 1px rgba(37,99,235,0.6);
            color: #e5e7eb;
            backdrop-filter: blur(18px);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
        }

        .modal-title {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.18em;
        }

        .modal-badge {
            font-size: 0.7rem;
            padding: 3px 10px;
            border-radius: 999px;
            border: 1px solid rgba(248,113,113,0.9);
            background: rgba(248,113,113,0.12);
            color: #fecaca;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .modal-body {
            font-size: 0.85rem;
            color: #e5e7eb;
        }

        .modal-body p {
            margin-bottom: 8px;
        }

        .modal-warning {
            font-size: 0.78rem;
            color: #fecaca;
            background: rgba(248,113,113,0.12);
            border-radius: 999px;
            padding: 4px 9px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(248,113,113,0.7);
            margin-bottom: 8px;
        }

        .modal-warning span.icon {
            font-size: 0.95rem;
        }

        .modal-detail-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }

        .modal-detail-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: #9ca3af;
        }

        .modal-detail-value {
            font-size: 0.85rem;
            font-weight: 500;
        }

        .modal-detail-strong {
            font-weight: 600;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-cancel {
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            border: 1px solid rgba(148,163,184,0.9);
            background: rgba(15,23,42,0.95);
            color: #e5e7eb;
            cursor: pointer;
        }

        .btn-confirm-delete {
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            border: 1px solid rgba(248,113,113,0.9);
            background: radial-gradient(circle at top left, #fecaca, #f97373);
            color: #450a0a;
            cursor: pointer;
            box-shadow: 0 16px 32px rgba(248,113,113,0.7);
        }

        .btn-confirm-delete:hover {
            background: radial-gradient(circle at top left, #fee2e2, #f97373);
        }

        @media (max-width: 780px) {
            .panel {
                padding: 14px 10px;
            }
            .card {
                border-radius: 0;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            .actions-header {
                width: 100%;
                justify-content: flex-start;
            }
            .col-id {
                display: none;
            }
        }
    </style>
    <script>
        let deleteForm = null;

        function openDeleteModal(button, facturaId, num, data, client, importTotal) {
            deleteForm = button.closest('form');

            document.getElementById('del_num').textContent = num;
            document.getElementById('del_data').textContent = data;
            document.getElementById('del_client').textContent = client;
            document.getElementById('del_import').textContent = importTotal;

            const hiddenId = document.getElementById('del_factura_id');
            hiddenId.value = facturaId;

            document.getElementById('deleteModal').classList.add('show');
            return false;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            deleteForm = null;
        }

        function confirmDelete() {
            if (deleteForm) {
                deleteForm.submit();
            }
        }
    </script>
</head>
<body>
<div class="shell">
    <div class="panel">
        <div class="header">
            <div class="title-block">
                <h1>Factures clients</h1>
                <p>Capçaleres de factura vinculades a clients.</p>
            </div>
            <div class="badge">
                <span class="badge-dot"></span>
                VENDES · FACTURES
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <h2>Llista de factures</h2>
                    <p>Consulta números, dates, clients, estat al portal i imports totals.</p>
                </div>
                <div class="card-header-right">
                    <div class="pill">
                        <span class="icon">💶</span>
                        Facturació clients
                    </div>
                    <div class="actions-header">
                        <?php if (user_can_editar_factures_clients($pdo)): ?>
                            <a href="factures_form.php" class="btn btn-new">
                                <span class="icon">＋</span> Nova factura
                            </a>
                        <?php else: ?>
                            <div>
                                <button class="btn btn-new disabled" type="button" disabled>
                                    <span class="icon">＋</span> Nova factura
                                </button>
                                <div class="hint-perms">
                                    Sense permisos per crear/editar factures (només lectura).
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="table-shell">
                <div class="table-container">
                    <table>
                        <thead>
                        <tr>
                            <th class="col-id">ID</th>
                            <th class="col-num">Núm. factura</th>
                            <th class="col-data">Data</th>
                            <th>Client</th>
                            <th class="col-estat">Estat portal</th>
                            <th class="col-total">Import</th>
                            <th class="col-actions">Accions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($factures)): ?>
                            <tr>
                                <td colspan="7" class="empty">
                                    Encara no hi ha cap factura. Fes clic a «Nova factura» o «Facturar albarans» per crear-ne una.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($factures as $f): ?>
                                <?php
                                $estat = strtolower(trim($f['estat_portal'] ?? ''));
                                $classe = 'tag-pending';
                                if (in_array($estat, ['acceptada', 'acceptat', 'acceptat portal'])) {
                                    $classe = 'tag-ok';
                                } elseif (in_array($estat, ['rebutjada', 'error', 'rebutjat'])) {
                                    $classe = 'tag-error';
                                }

                                $dataFormat = $f['data_factura']
                                    ? (new DateTime($f['data_factura']))->format('d/m/Y')
                                    : '';

                                $importFormat = number_format((float)$f['import_total'], 2, ',', '.').' €';
                                ?>
                                <tr>
                                    <td class="col-id">
                                        <span class="tag">#<?= htmlspecialchars($f['id']) ?></span>
                                    </td>
                                    <td class="col-num">
                                        <?= htmlspecialchars($f['num_factura']) ?>
                                    </td>
                                    <td class="col-data">
                                        <?= htmlspecialchars($dataFormat) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($f['client_nom']) ?>
                                    </td>
                                    <td class="col-estat">
                                        <span class="tag-estat <?= $classe ?>">
                                            <?= $estat !== '' ? htmlspecialchars($f['estat_portal']) : '—' ?>
                                        </span>
                                    </td>
                                    <td class="col-total">
                                        <span class="price">
                                            <?= htmlspecialchars($importFormat) ?>
                                        </span>
                                    </td>
                                    <td class="col-actions">
                                        <div class="actions-group">
                                            <?php if (user_can_editar_factures_clients($pdo)): ?>
                                                <a class="chip-btn chip-edit"
                                                   href="factures_form.php?id=<?= (int)$f['id'] ?>">
                                                    <span class="icon">✏️</span> Editar
                                                </a>
                                            <?php else: ?>
                                                <button class="chip-btn chip-edit chip-disabled" type="button" disabled>
                                                    <span class="icon">✏️</span> Editar
                                                </button>
                                            <?php endif; ?>

                                            <a class="chip-btn chip-pdf"
                                               href="factura_pdf.php?factura_id=<?= (int)$f['id'] ?>"
                                               target="_blank">
                                                <span class="icon">📄</span> PDF
                                            </a>
                                            <a class="chip-btn chip-mail"
                                               href="factura_sendmail.php?factura_id=<?= (int)$f['id'] ?>">
                                                <span class="icon">✉️</span> Mail
                                            </a>

                                            <?php if (user_can_editar_factures_clients($pdo)): ?>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="accio" value="delete">
                                                    <input type="hidden" name="factura_id" value="<?= (int)$f['id'] ?>">
                                                    <button type="button"
                                                            class="chip-btn chip-delete"
                                                            onclick="return openDeleteModal(this,
                                                                '<?= (int)$f['id'] ?>',
                                                                '<?= htmlspecialchars($f['num_factura'], ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($dataFormat, ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($f['client_nom'], ENT_QUOTES) ?>',
                                                                '<?= htmlspecialchars($importFormat, ENT_QUOTES) ?>'
                                                            );">
                                                        <span class="icon">🗑️</span> Esborrar
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="5" style="text-align:right; padding:10px 10px; font-weight:600;">
                                    Total facturat:
                                </td>
                                <td class="col-total" style="font-weight:600;">
                                    <?= htmlspecialchars(number_format($totalGlobal, 2, ',', '.')) ?> €
                                </td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <span><?= count($factures) ?> factura(es) trobades.</span>
                    <?php if (!empty($factures)): ?>
                        <span>Import total: <?= htmlspecialchars(number_format($totalGlobal, 2, ',', '.')) ?> €</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL CONFIRMACIÓ ESBORRAT -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Confirmar esborrat</div>
            <div class="modal-badge">Acció definitiva</div>
        </div>
        <div class="modal-body">
            <div class="modal-warning">
                <span class="icon">⚠️</span>
                Aquesta acció eliminarà la factura i alliberarà els albarans associats.
            </div>
            <p>Estàs a punt d’esborrar la següent factura:</p>
            <div class="modal-detail-row">
                <span class="modal-detail-label">Factura</span>
                <span class="modal-detail-value modal-detail-strong" id="del_num"></span>
            </div>
            <div class="modal-detail-row">
                <span class="modal-detail-label">Data</span>
                <span class="modal-detail-value" id="del_data"></span>
            </div>
            <div class="modal-detail-row">
                <span class="modal-detail-label">Client</span>
                <span class="modal-detail-value" id="del_client"></span>
            </div>
            <div class="modal-detail-row">
                <span class="modal-detail-label">Import</span>
                <span class="modal-detail-value modal-detail-strong" id="del_import"></span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel·lar</button>
            <button type="button" class="btn-confirm-delete" onclick="confirmDelete()">Esborrar definitivament</button>
        </div>
        <!-- hidden per assegurar l'id si calgués -->
        <input type="hidden" id="del_factura_id" value="">
    </div>
</div>
</body>
</html>

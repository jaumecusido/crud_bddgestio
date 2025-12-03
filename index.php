<?php
session_start();

require_once 'db.php';
$pdo = getPDO();

// Carregar dades d'empresa (si existeixen)
$empresaNom = '';
$empresaNif = '';
try {
    $stmtEmpresa = $pdo->query("SELECT nom, nif FROM empresa_params ORDER BY id ASC LIMIT 1");
    if ($rowEmp = $stmtEmpresa->fetch(PDO::FETCH_ASSOC)) {
        $empresaNom = $rowEmp['nom'] ?? '';
        $empresaNif = $rowEmp['nif'] ?? '';
    }
} catch (PDOException $e) {
    // si la taula encara no existeix, ignorem l'error discretament
}

// Mida de la base de dades (format llegible)
$dbSizePretty = '';
try {
    $stmtSize = $pdo->query("
        SELECT pg_size_pretty(pg_database_size(current_database())) AS size
    ");
    if ($rowSize = $stmtSize->fetch(PDO::FETCH_ASSOC)) {
        $dbSizePretty = $rowSize['size'] ?? '';
    }
} catch (PDOException $e) {
    $dbSizePretty = '';
}

// --- CREACIÓ TAULES BÀSIQUES ---

$sqlProveidors = "
CREATE TABLE IF NOT EXISTS proveidors (
    id          SERIAL PRIMARY KEY,
    nom         VARCHAR(100) NOT NULL,
    nif         VARCHAR(20),
    adreca      VARCHAR(255),
    telefon     VARCHAR(50),
    email       VARCHAR(100)
);
";

$sql = "
CREATE TABLE IF NOT EXISTS albarans (
    id              SERIAL PRIMARY KEY,
    num_albara      INTEGER      NOT NULL UNIQUE,
    data_albara     DATE         NOT NULL,
    client_id       INTEGER      NOT NULL,
    adreca_entrega  VARCHAR(255) NOT NULL,
    observacions    VARCHAR(255),
    CONSTRAINT fk_alb_client
        FOREIGN KEY (client_id)
        REFERENCES clients(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE SEQUENCE IF NOT EXISTS albarans_num_albara_seq;

ALTER TABLE albarans
    ALTER COLUMN num_albara
    SET DEFAULT nextval('albarans_num_albara_seq');

CREATE TABLE IF NOT EXISTS albara_linies (
    id              SERIAL PRIMARY KEY,
    albara_id       INTEGER      NOT NULL,
    num_linia       INTEGER      NOT NULL,
    article_id      INTEGER      NOT NULL,
    codi_article    VARCHAR(50)  NOT NULL,
    descripcio      VARCHAR(255) NOT NULL,
    preu_unitari    NUMERIC(10,2) NOT NULL,
    quantitat       NUMERIC(10,2) NOT NULL,
    num_factura     INTEGER,
    CONSTRAINT fk_lin_albara
        FOREIGN KEY (albara_id)
        REFERENCES albarans(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_lin_article
        FOREIGN KEY (article_id)
        REFERENCES articles(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);
";

$pdo->exec($sql);
$pdo->exec($sqlProveidors);

// Taula d'usuaris
$sqlUsers = "
CREATE TABLE IF NOT EXISTS usuaris (
    id              SERIAL PRIMARY KEY,
    usuari          VARCHAR(50)  NOT NULL UNIQUE,
    contrasenya     VARCHAR(255) NOT NULL,
    nom_complet     VARCHAR(100) NOT NULL,
    email           VARCHAR(100) NOT NULL,
    permis_mestres  SMALLINT     NOT NULL DEFAULT 0,
    permis_gestio   SMALLINT     NOT NULL DEFAULT 0,
    actiu           BOOLEAN      NOT NULL DEFAULT TRUE
);
";
$pdo->exec($sqlUsers);

// --- USUARI DEMO SI NO N'HI HA CAP ---
$stmtCountUsers = $pdo->query("SELECT COUNT(*) FROM usuaris");
if ((int)$stmtCountUsers->fetchColumn() === 0) {
    $hashAdmin = password_hash('admin123', PASSWORD_DEFAULT);
    $stmtInsUser = $pdo->prepare("
        INSERT INTO usuaris (usuari, contrasenya, nom_complet, email, permis_mestres, permis_gestio, actiu)
        VALUES (:usuari, :contrasenya, :nom_complet, :email, :permis_mestres, :permis_gestio, TRUE)
    ");
    $stmtInsUser->execute([
        'usuari'         => 'admin',
        'contrasenya'    => $hashAdmin,
        'nom_complet'    => 'Administrador',
        'email'          => 'admin@demo.local',
        'permis_mestres' => 2,
        'permis_gestio'  => 2,
    ]);
}

// --- GESTIÓ LOGIN / LOGOUT ---

$loginError = '';

// logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuari'], $_POST['contrasenya'])) {
    $usuariForm = trim($_POST['usuari']);
    $passForm   = (string)$_POST['contrasenya'];

    $stmtUser = $pdo->prepare("SELECT * FROM usuaris WHERE usuari = :u AND actiu = TRUE");
    $stmtUser->execute(['u' => $usuariForm]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($passForm, $user['contrasenya'])) {
        $_SESSION['usuari_id']   = $user['id'];
        $_SESSION['usuari_nom']  = $user['nom_complet'];
        $_SESSION['usuari_user'] = $user['usuari'];
        header('Location: index.php');
        exit;
    } else {
        $loginError = 'Usuari o contrasenya incorrectes.';
    }
}

// comprovar si hi ha sessió
$loggedIn = isset($_SESSION['usuari_id']);

// COMPTADORS PER ALS BOTONS (només si loguejat)
function countTable(PDO $pdo, string $table): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
    return (int)$stmt->fetchColumn();
}

$totalAlbaransPendents       = 0;
$totalFacturesPendentsB2B    = 0;

if ($loggedIn) {
    $totalArticles        = countTable($pdo, 'articles');
    $totalClients         = countTable($pdo, 'clients');
    $totalProveidors      = countTable($pdo, 'proveidors');
    $totalAlbarans        = countTable($pdo, 'albarans');
    $totalFacClients      = countTable($pdo, 'factures');
    $totalFacProveidors   = countTable($pdo, 'factures_proveidors');

    // Comptar albarans pendents de facturar
    $sqlPendents = "
        SELECT COUNT(*)
        FROM albarans a
        WHERE NOT EXISTS (
            SELECT 1
            FROM factura_albarans fa
            WHERE fa.albara_id = a.id
        )
    ";
    $stmtPend = $pdo->query($sqlPendents);
    $totalAlbaransPendents = (int)$stmtPend->fetchColumn();

    // Comptar factures pendents d'enviar a B2Brouter
    $sqlFacPend = "
        SELECT COUNT(*)
        FROM factures
        WHERE COALESCE(enviadaportal, 0) = 0
    ";
    $stmtFacPend = $pdo->query($sqlFacPend);
    $totalFacturesPendentsB2B = (int)$stmtFacPend->fetchColumn();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gestió - CRUD</title>
    <style>
        :root {
            --bg: #020617;
            --bg-accent: #0b1120;
            --sidebar-bg: #0f172a;
            --sidebar-border: #1f2937;
            --text-main: #e5e7eb;
            --text-soft: #9ca3af;

            --chip-mestres-border: #60a5fa;
            --chip-gestio-border: #d946ef;
            --chip-informes-border: #22c55e;

            --btn-clients-border: #60a5fa;
            --btn-articles-border: #f87171;
            --btn-proveidors-border: #fbbf24;
            --btn-albarans-border: #8b5cf6;
            --btn-faccli-border: #22c55e;
            --btn-facprov-border: #ef4444;
            --btn-facturar-alb-border: #06b6d4;

            --btn-inf-alb-border: #4f46e5;
            --btn-inf-fac-border: #0ea5e9;
            --btn-inf-estat-border: #22c55e;

            --badge-pendent-bg: #0ea5e9;
            --badge-pendent-border: #e0f2fe;
            --badge-pendent-text: #0f172a;
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
            display: flex;
            background:
                radial-gradient(circle at top left, rgba(56,189,248,0.25), transparent 55%),
                radial-gradient(circle at bottom right, rgba(34,197,94,0.20), transparent 55%),
                radial-gradient(circle at top, #020617 0, var(--bg) 52%, #020617 100%);
            color: var(--text-main);
        }

        .layout {
            display: grid;
            grid-template-columns: 270px 1fr; /* lleugerament més ample */
            width: 100%;
            height: 100vh;
        }

        .sidebar {
            background: linear-gradient(to bottom, #020617, #020617, #020617);
            border-right: 1px solid var(--sidebar-border);
            color: #e5e7eb;
            padding: 20px 18px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .sidebar-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .sidebar-logo {
            width: 150px;
            max-width: 80%;
            height: auto;
            display: block;
            filter: drop-shadow(0 10px 25px rgba(15,23,42,0.9));
        }

        .sidebar-title {
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            margin-bottom: 2px;
            text-align: center;
        }

        .sidebar-subtitle {
            font-size: 0.8rem;
            color: #9ca3af;
            text-align: center;
        }

        .sidebar-section {
            margin-top: 10px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .section-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: #9ca3af;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid transparent;
        }

        .chip-mestres {
            background-color: rgba(96,165,250,0.16);
            border-color: var(--chip-mestres-border);
            color: #bfdbfe;
        }

        .chip-gestio {
            background-color: rgba(217,70,239,0.16);
            border-color: var(--chip-gestio-border);
            color: #f9a8d4;
        }

        .chip-informes {
            background-color: rgba(34,197,94,0.16);
            border-color: var(--chip-informes-border);
            color: #bbf7d0;
        }

        .btn-col {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 4px;
        }

        .menu-btn {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 12px; /* una mica més d’ample interior */
            border-radius: 999px;
            border: 1px solid transparent;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            cursor: pointer;
            transition:
                transform 0.12s ease,
                box-shadow 0.12s ease,
                background-color 0.12s ease,
                border-color 0.12s ease,
                color 0.12s ease;
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.9);
            color: #e5e7eb;
            background-color: rgba(15,23,42,0.82);
        }

        .menu-btn span.label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap; /* clau perquè no faci salt de línia [web:360][web:363] */
        }

        .menu-btn span.icon {
            font-size: 1.0rem;
        }

        .menu-btn span.count {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .btn-articles      { border-color: var(--btn-articles-border); }
        .btn-clients       { border-color: var(--btn-clients-border); }
        .btn-proveidors    { border-color: var(--btn-proveidors-border); }
        .btn-albarans      { border-color: var(--btn-albarans-border); }
        .btn-faccli        { border-color: var(--btn-faccli-border); }
        .btn-facprov       { border-color: var(--btn-facprov-border); }
        .btn-facturar-alb  { border-color: var(--btn-facturar-alb-border); }

        .btn-inf-alb       { border-color: var(--btn-inf-alb-border); }
        .btn-inf-fac       { border-color: var(--btn-inf-fac-border); }
        .btn-inf-estat     { border-color: var(--btn-inf-estat-border); }

        .menu-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.95);
            background-color: rgba(30,64,175,0.7);
        }

        .sidebar-footer {
            margin-top: auto;
            font-size: 0.75rem;
            color: #6b7280;
            text-align: center;
        }

        .sidebar-footer strong {
            color: #e5e7eb;
        }

        .content {
            height: 100vh;
            width: 100%;
        }

        .content-frame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        @media (max-width: 800px) {
            .layout {
                grid-template-columns: 230px 1fr;
            }
        }

        @media (max-width: 640px) {
            body {
                display: block;
            }
            .layout {
                display: block;
                height: auto;
            }
            .sidebar {
                width: 100%;
                height: auto;
            }
            .content {
                height: calc(100vh - 260px);
            }
            .content-frame {
                height: 100%;
            }
        }

        /* LOGIN NOU */
        .login-shell {
            position: relative;
            margin: auto;
            max-width: 420px;
            background: radial-gradient(circle at top left, rgba(59,130,246,0.28), rgba(15,23,42,0.98));
            border-radius: 22px;
            box-shadow:
                0 24px 80px rgba(15,23,42,0.95),
                0 0 0 1px rgba(148,163,184,0.4);
            padding: 26px 24px 22px;
            text-align: center;
            color: #e5e7eb;
            overflow: hidden;
        }
        .login-shell::before {
            content: "";
            position: absolute;
            inset: -1px;
            border-radius: 22px;
            background:
                linear-gradient(120deg,
                    rgba(96,165,250,0.80),
                    rgba(45,212,191,0.80),
                    rgba(251,191,36,0.80));
            opacity: 0.18;
            mix-blend-mode: screen;
            pointer-events: none;
        }
        .login-inner {
            position: relative;
            z-index: 1;
        }
        .login-badge {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.20em;
            color: #a5b4fc;
            margin-bottom: 10px;
        }
        .login-company {
            font-size: 0.95rem;
            margin-bottom: 4px;
        }
        .login-company strong {
            font-size: 1.02rem;
        }
        .login-company span {
            color: #9ca3af;
            font-size: 0.8rem;
        }
        .login-shell h1 {
            font-size: 1.25rem;
            margin-bottom: 4px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .login-shell p {
            font-size: 0.85rem;
            color: var(--text-soft);
            margin-bottom: 16px;
        }
        .login-shell form {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 4px;
        }
        .login-shell input[type="text"],
        .login-shell input[type="password"] {
            padding: 9px 12px;
            border-radius: 999px;
            border: 1px solid rgba(148,163,184,0.65);
            font-size: 0.9rem;
            background: rgba(15,23,42,0.92);
            color: #e5e7eb;
        }
        .login-shell input::placeholder {
            color: #6b7280;
        }
        .login-shell button {
            margin-top: 8px;
            padding: 9px 12px;
            border-radius: 999px;
            border: none;
            background: linear-gradient(to right, #22c55e, #4ade80);
            color: #022c22;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            cursor: pointer;
            box-shadow: 0 14px 30px rgba(22,163,74,0.85);
        }
        .login-shell button:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 40px rgba(22,163,74,1);
        }
        .login-error {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #fecaca;
            background: rgba(239,68,68,0.16);
            border-radius: 999px;
            padding: 6px 10px;
            border: 1px solid rgba(239,68,68,0.6);
        }
        .login-hint {
            margin-top: 10px;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        .login-divider {
            margin-top: 14px;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.22em;
            color: #6b7280;
        }

        /* Badge pendents */
        .badge-pendents {
            position: absolute;
            top: -6px;
            right: -4px;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background-color: var(--badge-pendent-bg);
            border: 1px solid var(--badge-pendent-border);
            color: var(--badge-pendent-text);
            font-size: 0.7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 14px rgba(14,165,233,0.8);
        }
        .badge-pendents span {
            line-height: 1;
        }
    </style>
    <script>
        function loadPage(page) {
            const frame = document.getElementById('mainFrame');
            if (frame) {
                frame.src = page;
            }
        }
    </script>
</head>
<body>
<?php if (!$loggedIn): ?>
    <!-- Pantalla de LOGIN -->
    <div style="width:100%; display:flex; min-height:100vh; align-items:center; justify-content:center;">
        <div class="login-shell">
            <div class="login-inner">
                <div class="login-badge">BddGestio · Accés segur</div>
                <?php if ($empresaNom !== ''): ?>
                    <div class="login-company">
                        <strong><?= htmlspecialchars($empresaNom) ?></strong><br>
                        <?php if ($empresaNif !== ''): ?>
                            <span>NIF: <?= htmlspecialchars($empresaNif) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <h1>Inici de sessió</h1>
                <p>Introdueix les teves credencials per accedir al panell de gestió.</p>
                <form method="post" action="index.php">
                    <input type="text" name="usuari" placeholder="Usuari" required>
                    <input type="password" name="contrasenya" placeholder="Contrasenya" required>
                    <button type="submit">Entrar</button>
                </form>
                <?php if ($loginError): ?>
                    <div class="login-error"><?= htmlspecialchars($loginError) ?></div>
                <?php endif; ?>
                <div class="login-divider">Mode demostració</div>
                <div class="login-hint">
                    Usuari: <strong>admin</strong> · Contrasenya: <strong>admin123</strong>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
<div class="layout">
    <!-- MENÚ LATERAL -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <img src="img/logo.png" alt="Logo" class="sidebar-logo">
            <div>
                <div class="sidebar-title">Gestió BddGestio</div>
                <div class="sidebar-subtitle">
                    Benvingut/da, <?= htmlspecialchars($_SESSION['usuari_nom'] ?? $_SESSION['usuari_user']) ?> ·
                    <a href="index.php?logout=1" style="color:#93c5fd; text-decoration:underline; font-size:0.8rem;">Sortir</a>
                </div>
            </div>
        </div>

        <!-- Bloc Mestres -->
        <div class="sidebar-section">
            <div class="section-header">
                <span class="section-title">Mestres</span>
                <span class="chip chip-mestres">Base</span>
            </div>
            <div class="btn-col">
                <button type="button"
                        class="menu-btn btn-articles"
                        onclick="loadPage('articles_list.php')">
                    <span class="label">
                        <span class="icon">📦</span>
                        Articles
                    </span>
                    <span class="count"><?= $totalArticles ?></span>
                </button>

                <button type="button"
                        class="menu-btn btn-clients"
                        onclick="loadPage('clients_list.php')">
                    <span class="label">
                        <span class="icon">👥</span>
                        Clients
                    </span>
                    <span class="count"><?= $totalClients ?></span>
                </button>

                <button type="button"
                        class="menu-btn btn-proveidors"
                        onclick="loadPage('proveidors_list.php')">
                    <span class="label">
                        <span class="icon">🏢</span>
                        Proveïdors
                    </span>
                    <span class="count"><?= $totalProveidors ?></span>
                </button>
            </div>
        </div>

        <!-- Bloc Gestió -->
        <div class="sidebar-section">
            <div class="section-header">
                <span class="section-title">Gestió</span>
                <span class="chip chip-gestio">Diari</span>
            </div>
            <div class="btn-col">
                <button type="button"
                        class="menu-btn btn-albarans"
                        onclick="loadPage('albarans_list.php')">
                    <span class="label">
                        <span class="icon">📄</span>
                        Albarans
                    </span>
                    <span class="count"><?= $totalAlbarans ?></span>
                </button>

                <button type="button"
                        class="menu-btn btn-facturar-alb"
                        onclick="loadPage('factures_from_albarans.php')">
                    <span class="label">
                        <span class="icon">🧾</span>
                        Facturar albarans
                    </span>
                    <span>↗</span>
                    <?php if ($totalAlbaransPendents > 0): ?>
                        <div class="badge-pendents" title="Albarans pendents de facturar">
                            <span><?= $totalAlbaransPendents ?></span>
                        </div>
                    <?php endif; ?>
                </button>

                <button type="button"
                        class="menu-btn btn-faccli"
                        onclick="loadPage('factures_clients_list.php')">
                    <span class="label">
                        <span class="icon">🧾</span>
                        Factures clients
                    </span>
                    <span class="count"><?= $totalFacClients ?></span>
                    <?php if ($totalFacturesPendentsB2B > 0): ?>
                        <div class="badge-pendents" title="Factures pendents d'enviar al portal B2Brouter">
                            <span><?= $totalFacturesPendentsB2B ?></span>
                        </div>
                    <?php endif; ?>
                </button>

                <button type="button"
                        class="menu-btn btn-facprov"
                        onclick="loadPage('factures_proveidors_list.php')">
                    <span class="label">
                        <span class="icon">📑</span>
                        Factures proveïdors
                    </span>
                    <span class="count"><?= $totalFacProveidors ?></span>
                </button>
            </div>
        </div>

        <!-- Bloc Informes -->
        <div class="sidebar-section">
            <div class="section-header">
                <span class="section-title">Informes</span>
                <span class="chip chip-informes">Vista</span>
            </div>
            <div class="btn-col">
                <button type="button"
                        class="menu-btn btn-inf-alb"
                        onclick="loadPage('informes_albarans_pdf.php')">
                    <span class="label">
                        <span class="icon">📄</span>
                        PDF Albarans
                    </span>
                    <span>↗</span>
                </button>

                <button type="button"
                        class="menu-btn btn-inf-fac"
                        onclick="loadPage('informes_factures_pdf.php')">
                    <span class="label">
                        <span class="icon">🧾</span>
                        PDF Factures Clients
                    </span>
                    <span>↗</span>
                </button>

                <button type="button"
                        class="menu-btn btn-inf-estat"
                        onclick="loadPage('informes_estadistica_facturacio.php')">
                    <span class="label">
                        <span class="icon">📊</span>
                        Gràfics facturació
                    </span>
                    <span>↗</span>
                </button>
                <button type="button"
                        class="menu-btn btn-inf-estat"
                        onclick="loadPage('informe_iva.php')">
                    <span class="label">
                        <span class="icon">📊</span>
                        Informe Trimestral
                    </span>
                    <span>↗</span>
                </button>
            </div>
        </div>

        <!-- Bloc Utilitats -->
        <div class="sidebar-section">
            <div class="section-header">
                <span class="section-title">Utilitats</span>
                <span class="chip chip-mestres">Setup</span>
            </div>
            <div class="btn-col">
                <button type="button"
                        class="menu-btn btn-clients"
                        onclick="loadPage('empresa_form.php')">
                    <span class="label">
                        <span class="icon">🏭</span>
                        Empresa
                    </span>
                    <span>⚙</span>
                </button>
            </div>
        </div>

        <div class="sidebar-footer">
            <?php if ($empresaNom !== ''): ?>
                <div style="margin-bottom:4px;">
                    <strong><?= htmlspecialchars($empresaNom) ?></strong>
                    <?php if ($empresaNif !== ''): ?>
                        <br><span style="color:#9ca3af;">NIF: <?= htmlspecialchars($empresaNif) ?></span>
                    <?php endif; ?>
                    <?php if ($dbSizePretty !== ''): ?>
                        <br><span style="color:#9ca3af;">Mida BD: <?= htmlspecialchars($dbSizePretty) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($empresaNom === '' && $dbSizePretty !== ''): ?>
                <div style="margin-bottom:4px;">
                    <span style="color:#9ca3af;">Mida BD: <?= htmlspecialchars($dbSizePretty) ?></span>
                </div>
            <?php endif; ?>
            <strong>CRUD PHP + PostgreSQL</strong><br>
            Mòduls mestres, gestió i informes
        </div>
    </nav>

    <!-- ÀREA de CONTINGUT -->
    <main class="content">
        <iframe id="mainFrame" class="content-frame" src="albarans_list.php"></iframe>
    </main>
</div>
<?php endif; ?>
</body>
</html>

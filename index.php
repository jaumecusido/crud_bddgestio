
<?php

require_once 'db.php';
$pdo = getPDO();

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

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gestió - CRUD</title>
    <style>
        :root {
            --bg: #f5f7fb;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-soft: #64748b;
            --border-soft: #e2e8f0;

            --chip-mestres-bg: #e0f2fe;
            --chip-mestres-border: #60a5fa;

            --btn-clients-bg: #bfdbfe;
            --btn-clients-border: #60a5fa;

            --btn-articles-bg: #fecaca;
            --btn-articles-border: #f87171;

            --btn-proveidors-bg: #fde68a;
            --btn-proveidors-border: #fbbf24;

            --chip-gestio-bg: #fae8ff;
            --chip-gestio-border: #d946ef;

            --btn-albarans-bg: #ddd6fe;
            --btn-albarans-border: #8b5cf6;

            --btn-faccli-bg: #bbf7d0;
            --btn-faccli-border: #22c55e;

            --btn-facprov-bg: #fee2e2;
            --btn-facprov-border: #ef4444;
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
            background: radial-gradient(circle at top, #e0f2fe 0, var(--bg) 45%, #fefce8 100%);
            color: var(--text-main);
        }

        .card {
            background: var(--card-bg);
            border-radius: 22px;
            padding: 28px 32px 26px;
            box-shadow:
                0 18px 45px rgba(15, 23, 42, 0.15),
                0 0 0 1px rgba(148, 163, 184, 0.25);
            max-width: 640px;
            width: 100%;
        }

        .title {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #111827;
            margin-bottom: 4px;
        }

        .subtitle {
            font-size: 0.95rem;
            color: var(--text-soft);
            margin-bottom: 18px;
        }

        .sections {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 22px;
        }

        .section {
            border-radius: 18px;
            padding: 14px 14px 12px;
            border: 1px solid var(--border-soft);
            background: linear-gradient(to bottom right, #f9fafb, #ffffff);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .section-title {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: #4b5563;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid transparent;
        }

        .chip-mestres {
            background-color: var(--chip-mestres-bg);
            border-color: var(--chip-mestres-border);
            color: #1d4ed8;
        }

        .chip-gestio {
            background-color: var(--chip-gestio-bg);
            border-color: var(--chip-gestio-border);
            color: #a21caf;
        }

        .btn-col {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 4px;
        }

        .menu-btn {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid transparent;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            cursor: pointer;
            transition:
                transform 0.12s ease,
                box-shadow 0.12s ease,
                background-color 0.12s ease,
                border-color 0.12s ease;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.12);
            color: #111827;
        }

        .menu-btn span.label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .menu-btn span.icon {
            font-size: 1.05rem;
        }

        .btn-articles {
            background-color: var(--btn-articles-bg);
            border-color: var(--btn-articles-border);
        }
        .btn-clients {
            background-color: var(--btn-clients-bg);
            border-color: var(--btn-clients-border);
        }

        .btn-proveidors {
            background-color: var(--btn-proveidors-bg);
            border-color: var(--btn-proveidors-border);
        }

        .btn-albarans {
            background-color: var(--btn-albarans-bg);
            border-color: var(--btn-albarans-border);
        }

        .btn-faccli {
            background-color: var(--btn-faccli-bg);
            border-color: var(--btn-faccli-border);
        }

        .btn-facprov {
            background-color: var(--btn-facprov-bg);
            border-color: var(--btn-facprov-border);
        }

        .menu-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.18);
        }

        .footer-note {
            margin-top: 18px;
            font-size: 0.8rem;
            color: var(--text-soft);
            text-align: right;
        }

        @media (max-width: 640px) {
            .card {
                margin: 16px;
                padding: 24px 20px 20px;
            }

            .sections {
                grid-template-columns: minmax(0, 1fr);
            }

            .title {
                font-size: 1.35rem;
            }
        }
    </style>
</head>
<body>
<div class="card">
    <h1 class="title">Gestió BddGestio</h1>
    <p class="subtitle">
        Tria un mòdul per començar a treballar amb les teves dades mestres i la gestió diària.
    </p>

    <div class="sections">
        <!-- Bloc Mestres -->
        <div class="section">
            <div class="section-header">
                <span class="section-title">Mestres</span>
                <span class="chip chip-mestres">Base</span>
            </div>

            <div class="btn-col">

                <a href="articles_list.php" class="menu-btn btn-articles">
                    <span class="label">
                        <span class="icon">📦</span>
                        Articles
                    </span>
                    <span>↗</span>
                </a>

                <a href="clients_list.php" class="menu-btn btn-clients">
                    <span class="label">
                        <span class="icon">👥</span>
                        Clients
                    </span>
                    <span>↗</span>
                </a>



                <a href="proveidors_list.php" class="menu-btn btn-proveidors">
                    <span class="label">
                        <span class="icon">🏢</span>
                        Proveïdors
                    </span>
                    <span>↗</span>
                </a>
            </div>
        </div>

        <!-- Bloc Gestió -->
        <div class="section">
            <div class="section-header">
                <span class="section-title">Gestió</span>
                <span class="chip chip-gestio">Diari</span>
            </div>

            <div class="btn-col">
                <a href="albarans_list.php" class="menu-btn btn-albarans">
                    <span class="label">
                        <span class="icon">📄</span>
                        Albarans
                    </span>
                    <span>↗</span>
                </a>

                <a href="factures_clients_list.php" class="menu-btn btn-faccli">
                    <span class="label">
                        <span class="icon">🧾</span>
                        Factures clients
                    </span>
                    <span>↗</span>
                </a>

                <a href="factures_proveidors_list.php" class="menu-btn btn-facprov">
                    <span class="label">
                        <span class="icon">📑</span>
                        Factures proveïdors
                    </span>
                    <span>↗</span>
                </a>
            </div>
        </div>
    </div>

    <div class="footer-note">
        Entorn CRUD bàsic PHP + PostgreSQL · Mòduls mestres i de gestió
    </div>
</div>
</body>
</html>
```

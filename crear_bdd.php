<?php
// migrate_schema.php
// Crea totes les taules bàsiques si no existeixen

require_once 'db.php';

$pdo = getPDO();

// Array de sentències SQL
$sqlList = [];

/*
 * CLIENTS
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS clients (
    id          SERIAL PRIMARY KEY,
    nom         VARCHAR(255) NOT NULL,
    nif         VARCHAR(50),
    adreca      TEXT,
    email       VARCHAR(255),
    telefon     VARCHAR(50),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
";

/*
 * ARTICLES
 * Afegit: iva, actiu, preu_compra
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS articles (
    id           SERIAL PRIMARY KEY,
    codi         VARCHAR(50) UNIQUE NOT NULL,
    descripcio   TEXT NOT NULL,
    preu         NUMERIC(12,2) NOT NULL DEFAULT 0,
    preu_compra  NUMERIC(12,2) NOT NULL DEFAULT 0,
    iva          NUMERIC(5,2) NOT NULL DEFAULT 21,
    actiu        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP NOT NULL DEFAULT NOW()
);
";

/*
 * ALBARANS (capçalera)
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS albarans (
    id              SERIAL PRIMARY KEY,
    num_albara      VARCHAR(30) UNIQUE,
    client_id       INTEGER NOT NULL REFERENCES clients(id),
    data_albara     DATE NOT NULL,
    adreca_entrega  TEXT,
    observacions    TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
";

/*
 * ALBARA_LINIES
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS albara_linies (
    id              SERIAL PRIMARY KEY,
    albara_id       INTEGER NOT NULL REFERENCES albarans(id) ON DELETE CASCADE,
    num_linia       INTEGER NOT NULL,
    article_id      INTEGER REFERENCES articles(id),
    codi_article    VARCHAR(50),
    descripcio      TEXT,
    preu_unitari    NUMERIC(12,2) NOT NULL DEFAULT 0,
    quantitat       NUMERIC(12,3) NOT NULL DEFAULT 0,
    num_factura     VARCHAR(30),
    CONSTRAINT uq_albara_linia UNIQUE (albara_id, num_linia)
);
";

/*
 * FACTURES (capçalera)
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS factures (
    id                      SERIAL PRIMARY KEY,
    num_factura             VARCHAR(30) UNIQUE NOT NULL,
    client_id               INTEGER NOT NULL REFERENCES clients(id),
    data_factura            DATE NOT NULL,
    data_cobrada            DATE,
    data_enviada_portal     DATE,
    estat_portal            VARCHAR(30),
    import_total            NUMERIC(12,2) NOT NULL DEFAULT 0,
    observacions            TEXT,
    created_at              TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMP NOT NULL DEFAULT NOW()
);
";

/*
 * FACTURA_LINIES
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS factura_linies (
    id              SERIAL PRIMARY KEY,
    factura_id      INTEGER NOT NULL REFERENCES factures(id) ON DELETE CASCADE,
    num_linia       INTEGER NOT NULL,
    article_id      INTEGER REFERENCES articles(id),
    codi_article    VARCHAR(50),
    descripcio      TEXT,
    preu_unitari    NUMERIC(12,2) NOT NULL DEFAULT 0,
    quantitat       NUMERIC(12,3) NOT NULL DEFAULT 0,
    CONSTRAINT uq_factura_linia UNIQUE (factura_id, num_linia)
);
";

/*
 * FACTURA_ALBARANS (relació N-N factura <-> albarans)
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS factura_albarans (
    id          SERIAL PRIMARY KEY,
    factura_id  INTEGER NOT NULL REFERENCES factures(id) ON DELETE CASCADE,
    albara_id   INTEGER NOT NULL REFERENCES albarans(id) ON DELETE RESTRICT,
    UNIQUE (factura_id, albara_id)
);
";

/*
 * EMPRESA_PARAMS (paràmetres d'empresa, 1 sola fila)
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS empresa_params (
    id          SERIAL PRIMARY KEY,
    nom         VARCHAR(255) NOT NULL,
    nif         VARCHAR(50),
    adreca      TEXT,
    cp          VARCHAR(10),
    localitat   VARCHAR(100),
    telefon     VARCHAR(50),
    email       VARCHAR(255),
    logo_path   TEXT, -- ruta relativa o absoluta al logo
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
";

/*
 * PROVEIDORS
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS proveidors (
    id          SERIAL PRIMARY KEY,
    nom         VARCHAR(255) NOT NULL,
    nif         VARCHAR(50),
    adreca      TEXT,
    email       VARCHAR(255),
    telefon     VARCHAR(50),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
";

/*
 * FACTURES_PROVEIDORS (capçalera de factura de proveïdor)
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS factures_proveidors (
    id              SERIAL PRIMARY KEY,
    proveidor_id    INTEGER NOT NULL REFERENCES proveidors(id),
    data_factura    DATE NOT NULL,
    num_factura_ext VARCHAR(100),
    observacions    TEXT,
    import_total    NUMERIC(12,2) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
";

/*
 * FACTURA_PROVEIDOR_LINIES (línies de factura de proveïdor)
 */
$sqlList[] = "
CREATE TABLE IF NOT EXISTS factura_proveidor_linies (
    id           SERIAL PRIMARY KEY,
    factura_id   INTEGER NOT NULL REFERENCES factures_proveidors(id) ON DELETE CASCADE,
    num_linia    INTEGER NOT NULL,
    article_id   INTEGER REFERENCES articles(id),
    codi_article VARCHAR(50),
    descripcio   TEXT,
    preu_unitari NUMERIC(12,2) NOT NULL DEFAULT 0,
    quantitat    NUMERIC(12,3) NOT NULL DEFAULT 0,
    CONSTRAINT uq_factura_proveidor_linia UNIQUE (factura_id, num_linia)
);
";

try {
    foreach ($sqlList as $sql) {
        $pdo->exec($sql);
    }
    echo 'Esquema creat/actualitzat correctament (taules creades si no existien).';
} catch (PDOException $e) {
    echo 'Error creant taules: ' . $e->getMessage();
}

// Afegir columna enviadaportal a factures si no existeix
try {
    $pdo->exec("
        ALTER TABLE factures
        ADD COLUMN IF NOT EXISTS enviadaportal SMALLINT NOT NULL DEFAULT 0
    ");
} catch (PDOException $e) {
    // si la taula factures no existeix encara, ho ignorem
}

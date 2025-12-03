<?php
require_once 'db.php';
$pdo = getPDO();

// Per evitar problemes de FK mentre buidem/omplim
$pdo->exec("SET CONSTRAINTS ALL DEFERRED");

// Esborrem dades existents (ordre per FK)
$pdo->beginTransaction();
$pdo->exec("DELETE FROM factura_proveidor_linies");
$pdo->exec("DELETE FROM factures_proveidors");
$pdo->exec("DELETE FROM factura_albarans");
$pdo->exec("DELETE FROM albara_linies");
$pdo->exec("DELETE FROM albarans");
$pdo->exec("DELETE FROM factures");
$pdo->exec("DELETE FROM clients");
$pdo->exec("DELETE FROM proveidors");
$pdo->exec("DELETE FROM articles");
$pdo->commit();

// Helpers
function randDateInMonth(int $year, int $month): string {
    $day = random_int(1, 28); // simplifiquem
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function demoPhone(): string {
    return '+34 9' . random_int(10, 99) . ' ' . random_int(100, 999) . ' ' . random_int(100, 999);
}

function demoEmail(string $prefix, int $i): string {
    return strtolower($prefix) . $i . '@demo.local';
}

// 1) ARTICLES (50)  -> taula: preu
$articles = [];
for ($i = 1; $i <= 50; $i++) {
    $codi = sprintf('ART%03d', $i);
    $desc = "Article demo $i";
    $preu = random_int(500, 5000) / 100; // 5.00 - 50.00

    // IVA: parells 21%, senars 10%
    $iva = ($i % 2 === 0) ? 21 : 10;

    $articles[] = [
        'codi'       => $codi,
        'descripcio' => $desc,
        'preu'       => $preu,
        'iva'        => $iva,
    ];
}

$stmtArt = $pdo->prepare("
    INSERT INTO articles (codi, descripcio, preu, iva)
    VALUES (:codi, :descripcio, :preu, :iva)
    RETURNING id
");

$articleIds = [];
foreach ($articles as $a) {
    $stmtArt->execute($a);
    $articleIds[] = [
        'id'           => (int)$stmtArt->fetchColumn(),
        'preu'         => $a['preu'],          // per si vols usar el preu a altres llocs
        'preu_unitari' => $a['preu'],          // per usar directament a albara_linies
        'codi'         => $a['codi'],
        'desc'         => $a['descripcio'],
        'iva'          => $a['iva'],
    ];
}

// 2) CLIENTS (20)
$clients = [];
for ($i = 1; $i <= 20; $i++) {
    $clients[] = [
        'nom'    => "Client Demo $i",
        'nif'    => "CDEM$i",
        'adreca' => "Carrer Client Demo $i",
        'telefon'=> demoPhone(),
        'email'  => demoEmail('client', $i),
    ];
}

$stmtCli = $pdo->prepare("
    INSERT INTO clients (nom, nif, adreca, telefon, email)
    VALUES (:nom, :nif, :adreca, :telefon, :email)
    RETURNING id
");

$clientIds = [];
foreach ($clients as $c) {
    $stmtCli->execute($c);
    $clientIds[] = (int)$stmtCli->fetchColumn();
}

// 3) PROVEÏDORS (10)
$proveidors = [];
for ($i = 1; $i <= 10; $i++) {
    $proveidors[] = [
        'nom'    => "Proveïdor Demo $i",
        'nif'    => "PDEM$i",
        'adreca' => "Carrer Proveïdor Demo $i",
        'telefon'=> demoPhone(),
        'email'  => demoEmail('proveidor', $i),
    ];
}

$stmtProv = $pdo->prepare("
    INSERT INTO proveidors (nom, nif, adreca, telefon, email)
    VALUES (:nom, :nif, :adreca, :telefon, :email)
    RETURNING id
");

$proveidorIds = [];
foreach ($proveidors as $p) {
    $stmtProv->execute($p);
    $proveidorIds[] = (int)$stmtProv->fetchColumn();
}

// 4) ALBARANS + LÍNIES (per poder facturar clients)
// farem uns 90 albarans repartits en 6 mesos
$year = (int)date('Y');
$months = [1,2,3,4,5,6];

$stmtAlb = $pdo->prepare("
    INSERT INTO albarans (num_albara, data_albara, client_id, adreca_entrega, observacions)
    VALUES (:num_albara, :data_albara, :client_id, :adreca_entrega, :observacions)
    RETURNING id
");

$stmtAlbLin = $pdo->prepare("
    INSERT INTO albara_linies
        (albara_id, num_linia, article_id, codi_article, descripcio, preu_unitari, quantitat, num_factura)
    VALUES
        (:albara_id, :num_linia, :article_id, :codi_article, :descripcio, :preu_unitari, :quantitat, :num_factura)
");

$albaransIdsPerMes = [];
$numAlbaraSeq = 1;

foreach ($months as $m) {
    $albaransIdsPerMes[$m] = [];
    $numAlbaransMes = 15; // aproximat, després facturarem una part
    for ($i = 0; $i < $numAlbaransMes; $i++) {
        $clientId = $clientIds[array_rand($clientIds)];
        $dataAlb  = randDateInMonth($year, $m);

        $stmtAlb->execute([
            'num_albara'      => $numAlbaraSeq++,
            'data_albara'     => $dataAlb,
            'client_id'       => $clientId,
            'adreca_entrega'  => 'Adreça d\'entrega demo',
            'observacions'    => 'Observacions albarà demo',
        ]);
        $albId = (int)$stmtAlb->fetchColumn();
        $albaransIdsPerMes[$m][] = ['id' => $albId, 'data' => $dataAlb, 'client_id' => $clientId];

        // 1-3 línies
        $nLinies = random_int(1,3);
        for ($ln = 1; $ln <= $nLinies; $ln++) {
            $art = $articleIds[array_rand($articleIds)];
            $q   = random_int(1,5);

            $stmtAlbLin->execute([
                'albara_id'     => $albId,
                'num_linia'     => $ln,
                'article_id'    => $art['id'],
                'codi_article'  => $art['codi'],
                'descripcio'    => $art['desc'],
                'preu_unitari'  => $art['preu_unitari'], // aquí ja és preu_unitari
                'quantitat'     => $q,
                'num_factura'   => 0,
            ]);
        }
    }
}

// 5) FACTURES CLIENTS (50 en total, ~30/mes però limitant a 50)
// agafem albarans d’aquests 6 mesos i els agrupem en factures
$stmtFacCli = $pdo->prepare("
    INSERT INTO factures (num_factura, data_factura, client_id, import_total, observacions)
    VALUES (:num_factura, :data_factura, :client_id, :import_total, :observacions)
    RETURNING id
");

$stmtFacAlb = $pdo->prepare("
    INSERT INTO factura_albarans (factura_id, albara_id)
    VALUES (:factura_id, :albara_id)
");

// per calcular l’import de cada albarà
$stmtSumAlb = $pdo->prepare("
    SELECT COALESCE(SUM(preu_unitari * quantitat),0)
    FROM albara_linies
    WHERE albara_id = :id
");

$totalFacturesCli = 0;
$numFacturaSeq = 1;

foreach ($months as $m) {
    if ($totalFacturesCli >= 50) {
        break;
    }
    $albsMes = $albaransIdsPerMes[$m];
    shuffle($albsMes);

    // apuntem a tenir unes 8–10 factures per mes fins arribar a 50
    $maxFactMes = 10;
    $factMes = 0;

    // agruparem 1-3 albarans per factura
    $i = 0;
    $countAlb = count($albsMes);
    while ($i < $countAlb && $factMes < $maxFactMes && $totalFacturesCli < 50) {
        $numAlbInFactura = random_int(1,3);
        $albsFactura = array_slice($albsMes, $i, $numAlbInFactura);
        if (empty($albsFactura)) break;

        $i += $numAlbInFactura;

        $clientId = $albsFactura[0]['client_id'];
        $dataFac  = randDateInMonth($year, $m);

        // calc import sumant línies dels albarans
        $importTotal = 0.0;
        foreach ($albsFactura as $alb) {
            $stmtSumAlb->execute(['id' => $alb['id']]);
            $importTotal += (float)$stmtSumAlb->fetchColumn();
        }

        $stmtFacCli->execute([
            'num_factura'  => $numFacturaSeq++,
            'data_factura' => $dataFac,
            'client_id'    => $clientId,
            'import_total' => $importTotal,
            'observacions' => 'Factura client demo',
        ]);
        $facId = (int)$stmtFacCli->fetchColumn();

        // vincular albarans
        foreach ($albsFactura as $alb) {
            $stmtFacAlb->execute([
                'factura_id' => $facId,
                'albara_id'  => $alb['id'],
            ]);
        }

        $factMes++;
        $totalFacturesCli++;
    }
}

// 6) FACTURES PROVEÏDORS (30, ~5 per mes)
$stmtFacProv = $pdo->prepare("
    INSERT INTO factures_proveidors (num_factura, proveidor_id, data_factura, import_total, observacions)
    VALUES (:num_factura, :proveidor_id, :data_factura, :import_total, :observacions)
");

$numFacProvSeq = 1;
$totalFactProv = 0;

foreach ($months as $m) {
    if ($totalFactProv >= 30) break;

    $facMes = 0;
    $maxFacMes = 5; // objectiu per mes

    while ($facMes < $maxFacMes && $totalFactProv < 30) {
        $provId = $proveidorIds[array_rand($proveidorIds)];
        $data   = randDateInMonth($year, $m);
        $import = random_int(5000, 30000) / 100; // 50 - 300 €

        $stmtFacProv->execute([
            'num_factura'  => $numFacProvSeq++,
            'proveidor_id' => $provId,
            'data_factura' => $data,
            'import_total' => $import,
            'observacions' => 'Factura proveïdor demo',
        ]);

        $facMes++;
        $totalFactProv++;
    }
}
$hashAdmin = password_hash('admin123', PASSWORD_DEFAULT);
$hashRead  = password_hash('read123',  PASSWORD_DEFAULT);
$hashMix   = password_hash('mix123',   PASSWORD_DEFAULT);

$stmtUser = $pdo->prepare("
    INSERT INTO usuaris (usuari, contrasenya, nom_complet, email, permis_mestres, permis_gestio, actiu)
    VALUES (:usuari, :contrasenya, :nom_complet, :email, :permis_mestres, :permis_gestio, TRUE)
    ON CONFLICT (usuari) DO NOTHING
");

$demoUsers = [
    [
        'usuari'         => 'admin',
        'contrasenya'    => $hashAdmin,
        'nom_complet'    => 'Administrador',
        'email'          => 'admin@demo.local',
        'permis_mestres' => 2,
        'permis_gestio'  => 2,
    ],
    [
        'usuari'         => 'lectura',
        'contrasenya'    => $hashRead,
        'nom_complet'    => 'Usuari Lectura',
        'email'          => 'lectura@demo.local',
        'permis_mestres' => 1,
        'permis_gestio'  => 1,
    ],
    [
        'usuari'         => 'mix',
        'contrasenya'    => $hashMix,
        'nom_complet'    => 'Usuari Mestres RW, Gestió R',
        'email'          => 'mix@demo.local',
        'permis_mestres' => 2,
        'permis_gestio'  => 1,
    ],
];

foreach ($demoUsers as $u) {
    $stmtUser->execute($u);
}


echo "<h2>Dades de demostració generades correctament</h2>";
echo "<ul>";
echo "<li>Articles: 50</li>";
echo "<li>Clients: 20</li>";
echo "<li>Proveïdors: 10</li>";
echo "<li>Albarans: aproximadament 90</li>";
echo "<li>Factures clients: $totalFacturesCli</li>";
echo "<li>Factures proveïdors: $totalFactProv</li>";
echo "</ul>";

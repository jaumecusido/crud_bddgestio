<?php
require_once 'config.php';

$databaseUrl = getenv('DATABASE_URL');

if ($databaseUrl !== false) {
    // SOM A RENDER (o entorn amb DATABASE_URL)
    $parts = parse_url($databaseUrl);

    $host = $parts['host'] ?? null;
    $port = $parts['port'] ?? 5432;
    $user = $parts['user'] ?? null;
    $pass = $parts['pass'] ?? null;
    $db   = isset($parts['path']) ? ltrim($parts['path'], '/') : null;

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;";
} else {
    // SOM EN LOCAL → POSA LES TEVES DADES LOCALS
    $host = 'localhost';
    $port = '5432';
    $db   = 'BddGestio';
    $user = 'postgres';
    $pass = 'Jcm22122001';

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;";
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die('Error de connexió: ' . $e->getMessage());
}

function getPDO(): PDO {
    global $host, $port, $db, $user, $pass;

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    return new PDO($dsn, $user, $pass, $options);
}



function getUsuariActual(PDO $pdo): ?array {
    if (empty($_SESSION['usuari_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM usuaris WHERE id = :id AND actiu = TRUE");
    $stmt->execute(['id' => $_SESSION['usuari_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function tePermisMestres(PDO $pdo, int $nivellMinim): bool {
    $user = getUsuariActual($pdo);
    if (!$user) return false;
    return (int)$user['permis_mestres'] >= $nivellMinim;
}

function tePermisGestio(PDO $pdo, int $nivellMinim): bool {
    $user = getUsuariActual($pdo);
    if (!$user) return false;
    return (int)$user['permis_gestio'] >= $nivellMinim;
}

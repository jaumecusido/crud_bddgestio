<?php
require_once 'db.php';
$pdo = getPDO();

$id = $_GET['id'] ?? null;

if ($id !== null && ctype_digit((string)$id)) {
    $stmt = $pdo->prepare('DELETE FROM clients WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

header('Location: clients_list.php');
exit;


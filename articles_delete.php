<?php
require_once 'db.php';
$pdo = getPDO();

$id = $_GET['id'] ?? null;

if ($id !== null && ctype_digit((string)$id)) {
    $stmt = $pdo->prepare('DELETE FROM articles WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

header('Location: articles_list.php');
exit;


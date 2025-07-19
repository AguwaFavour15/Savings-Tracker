<?php
require_once 'config.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    exit("Invalid transaction ID.");
}

// Delete the transaction
$stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
$stmt->execute([$id]);

// Redirect back to dashboard
header("Location: index.php");
exit;

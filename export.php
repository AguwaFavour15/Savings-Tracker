<?php
require_once 'config.php';

// Set headers to force download
header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename="transactions.csv"');

// Output buffer for CSV content
$output = fopen('php://output', 'w');

// Column headings
fputcsv($output, ['ID', 'Type', 'Category', 'Amount', 'Description', 'Transaction Date', 'Created At']);

// Fetch transactions
$stmt = $pdo->query("SELECT * FROM transactions ORDER BY transaction_date DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['type'],
        $row['category'],
        $row['amount'],
        $row['description'],
        $row['transaction_date'],
        $row['created_at']
    ]);
}

fclose($output);
exit;

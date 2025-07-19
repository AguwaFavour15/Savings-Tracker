<?php
require_once 'config.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    exit("Invalid transaction ID.");
}

// Fetch existing record
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->execute([$id]);
$transaction = $stmt->fetch();

if (!$transaction) {
    exit("Transaction not found.");
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $category = trim($_POST['category'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $transaction_date = $_POST['transaction_date'] ?? '';

    if (!in_array($type, ['income', 'expense'])) {
        $errors[] = 'Transaction type is invalid.';
    }
    if ($category === '') {
        $errors[] = 'Category is required.';
    }
    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0.';
    }
    if (!$transaction_date) {
        $errors[] = 'Transaction date is required.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE transactions SET type=?, category=?, amount=?, description=?, transaction_date=? WHERE id=?");
        $stmt->execute([$type, $category, $amount, $description, $transaction_date, $id]);
        $success = "Transaction updated successfully!";
        // Refresh data
        $transaction = [
            'type' => $type,
            'category' => $category,
            'amount' => $amount,
            'description' => $description,
            'transaction_date' => $transaction_date
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Transaction – AccTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="max-w-xl mx-auto mt-10 p-6 bg-white shadow rounded">
        <h1 class="text-2xl font-bold mb-4">✏️ Edit Transaction</h1>

        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 px-4 py-2 rounded mb-4"><?= $success ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4">
                <ul class="list-disc ml-5">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block mb-1 font-semibold">Type</label>
                <select name="type" class="w-full border-gray-300 rounded px-3 py-2" required>
                    <option value="income" <?= $transaction['type'] === 'income' ? 'selected' : '' ?>>Income</option>
                    <option value="expense" <?= $transaction['type'] === 'expense' ? 'selected' : '' ?>>Expense</option>
                </select>
            </div>
            <div>
                <label class="block mb-1 font-semibold">Category</label>
                <input type="text" name="category" value="<?= htmlspecialchars($transaction['category']) ?>" class="w-full border-gray-300 rounded px-3 py-2" required>
            </div>
            <div>
                <label class="block mb-1 font-semibold">Amount ($)</label>
                <input type="number" step="0.01" name="amount" value="<?= $transaction['amount'] ?>" class="w-full border-gray-300 rounded px-3 py-2" required>
            </div>
            <div>
                <label class="block mb-1 font-semibold">Transaction Date</label>
                <input type="date" name="transaction_date" value="<?= $transaction['transaction_date'] ?>" class="w-full border-gray-300 rounded px-3 py-2" required>
            </div>
            <div>
                <label class="block mb-1 font-semibold">Description</label>
                <textarea name="description" rows="3" class="w-full border-gray-300 rounded px-3 py-2"><?= htmlspecialchars($transaction['description']) ?></textarea>
            </div>
            <div class="flex justify-between">
                <button type="submit" class="bg-yellow-500 text-white px-4 py-2 rounded hover:bg-yellow-600">Update</button>
                <a href="index.php" class="text-blue-600 hover:underline">← Back</a>
            </div>
        </form>
    </div>
</body>
</html>

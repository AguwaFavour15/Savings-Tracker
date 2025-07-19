<?php
require_once 'config.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect & sanitize inputs
    $type = $_POST['type'] ?? '';
    $category = trim($_POST['category'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $transaction_date = $_POST['transaction_date'] ?? '';

    // Validation
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

    // If no errors, insert
    if (empty($errors)) {
       $user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, category, amount, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$user_id, $type, $category, $amount, $description, $transaction_date]);
 $success = "Transaction added successfully!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Transaction – AccTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="max-w-xl mx-auto mt-10 p-6 bg-white shadow rounded">
        <h1 class="text-2xl font-bold mb-4">➕ Add Transaction</h1>

        <!-- Success Message -->
        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 px-4 py-2 rounded mb-4">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- Error Messages -->
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
            <!-- Type -->
            <div>
                <label class="block mb-1 font-semibold">Type</label>
                <select name="type" required class="w-full border-gray-300 rounded px-3 py-2">
                    <option value="">-- Select Type --</option>
                    <option value="income">Income</option>
                    <option value="expense">Expense</option>
                </select>
            </div>

            <!-- Category -->
            <div>
                <label class="block mb-1 font-semibold">Category</label>
                <input type="text" name="category" class="w-full border-gray-300 rounded px-3 py-2" required>
            </div>

            <!-- Amount -->
            <div>
                <label class="block mb-1 font-semibold">Amount ($)</label>
                <input type="number" name="amount" step="0.01" class="w-full border-gray-300 rounded px-3 py-2" required>
            </div>

            <!-- Date -->
            <div>
                <label class="block mb-1 font-semibold">Transaction Date</label>
                <input type="date" name="transaction_date" class="w-full border-gray-300 rounded px-3 py-2" required>
            </div>

            <!-- Description -->
            <div>
                <label class="block mb-1 font-semibold">Description (optional)</label>
                <textarea name="description" rows="3" class="w-full border-gray-300 rounded px-3 py-2"></textarea>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-between">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Save Transaction
                </button>
                <a href="index.php" class="text-blue-600 hover:underline">← Back to Dashboard</a>
            </div>
        </form>
    </div>
</body>
</html>

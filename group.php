<?php
require_once 'config.php';

// Security Headers
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; style-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; img-src 'self' data:;");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

// Start secure session
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Strict'
    ]);
}

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if group ID is provided
if (!isset($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}

$group_id = $_GET['id'];

// Verify user is a member of this group
$stmt = $pdo->prepare("SELECT g.*, gm.is_admin FROM groups g 
                      JOIN group_members gm ON g.id = gm.group_id 
                      WHERE g.id = ? AND gm.user_id = ?");
$stmt->execute([$group_id, $user_id]);
$group = $stmt->fetch();

if (!$group) {
    header('Location: dashboard.php');
    exit;
}

// Fetch group members
$stmt = $pdo->prepare("SELECT u.id, u.name FROM users u 
                      JOIN group_members gm ON u.id = gm.user_id 
                      WHERE gm.group_id = ?");
$stmt->execute([$group_id]);
$members = $stmt->fetchAll();

// Fetch group transactions
$stmt = $pdo->prepare("SELECT gt.*, u.name as user_name FROM group_transactions gt
                      JOIN users u ON gt.user_id = u.id
                      WHERE gt.group_id = ? ORDER BY gt.transaction_date DESC");
$stmt->execute([$group_id]);
$transactions = $stmt->fetchAll();

// Calculate group totals
$income = 0;
$expense = 0;
foreach ($transactions as $t) {
    if ($t['type'] === 'income') {
        $income += $t['amount'];
    } elseif ($t['type'] === 'expense') {
        $expense += $t['amount'];
    }
}
$balance = $income - $expense;

// Fetch chat messages
$stmt = $pdo->prepare("SELECT gc.*, u.name as sender_name FROM group_chat gc
                      JOIN users u ON gc.user_id = u.id
                      WHERE gc.group_id = ? ORDER BY gc.created_at ASC");
$stmt->execute([$group_id]);
$messages = $stmt->fetchAll();

// Handle new message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = htmlspecialchars($_POST['message'], ENT_QUOTES, 'UTF-8');
    
    $stmt = $pdo->prepare("INSERT INTO group_chat (group_id, user_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$group_id, $user_id, $message]);
    
    // Redirect to prevent form resubmission
    header("Location: group.php?id=$group_id");
    exit;
}

// Handle new transaction submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amount'])) {
    $amount = floatval($_POST['amount']);
    $type = htmlspecialchars($_POST['type'], ENT_QUOTES, 'UTF-8');
    $category = htmlspecialchars($_POST['category'], ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars($_POST['description'], ENT_QUOTES, 'UTF-8');
    $transaction_date = htmlspecialchars($_POST['transaction_date'], ENT_QUOTES, 'UTF-8');
    
    $stmt = $pdo->prepare("INSERT INTO group_transactions 
                          (group_id, user_id, amount, type, category, description, transaction_date) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$group_id, $user_id, $amount, $type, $category, $description, $transaction_date]);
    
    // Redirect to prevent form resubmission
    header("Location: group.php?id=$group_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($group['name']) ?> - AccTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        function togglePasswordVisibility(inputId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(`${inputId}_icon`);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function scrollChatToBottom() {
            const chatContainer = document.getElementById('chatMessages');
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
        
        window.onload = function() {
            scrollChatToBottom();
        };
    </script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-6xl mx-auto py-8 px-4">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <div>
                <h1 class="text-3xl font-bold"><?= htmlspecialchars($group['name']) ?></h1>
                <p class="text-gray-600"><?= htmlspecialchars($group['bio']) ?></p>
                <p class="text-sm text-gray-500">Members: <?= count($members) ?> | 
                    <?= $group['is_admin'] ? 'You are an admin' : 'Member' ?></p>
            </div>
            <div class="flex gap-2">
                <a href="index.php" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
                    <i class="fas fa-home mr-2"></i>Personal Dashboard
                </a>
                <?php if ($group['is_admin']): ?>
                    <a href="add_member.php?group_id=<?= $group_id ?>" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-user-plus mr-2"></i>Add Member
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white shadow rounded p-6 text-center">
                <h3 class="text-lg font-semibold text-gray-600 mb-2">Group Income</h3>
                <p class="text-2xl font-bold text-green-600">₦<?= number_format($income, 2) ?></p>
            </div>
            <div class="bg-white shadow rounded p-6 text-center">
                <h3 class="text-lg font-semibold text-gray-600 mb-2">Group Expenses</h3>
                <p class="text-2xl font-bold text-red-600">₦<?= number_format($expense, 2) ?></p>
            </div>
            <div class="bg-white shadow rounded p-6 text-center">
                <h3 class="text-lg font-semibold text-gray-600 mb-2">Group Balance</h3>
                <p class="text-2xl font-bold <?= ($balance >= 0) ? 'text-blue-700' : 'text-red-700' ?>">
                    ₦<?= number_format($balance, 2) ?>
                </p>
            </div>
        </div>

        <!-- Two-column layout -->
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left column - Transactions -->
            <div class="lg:w-2/3">
                <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
                    <div class="p-4 border-b flex justify-between items-center">
                        <h2 class="text-xl font-bold">Group Transactions</h2>
                        <button onclick="showTransactionModal()" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">
                            <i class="fas fa-plus mr-1"></i>Add Transaction
                        </button>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($transactions as $t): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4"><?= htmlspecialchars($t['transaction_date']) ?></td>
                                        <td class="px-6 py-4"><?= htmlspecialchars($t['user_name']) ?></td>
                                        <td class="px-6 py-4">
                                            <span class="px-2 py-1 rounded-full text-xs <?= $t['type'] === 'income' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= ucfirst($t['type']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 font-medium <?= $t['type'] === 'income' ? 'text-green-600' : 'text-red-600' ?>">
                                            ₦<?= number_format($t['amount'], 2) ?>
                                        </td>
                                        <td class="px-6 py-4"><?= htmlspecialchars($t['description']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                            No transactions yet. Add the first one!
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right column - Chat and Members -->
            <div class="lg:w-1/3">
                <!-- Group Chat -->
                <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
                    <div class="p-4 border-b">
                        <h2 class="text-xl font-bold">Group Chat</h2>
                    </div>
                    
                    <div class="p-4 h-64 overflow-y-auto" id="chatMessages">
                        <?php foreach ($messages as $m): ?>
                            <div class="mb-3">
                                <div class="flex justify-between items-baseline">
                                    <span class="font-semibold"><?= htmlspecialchars($m['sender_name']) ?></span>
                                    <span class="text-xs text-gray-500"><?= date('h:i A', strtotime($m['created_at'])) ?></span>
                                </div>
                                <p class="text-gray-800"><?= htmlspecialchars($m['message']) ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($messages)): ?>
                            <p class="text-gray-500 text-center py-4">No messages yet. Say hello!</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="p-4 border-t">
                        <form method="POST" class="flex gap-2">
                            <input type="text" name="message" placeholder="Type your message..." 
                                   class="flex-1 border rounded px-3 py-2 focus:outline-none focus:ring-1 focus:ring-blue-500" required>
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Group Members -->
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="p-4 border-b">
                        <h2 class="text-xl font-bold">Group Members</h2>
                    </div>
                    
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($members as $m): ?>
                            <div class="p-3 flex justify-between items-center">
                                <span><?= htmlspecialchars($m['name']) ?></span>
                                <?php if ($m['id'] == $user_id): ?>
                                    <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">You</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Transaction Modal -->
    <div id="transactionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <div class="flex justify-between items-start mb-4">
                <h3 class="text-xl font-bold">Add Group Transaction</h3>
                <button onclick="hideTransactionModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                        <select id="type" name="type" required 
                                class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="income">Income</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="0" required 
                               class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                
                <div>
                    <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <input type="text" id="category" name="category" required 
                           class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="description" name="description" rows="2"
                              class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
                
                <div>
                    <label for="transaction_date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" id="transaction_date" name="transaction_date" required 
                           class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                           value="<?= date('Y-m-d') ?>">
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="hideTransactionModal()" class="border border-gray-300 px-4 py-2 rounded hover:bg-gray-100">
                        Cancel
                    </button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        Add Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle transaction modal
        function showTransactionModal() {
            document.getElementById('transactionModal').classList.remove('hidden');
            document.getElementById('transactionModal').classList.add('flex');
        }

        function hideTransactionModal() {
            document.getElementById('transactionModal').classList.add('hidden');
            document.getElementById('transactionModal').classList.remove('flex');
        }
    </script>
</body>
</html>
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

// Check if we should show the purpose modal (first visit)
$show_purpose_modal = !isset($_SESSION['purpose_selected']);

// Fetch user details
$stmt = $pdo->prepare("SELECT name, account_type, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Fetch all transactions for this user
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY transaction_date DESC");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll();

// Fetch all groups the user belongs to
$stmt = $pdo->prepare("SELECT g.* FROM groups g JOIN group_members gm ON g.id = gm.group_id WHERE gm.user_id = ?");
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll();

// Fetch pending invitations for this user
$stmt = $pdo->prepare("SELECT gi.*, g.name as group_name, u.name as inviter_name 
                      FROM group_invitations gi
                      JOIN groups g ON gi.group_id = g.id
                      JOIN users u ON gi.inviter_id = u.id
                      WHERE gi.invitee_id = ? AND gi.status = 'pending'");
$stmt->execute([$user_id]);
$invitations = $stmt->fetchAll();

// Calculate totals
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
$showWarning = $income > 0 && $expense > ($income * 0.7);

// Handle purpose selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purpose'])) {
    $_SESSION['purpose_selected'] = true;
    $_SESSION['purpose'] = htmlspecialchars($_POST['purpose'], ENT_QUOTES, 'UTF-8');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle group creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $group_name = htmlspecialchars($_POST['group_name'], ENT_QUOTES, 'UTF-8');
    $group_bio = htmlspecialchars($_POST['group_bio'], ENT_QUOTES, 'UTF-8');
    $group_password = password_hash($_POST['group_password'], PASSWORD_DEFAULT);
    
    try {
        $pdo->beginTransaction();
        
        // Create the group
        $stmt = $pdo->prepare("INSERT INTO groups (name, bio, password, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$group_name, $group_bio, $group_password, $user_id]);
        $group_id = $pdo->lastInsertId();
        
        // Add creator as member
        $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, is_admin) VALUES (?, ?, 1)");
        $stmt->execute([$group_id, $user_id]);
        
        $pdo->commit();
        
        // Refresh groups list
        $stmt = $pdo->prepare("SELECT g.* FROM groups g JOIN group_members gm ON g.id = gm.group_id WHERE gm.user_id = ?");
        $stmt->execute([$user_id]);
        $groups = $stmt->fetchAll();
        
        // Hide the modal
        echo "<script>hideCreateGroupModal();</script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Failed to create group: " . $e->getMessage();
    }
}

// Handle group access
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_group'])) {
    $group_id = $_POST['group_id'];
    $password = $_POST['group_password'];
    
    $stmt = $pdo->prepare("SELECT * FROM groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
    
    if ($group && password_verify($password, $group['password'])) {
        $_SESSION['current_group'] = $group_id;
        $_SESSION['current_group_name'] = $group['name'];
        header('Location: group.php?id=' . $group_id);
        exit;
    } else {
        $group_error = "Incorrect password for this group";
    }
}

// Handle invitation response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_invitation'])) {
    $invitation_id = $_POST['invitation_id'];
    $response = $_POST['response'];
    $password = $_POST['password'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Get invitation details
        $stmt = $pdo->prepare("SELECT gi.*, g.password as group_password 
                              FROM group_invitations gi
                              JOIN groups g ON gi.group_id = g.id
                              WHERE gi.id = ? AND gi.invitee_id = ?");
        $stmt->execute([$invitation_id, $user_id]);
        $invitation = $stmt->fetch();
        
        if (!$invitation) {
            throw new Exception("Invitation not found");
        }
        
        if ($response === 'accept') {
            // Verify password if required
            if (!password_verify($password, $invitation['group_password'])) {
                throw new Exception("Incorrect group password");
            }
            
            // Add user to group
            $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            $stmt->execute([$invitation['group_id'], $user_id]);
        }
        
        // Update invitation status
        $stmt = $pdo->prepare("UPDATE group_invitations SET status = ? WHERE id = ?");
        $stmt->execute([$response === 'accept' ? 'accepted' : 'rejected', $invitation_id]);
        
        $pdo->commit();
        
        // Refresh page to show changes
        header("Location: index.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $invitation_error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AccTrack Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        // Check if device is mobile
        function isMobile() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        }
    </script>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Notification Bell for Mobile -->
    <div class="lg:hidden fixed bottom-4 right-4 z-40">
        <button onclick="showNotifications()" class="bg-blue-600 text-white p-3 rounded-full shadow-lg hover:bg-blue-700">
            <i class="fas fa-bell"></i>
            <?php if (!empty($invitations)): ?>
                <span class="absolute top-0 right-0 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                    <?= count($invitations) ?>
                </span>
            <?php endif; ?>
        </button>
    </div>

    <div class="max-w-6xl mx-auto py-8 px-4">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold">Welcome, <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-gray-600">Account type: <?= htmlspecialchars($user['account_type']) ?></p>
                <?php if (isset($_SESSION['current_group_name'])): ?>
                    <p class="text-gray-600">Current group: <?= htmlspecialchars($_SESSION['current_group_name']) ?></p>
                <?php endif; ?>
            </div>
            <div class="flex gap-2">
                <button onclick="showCreateGroupModal()" class="bg-purple-600 text-white px-3 md:px-4 py-2 rounded hover:bg-purple-700">
                    <i class="fas fa-users mr-1 md:mr-2"></i>
                    <span class="hidden md:inline">Create Group</span>
                    <span class="md:hidden">Create</span>
                </button>
                <a href="logout.php" class="bg-red-500 text-white px-3 md:px-4 py-2 rounded hover:bg-red-600">
                    <i class="fas fa-sign-out-alt mr-1 md:mr-2"></i>
                    <span class="hidden md:inline">Logout</span>
                </a>
                <!-- Notification Bell for Desktop -->
                <button onclick="showNotifications()" class="hidden lg:flex bg-blue-600 text-white px-3 py-2 rounded hover:bg-blue-700 relative">
                    <i class="fas fa-bell"></i>
                    <?php if (!empty($invitations)): ?>
                        <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                            <?= count($invitations) ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>
        </div>

        <!-- Add this to your dashboard.php where notifications are displayed -->
<div id="notificationsPanel" class="hidden fixed inset-0 lg:inset-auto lg:absolute lg:top-16 lg:right-4 lg:w-96 bg-white shadow-xl rounded-lg z-50 overflow-y-auto max-h-screen">
    <div class="p-4 border-b flex justify-between items-center">
        <h2 class="text-xl font-bold">Notifications</h2>
        <button onclick="hideNotifications()" class="text-gray-500 hover:text-gray-700">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <?php 
    // Mark all notifications as seen when panel is opened
    if (!empty($invitations)) {
        $unseen_ids = array_filter(array_map(function($invite) {
            return $invite['is_seen'] ? null : $invite['id'];
        }, $invitations));
        
        if (!empty($unseen_ids)) {
            $stmt = $pdo->prepare("UPDATE group_invitations SET is_seen = TRUE WHERE id IN (" . implode(',', $unseen_ids) . ")");
            $stmt->execute();
        }
    }
    ?>
    <?php if (!empty($invitations)): ?>
    <div class="divide-y divide-gray-200">
        <?php foreach ($invitations as $invite): ?>
            <div class="p-4 <?= $invite['is_seen'] ? 'bg-gray-50' : 'bg-blue-50' ?>">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-semibold">Group Invitation</p>
                        <p class="text-sm text-gray-600"><?= htmlspecialchars($invite['inviter_name']) ?> invited you to join <?= htmlspecialchars($invite['group_name']) ?></p>
                    </div>
                    <span class="text-xs text-gray-500"><?= date('M j, g:i a', strtotime($invite['created_at'])) ?></span>
                </div>
                
                <form method="POST" class="mt-3 space-y-2">
                    <input type="hidden" name="invitation_id" value="<?= $invite['id'] ?>">
                    
                    <div class="flex gap-2">
                        <button type="submit" name="respond_invitation" value="reject" 
                                class="bg-gray-200 text-gray-800 px-3 py-1 rounded text-sm hover:bg-gray-300">
                            Decline
                        </button>
                        <button type="button" onclick="showAcceptModal(<?= $invite['id'] ?>)" 
                                class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">
                            Accept
                        </button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="p-4 text-center text-gray-500">
        No new notifications
    </div>
<?php endif; ?>
</div>

<!-- Accept Invitation Modal -->
<div id="acceptModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-start mb-4">
            <h3 class="text-xl font-bold">Join Group</h3>
            <button onclick="hideAcceptModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <p class="mb-4">Please enter the group password to accept this invitation:</p>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" id="acceptInvitationId" name="invitation_id">
            
            <div>
                <label for="accept_password" class="block text-sm font-medium text-gray-700 mb-1">Group Password</label>
                <div class="relative">
                    <input type="password" id="accept_password" name="password" required 
                           class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <button type="button" onclick="togglePasswordVisibility('accept_password')" 
                            class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500">
                        <i id="accept_password_icon" class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <?php if (isset($invitation_error)): ?>
                <div class="text-red-500 text-sm"><?= $invitation_error ?></div>
            <?php endif; ?>
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="hideAcceptModal()" class="border border-gray-300 px-4 py-2 rounded hover:bg-gray-100">
                    Cancel
                </button>
                <button type="submit" name="respond_invitation" value="accept" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Join Group
                </button>
            </div>
        </form>
    </div>
</div>
        <!-- Buttons -->
        <div class="flex flex-wrap gap-2 md:gap-4 mb-6">
            <a href="add.php" class="bg-blue-600 text-white px-3 md:px-4 py-2 rounded hover:bg-blue-700">
                <i class="fas fa-plus mr-1 md:mr-2"></i>
                <span class="hidden md:inline">Add Transaction</span>
                <span class="md:hidden">Add</span>
            </a>
            <a href="export.php" class="bg-green-600 text-white px-3 md:px-4 py-2 rounded hover:bg-green-700">
                <i class="fas fa-file-export mr-1 md:mr-2"></i>
                <span class="hidden md:inline">Export to CSV</span>
                <span class="md:hidden">Export</span>
            </a>
            <a href="reports.php" class="bg-indigo-600 text-white px-3 md:px-4 py-2 rounded hover:bg-indigo-700">
                <i class="fas fa-chart-pie mr-1 md:mr-2"></i>
                <span class="hidden md:inline">View Reports</span>
                <span class="md:hidden">Reports</span>
            </a>
            <?php if (isset($_SESSION['current_group'])): ?>
                <a href="dashboard.php" class="bg-gray-600 text-white px-3 md:px-4 py-2 rounded hover:bg-gray-700">
                    <i class="fas fa-home mr-1 md:mr-2"></i>
                    <span class="hidden md:inline">Personal Dashboard</span>
                    <span class="md:hidden">Personal</span>
                </a>
            <?php endif; ?>
        </div>

        <!-- Groups Section -->
        <div class="mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl md:text-2xl font-bold">Your Groups</h2>
                <?php if (!empty($groups)): ?>
                    <button onclick="showCreateGroupModal()" class="bg-purple-600 text-white px-3 py-1 rounded text-sm hover:bg-purple-700">
                        <i class="fas fa-plus mr-1"></i>
                        <span class="hidden md:inline">New Group</span>
                    </button>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($groups)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                    <?php foreach ($groups as $group): ?>
                        <div class="bg-white rounded-lg shadow p-3 md:p-4 hover:shadow-md transition-shadow">
                            <h3 class="font-bold text-base md:text-lg mb-1 md:mb-2"><?= htmlspecialchars($group['name']) ?></h3>
                            <p class="text-gray-600 text-xs md:text-sm mb-2 md:mb-3"><?= htmlspecialchars($group['bio']) ?></p>
                            <button onclick="showGroupAccessModal(<?= $group['id'] ?>, '<?= htmlspecialchars(addslashes($group['name'])) ?>')" 
                                    class="bg-blue-500 text-white px-2 md:px-3 py-1 rounded text-xs md:text-sm hover:bg-blue-600">
                                <i class="fas fa-door-open mr-1"></i>
                                Access
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-lg shadow p-6 text-center">
                    <p class="text-gray-500 mb-4">You haven't joined any groups yet.</p>
                    <button onclick="showCreateGroupModal()" class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700">
                        Create Your First Group
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Spending Warning -->
        <?php if ($showWarning): ?>
            <div class="bg-yellow-100 text-yellow-800 border-l-4 border-yellow-500 p-3 md:p-4 mb-6 rounded flex items-start">
                <i class="fas fa-lightbulb mt-1 mr-2 md:mr-3"></i>
                <div>
                    <p class="font-semibold">Spending Alert</p>
                    <p class="text-sm">You're spending more than 70% of your income. Consider reviewing your expenses.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 mb-6">
            <div class="bg-white shadow rounded p-4 md:p-6 text-center">
                <h3 class="text-sm md:text-lg font-semibold text-gray-600 mb-1 md:mb-2">Total Income</h3>
                <p class="text-xl md:text-2xl font-bold text-green-600">₦<?= number_format($income, 2) ?></p>
            </div>
            <div class="bg-white shadow rounded p-4 md:p-6 text-center">
                <h3 class="text-sm md:text-lg font-semibold text-gray-600 mb-1 md:mb-2">Total Expenses</h3>
                <p class="text-xl md:text-2xl font-bold text-red-600">₦<?= number_format($expense, 2) ?></p>
            </div>
            <div class="bg-white shadow rounded p-4 md:p-6 text-center">
                <h3 class="text-sm md:text-lg font-semibold text-gray-600 mb-1 md:mb-2">Current Balance</h3>
                <p class="text-xl md:text-2xl font-bold <?= ($balance >= 0) ? 'text-blue-700' : 'text-red-700' ?>">
                    ₦<?= number_format($balance, 2) ?>
                </p>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="p-3 md:p-4 border-b flex flex-col md:flex-row justify-between items-start md:items-center gap-2">
                <h2 class="text-lg md:text-xl font-bold">Your Transactions</h2>
                <div class="relative w-full md:w-auto">
                    <input type="text" placeholder="Search transactions..." 
                           class="border rounded pl-8 pr-3 py-1 md:py-2 w-full md:w-64 text-sm md:text-base"
                           id="searchInput">
                    <i class="fas fa-search absolute left-3 top-2 md:top-3 text-gray-400"></i>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left" id="transactionsTable">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 md:px-6 py-2 md:py-3 text-xs md:text-sm font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-4 md:px-6 py-2 md:py-3 text-xs md:text-sm font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-4 md:px-6 py-2 md:py-3 text-xs md:text-sm font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-4 md:px-6 py-2 md:py-3 text-xs md:text-sm font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Description</th>
                            <th class="px-4 md:px-6 py-2 md:py-3 text-xs md:text-sm font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($transactions as $row): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 md:px-6 py-3 whitespace-nowrap text-sm">
                                    <?= date('M j', strtotime($row['transaction_date'])) ?>
                                </td>
                                <td class="px-4 md:px-6 py-3 whitespace-nowrap">
                                    <span class="px-2 py-1 rounded-full text-xs <?= $row['type'] === 'income' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= ucfirst($row['type']) ?>
                                    </span>
                                </td>
                                <td class="px-4 md:px-6 py-3 whitespace-nowrap font-medium text-sm <?= $row['type'] === 'income' ? 'text-green-600' : 'text-red-600' ?>">
                                    ₦<?= number_format($row['amount'], 2) ?>
                                </td>
                                <td class="px-4 md:px-6 py-3 text-sm hidden md:table-cell">
                                    <?= htmlspecialchars($row['description']) ?>
                                </td>
                                <td class="px-4 md:px-6 py-3 whitespace-nowrap text-sm">
                                    <div class="flex space-x-1 md:space-x-2">
                                        <a href="edit.php?id=<?= $row['id'] ?>" class="text-blue-600 hover:text-blue-800" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete.php?id=<?= $row['id'] ?>" class="text-red-600 hover:text-red-800" 
                                           onclick="return confirm('Are you sure you want to delete this transaction?')" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                    No transactions found. <a href="add.php" class="text-blue-600 hover:underline">Add your first transaction</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Purpose Modal -->
    <?php if ($show_purpose_modal): ?>
    <div id="purposeModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 class="text-xl font-bold mb-4">Welcome to AccTrack! 🎉</h3>
            <p class="mb-4">We'd love to know your primary purpose for tracking finances:</p>
            
            <form method="POST" class="space-y-4">
                <div class="space-y-2">
                    <label class="flex items-center space-x-3">
                        <input type="radio" name="purpose" value="savings" class="h-4 w-4 text-blue-600" checked>
                        <span>Track savings and build wealth</span>
                    </label>
                    <label class="flex items-center space-x-3">
                        <input type="radio" name="purpose" value="budgeting" class="h-4 w-4 text-blue-600">
                        <span>Budgeting and expense control</span>
                    </label>
                    <label class="flex items-center space-x-3">
                        <input type="radio" name="purpose" value="debt" class="h-4 w-4 text-blue-600">
                        <span>Manage and reduce debt</span>
                    </label>
                    <label class="flex items-center space-x-3">
                        <input type="radio" name="purpose" value="business" class="h-4 w-4 text-blue-600">
                        <span>Business financial tracking</span>
                    </label>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        Continue
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Create Group Modal -->
    <div id="createGroupModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <div class="flex justify-between items-start mb-4">
                <h3 class="text-xl font-bold">Create New Group</h3>
                <button onclick="hideCreateGroupModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label for="group_name" class="block text-sm font-medium text-gray-700 mb-1">Group Name</label>
                    <input type="text" id="group_name" name="group_name" required 
                           class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="group_bio" class="block text-sm font-medium text-gray-700 mb-1">Group Description</label>
                    <textarea id="group_bio" name="group_bio" rows="3" 
                              class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
                
                <div>
                    <label for="group_password" class="block text-sm font-medium text-gray-700 mb-1">Group Password</label>
                    <input type="password" id="group_password" name="group_password" required 
                           class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Members will need this password to join the group</p>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="hideCreateGroupModal()" class="border border-gray-300 px-4 py-2 rounded hover:bg-gray-100">
                        Cancel
                    </button>
                    <button type="submit" name="create_group" class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700">
                        Create Group
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Group Access Modal -->
    <div id="groupAccessModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <div class="flex justify-between items-start mb-4">
                <h3 class="text-xl font-bold">Enter Group Password</h3>
                <button onclick="hideGroupAccessModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <p class="mb-4">To access <span id="groupAccessName" class="font-semibold"></span>, please enter the group password:</p>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" id="groupAccessId" name="group_id">
                
                <div>
                    <label for="access_password" class="block text-sm font-medium text-gray-700 mb-1">Group Password</label>
                    <input type="password" id="access_password" name="group_password" required 
                           class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <?php if (isset($group_error)): ?>
                    <div class="text-red-500 text-sm"><?= $group_error ?></div>
                <?php endif; ?>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="hideGroupAccessModal()" class="border border-gray-300 px-4 py-2 rounded hover:bg-gray-100">
                        Cancel
                    </button>
                    <button type="submit" name="access_group" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        Access Group
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Accept Invitation Modal -->
    <div id="acceptModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <div class="flex justify-between items-start mb-4">
                <h3 class="text-xl font-bold">Join Group</h3>
                <button onclick="hideAcceptModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <p class="mb-4">Please enter the group password to accept this invitation:</p>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" id="acceptInvitationId" name="invitation_id">
                
                <div>
                    <label for="accept_password" class="block text-sm font-medium text-gray-700 mb-1">Group Password</label>
                    <input type="password" id="accept_password" name="password" required 
                           class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <?php if (isset($invitation_error)): ?>
                    <div class="text-red-500 text-sm"><?= $invitation_error ?></div>
                <?php endif; ?>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="hideAcceptModal()" class="border border-gray-300 px-4 py-2 rounded hover:bg-gray-100">
                        Cancel
                    </button>
                    <button type="submit" name="respond_invitation" value="accept" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        Join Group
                    </button>
                </div>
            </form>
        </div>
    </div>

    
    <script>
        // Toggle functions for modals
        function showCreateGroupModal() {
            document.getElementById('createGroupModal').classList.remove('hidden');
            document.getElementById('createGroupModal').classList.add('flex');
        }

        function hideCreateGroupModal() {
            document.getElementById('createGroupModal').classList.add('hidden');
            document.getElementById('createGroupModal').classList.remove('flex');
        }

        function showGroupAccessModal(groupId, groupName) {
            document.getElementById('groupAccessId').value = groupId;
            document.getElementById('groupAccessName').textContent = groupName;
            document.getElementById('groupAccessModal').classList.remove('hidden');
            document.getElementById('groupAccessModal').classList.add('flex');
        }

        function hideGroupAccessModal() {
            document.getElementById('groupAccessModal').classList.add('hidden');
            document.getElementById('groupAccessModal').classList.remove('flex');
        }

        function showAcceptModal(invitationId) {
            document.getElementById('acceptInvitationId').value = invitationId;
            document.getElementById('acceptModal').classList.remove('hidden');
            document.getElementById('acceptModal').classList.add('flex');
        }

        function hideAcceptModal() {
            document.getElementById('acceptModal').classList.add('hidden');
            document.getElementById('acceptModal').classList.remove('flex');
        }

        function showNotifications() {
            document.getElementById('notificationsPanel').classList.remove('hidden');
            if (isMobile()) {
                document.getElementById('notificationsPanel').classList.add('flex');
            }
        }

        function hideNotifications() {
            document.getElementById('notificationsPanel').classList.add('hidden');
            if (isMobile()) {
                document.getElementById('notificationsPanel').classList.remove('flex');
            }
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#transactionsTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Close purpose modal after submission
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purpose'])): ?>
            document.getElementById('purposeModal').style.display = 'none';
        <?php endif; ?>

        // Close notifications when clicking outside
        document.addEventListener('click', function(event) {
            const notificationsPanel = document.getElementById('notificationsPanel');
            const notificationsButton = document.querySelector('[onclick="showNotifications()"]');
            
            if (!notificationsPanel.contains(event.target) && 
                !notificationsButton.contains(event.target) &&
                !event.target.closest('[onclick="showNotifications()"]')) {
                hideNotifications();
            }
        });

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

function showAcceptModal(invitationId) {
    document.getElementById('acceptInvitationId').value = invitationId;
    document.getElementById('acceptModal').classList.remove('hidden');
    document.getElementById('acceptModal').classList.add('flex');
}

function hideAcceptModal() {
    document.getElementById('acceptModal').classList.add('hidden');
    document.getElementById('acceptModal').classList.remove('flex');
}
    </script>
</body>
</html>
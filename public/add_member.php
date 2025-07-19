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
$group_id = $_GET['group_id'] ?? null;

// Verify user is admin of this group
$stmt = $pdo->prepare("SELECT g.* FROM groups g 
                      JOIN group_members gm ON g.id = gm.group_id 
                      WHERE g.id = ? AND gm.user_id = ? AND gm.is_admin = 1");
$stmt->execute([$group_id, $user_id]);
$group = $stmt->fetch();

if (!$group) {
    header('Location: dashboard.php');
    exit;
}

// Fetch current members to exclude from search
$stmt = $pdo->prepare("SELECT user_id FROM group_members WHERE group_id = ?");
$stmt->execute([$group_id]);
$current_members = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle search
$search_results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search_term = '%' . $_POST['search_term'] . '%';
    
    $stmt = $pdo->prepare("SELECT id, name, email FROM users 
                          WHERE (name LIKE ? OR email LIKE ?) 
                          AND id NOT IN (" . implode(',', array_fill(0, count($current_members), '?')) . ")");
    $params = array_merge([$search_term, $search_term], $current_members);
    $stmt->execute($params);
    $search_results = $stmt->fetchAll();
}

// Handle invitation response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_invitation'])) {
    $invitation_id = $_POST['invitation_id'];
    $response = $_POST['response'];
    $password = $_POST['password'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Get invitation details including group password
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
            
            // Check if user is already a member (prevent duplicates)
            $stmt = $pdo->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$invitation['group_id'], $user_id]);
            
            if (!$stmt->fetch()) {
                // Add user to group members
                $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, is_admin) VALUES (?, ?, 0)");
                $stmt->execute([$invitation['group_id'], $user_id]);
            }
        }
        
        // Update invitation status
        $stmt = $pdo->prepare("UPDATE group_invitations SET status = ?, is_seen = TRUE WHERE id = ?");
        $stmt->execute([$response === 'accept' ? 'accepted' : 'rejected', $invitation_id]);
        
        $pdo->commit();
        
        // Refresh page to show changes
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $invitation_error = $e->getMessage();
    }
}

// Fetch group account balance
$stmt = $pdo->prepare("SELECT 
                       SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
                       SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
                       FROM group_transactions WHERE group_id = ?");
$stmt->execute([$group_id]);
$group_finance = $stmt->fetch();
$group_balance = $group_finance['income'] - $group_finance['expense'];

// Fetch group account contributions
$stmt = $pdo->prepare("SELECT u.name, 
                       SUM(CASE WHEN gt.type = 'income' THEN gt.amount ELSE 0 END) as contributed,
                       SUM(CASE WHEN gt.type = 'expense' THEN gt.amount ELSE 0 END) as spent
                       FROM group_transactions gt
                       JOIN users u ON gt.user_id = u.id
                       WHERE gt.group_id = ?
                       GROUP BY gt.user_id");
$stmt->execute([$group_id]);
$member_contributions = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Member - <?= htmlspecialchars($group['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        function copyInviteLink() {
            const link = document.getElementById('invite_link');
            link.select();
            document.execCommand('copy');
            alert('Link copied to clipboard!');
        }
        
        function shareOnSocial(platform) {
            const link = document.getElementById('invite_link').value;
            let url;
            
            switch(platform) {
                case 'whatsapp':
                    url = `https://wa.me/?text=Join%20our%20group%20on%20AccTrack:%20${encodeURIComponent(link)}`;
                    break;
                case 'facebook':
                    url = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(link)}`;
                    break;
                case 'twitter':
                    url = `https://twitter.com/intent/tweet?text=Join%20our%20group%20on%20AccTrack&url=${encodeURIComponent(link)}`;
                    break;
                default:
                    return;
            }
            
            window.open(url, '_blank', 'width=600,height=400');
        }
        
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
    </script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-4xl mx-auto py-8 px-4">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold">Add Members to <?= htmlspecialchars($group['name']) ?></h1>
                <p class="text-gray-600">Group Balance: ₦<?= number_format($group_balance, 2) ?></p>
            </div>
            <a href="group.php?id=<?= $group_id ?>" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
                <i class="fas fa-arrow-left mr-2"></i>Back to Group
            </a>
        </div>

        <!-- Group Account Summary -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">Group Account Summary</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-blue-50 p-4 rounded-lg">
                    <h3 class="font-semibold text-blue-800">Total Income</h3>
                    <p class="text-2xl">₦<?= number_format($group_finance['income'], 2) ?></p>
                </div>
                <div class="bg-red-50 p-4 rounded-lg">
                    <h3 class="font-semibold text-red-800">Total Expenses</h3>
                    <p class="text-2xl">₦<?= number_format($group_finance['expense'], 2) ?></p>
                </div>
                <div class="bg-green-50 p-4 rounded-lg">
                    <h3 class="font-semibold text-green-800">Current Balance</h3>
                    <p class="text-2xl">₦<?= number_format($group_balance, 2) ?></p>
                </div>
            </div>
            
            <h3 class="font-semibold mb-2">Member Contributions</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contributed</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Spent</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($member_contributions as $mc): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($mc['name']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-green-600">₦<?= number_format($mc['contributed'], 2) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-red-600">₦<?= number_format($mc['spent'], 2) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap font-medium">
                                    ₦<?= number_format($mc['contributed'] - $mc['spent'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Search Form -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">Search Users</h2>
            
            <form method="POST" class="flex gap-2 mb-4">
                <input type="text" name="search_term" placeholder="Search by name or email" 
                       class="flex-1 border rounded px-3 py-2 focus:outline-none focus:ring-1 focus:ring-blue-500" required>
                <button type="submit" name="search" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    <i class="fas fa-search mr-2"></i>Search
                </button>
            </form>
            
            <?php if (!empty($search_results)): ?>
                <div class="space-y-3">
                    <?php foreach ($search_results as $user): ?>
                        <div class="flex justify-between items-center p-3 border rounded-lg">
                            <div>
                                <p class="font-semibold"><?= htmlspecialchars($user['name']) ?></p>
                                <p class="text-sm text-gray-600"><?= htmlspecialchars($user['email']) ?></p>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="invitee_id" value="<?= $user['id'] ?>">
                                <button type="submit" name="invite_user" class="bg-purple-600 text-white px-3 py-1 rounded text-sm hover:bg-purple-700">
                                    <i class="fas fa-user-plus mr-1"></i>Invite
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <p class="text-gray-500 text-center py-4">No users found matching your search.</p>
            <?php endif; ?>
        </div>

        <!-- Invite Link Section -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-bold mb-4">Share Invitation Link</h2>
            
            <div class="flex flex-col md:flex-row gap-2 mb-4">
                <input type="text" id="invite_link" readonly 
                       value="<?= "https://" . $_SERVER['HTTP_HOST'] . "/join_group.php?group_id=" . $group_id ?>" 
                       class="flex-1 border rounded px-3 py-2 bg-gray-100">
                <button onclick="copyInviteLink()" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
                    <i class="fas fa-copy mr-2"></i>Copy Link
                </button>
            </div>
            
            <div class="flex gap-2">
                <button onclick="shareOnSocial('whatsapp')" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">
                    <i class="fab fa-whatsapp mr-2"></i>Share on WhatsApp
                </button>
                <button onclick="shareOnSocial('facebook')" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    <i class="fab fa-facebook mr-2"></i>Share on Facebook
                </button>
                <button onclick="shareOnSocial('twitter')" class="bg-blue-400 text-white px-4 py-2 rounded hover:bg-blue-500">
                    <i class="fab fa-twitter mr-2"></i>Share on Twitter
                </button>
            </div>
        </div>

        <!-- Status Messages -->
        <?php if (isset($success)): ?>
            <div class="fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg">
                <?= $success ?>
            </div>
            <script>
                setTimeout(() => document.querySelector('.bg-green-500').remove(), 3000);
            </script>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="fixed bottom-4 right-4 bg-red-500 text-white px-4 py-2 rounded shadow-lg">
                <?= $error ?>
            </div>
            <script>
                setTimeout(() => document.querySelector('.bg-red-500').remove(), 3000);
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
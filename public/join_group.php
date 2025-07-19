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
    $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$group_id = $_GET['group_id'] ?? null;

// Verify group exists
$stmt = $pdo->prepare("SELECT * FROM groups WHERE id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch();

if (!$group) {
    header('Location: dashboard.php');
    exit;
}

// Check if user is already a member
$stmt = $pdo->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
$stmt->execute([$group_id, $user_id]);
$is_member = $stmt->fetch();

if ($is_member) {
    header("Location: group.php?id=$group_id");
    exit;
}

// Handle joining the group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_group'])) {
    $password = $_POST['password'] ?? '';
    
    // Verify password
    if (password_verify($password, $group['password'])) {
        try {
            $pdo->beginTransaction();
            
            // Add user to group
            $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            $stmt->execute([$group_id, $user_id]);
            
            // Update any pending invitations
            $stmt = $pdo->prepare("UPDATE group_invitations SET status = 'accepted' 
                                  WHERE group_id = ? AND invitee_id = ? AND status = 'pending'");
            $stmt->execute([$group_id, $user_id]);
            
            $pdo->commit();
            
            header("Location: group.php?id=$group_id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to join group: " . $e->getMessage();
        }
    } else {
        $error = "Incorrect group password";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Group - <?= htmlspecialchars($group['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white shadow rounded-lg p-8 max-w-md w-full">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold">Join Group</h1>
            <p class="text-gray-600"><?= htmlspecialchars($group['name']) ?></p>
            <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars($group['bio']) ?></p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                <p><?= $error ?></p>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-4">
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Group Password</label>
                <input type="password" id="password" name="password" required 
                       class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Enter the group password">
            </div>
            
            <div class="flex justify-between items-center">
                <a href="dashboard.php" class="text-blue-600 hover:underline">Cancel</a>
                <button type="submit" name="join_group" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Join Group
                </button>
            </div>
        </form>
    </div>
</body>
</html>
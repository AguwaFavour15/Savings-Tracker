<?php
// Set this first — before session_start and ANY output
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict'
]);

// THEN start session
require_once 'config.php';
// session_start();
session_regenerate_id(true);

// after session is safely started
// declare(strict_types=1);
// require_once 'config.php';




// If already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errors[] = "Both fields are required.";
    } else {
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            header('Location: index.php');
            exit;
        } else {
            $errors[] = "Invalid email or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - AccTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white shadow-lg rounded-lg p-8 w-full max-w-md">
        <h2 class="text-2xl font-bold text-center mb-6">Login to AccTrack</h2>

        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded mb-4">
                <?php foreach ($errors as $error): ?>
                    <p>• <?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="mb-4">
                <label class="block font-medium mb-1">Email</label>
                <input type="email" name="email" required class="w-full border px-3 py-2 rounded" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="mb-6">
                <label class="block font-medium mb-1">Password</label>
                <div class="relative">
                    <input type="password" id="password" name="password" required class="w-full border px-3 py-2 rounded pr-10">
                    <button type="button" onclick="togglePassword()" class="absolute top-1/2 right-2 transform -translate-y-1/2 text-gray-500 text-sm">👁️</button>
                </div>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700">Login</button>
        </form>

        <p class="mt-4 text-sm text-center text-gray-600">
            Don't have an account? <a href="register.php" class="text-blue-600 hover:underline">Register</a>
        </p>
    </div>

    <script>
        function togglePassword() {
            const passInput = document.getElementById("password");
            passInput.type = passInput.type === "password" ? "text" : "password";
        }
    </script>
</body>
</html>

<?php
require_once 'config.php';

// Security Headers
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; style-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'; img-src 'self' data:;");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$errors = [];
$input = [
    'name' => '',
    'email' => '',
    'account_type' => 'Individual'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $input['name'] = strip_tags(trim($_POST['name'] ?? ''));
    $input['email'] = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';
    $input['account_type'] = in_array($_POST['account_type'] ?? '', ['Individual', 'Family', 'Company']) 
        ? $_POST['account_type'] 
        : 'Individual';

    // Validate inputs
    if (empty($input['name'])) {
        $errors[] = "Name is required.";
    } elseif (strlen($input['name']) > 100) {
        $errors[] = "Name must be less than 100 characters.";
    }

    if (empty($input['email'])) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter, one lowercase letter, and one number.";
    }

    if ($password !== $confirmPass) {
        $errors[] = "Passwords do not match.";
    }

    // Check email duplication only if email is valid
    if (empty($errors) && filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$input['email']]);
            if ($stmt->fetch()) {
                $errors[] = "Email already exists. Please use another email.";
            }
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            $errors[] = "Registration temporarily unavailable. Please try again later.";
        }
    }

    // Register if valid
    if (empty($errors)) {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, account_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $input['name'],
                $input['email'],
                $hashedPassword,
                $input['account_type']
            ]);

            // Optionally log the user in directly after registration
            // $_SESSION['user_id'] = $pdo->lastInsertId();
            // $_SESSION['user_email'] = $input['email'];
            
            header('Location: login.php?registered=1');
            exit;
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            $errors[] = "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - AccTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white shadow-md rounded-lg p-8 w-full max-w-md">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Create an Account</h1>
            <p class="text-gray-600 mt-2">Join us to manage your finances</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded mb-4" role="alert">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium">There were errors with your submission</h3>
                        <div class="mt-2 text-sm">
                            <ul class="list-disc pl-5 space-y-1">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" id="name" name="name" required 
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                    value="<?= htmlspecialchars($input['name']) ?>"
                    maxlength="100">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" id="email" name="email" required 
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                    value="<?= htmlspecialchars($input['email']) ?>">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" id="password" name="password" required minlength="8"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                    pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$"
                    title="Must contain at least one uppercase letter, one lowercase letter, and one number">
                <p class="mt-1 text-xs text-gray-500">Minimum 8 characters with uppercase, lowercase, and number</p>
            </div>

            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div class="flex items-center">
                <input type="checkbox" id="showPassword" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <label for="showPassword" class="ml-2 block text-sm text-gray-700">Show passwords</label>
            </div>

            <div>
                <label for="account_type" class="block text-sm font-medium text-gray-700">Account Type</label>
                <select id="account_type" name="account_type" required
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <option value="Individual" <?= $input['account_type'] === 'Individual' ? 'selected' : '' ?>>Individual</option>
                    <option value="Family" <?= $input['account_type'] === 'Family' ? 'selected' : '' ?>>Family</option>
                    <option value="Company" <?= $input['account_type'] === 'Company' ? 'selected' : '' ?>>Company</option>
                </select>
            </div>

            <div>
                <button type="submit" 
                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Register
                </button>
            </div>
        </form>

        <div class="mt-4 text-center text-sm text-gray-600">
            <p>Already have an account? <a href="login.php" class="font-medium text-blue-600 hover:text-blue-500">Sign in</a></p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const showPassword = document.getElementById('showPassword');
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            
            showPassword.addEventListener('change', function() {
                const type = this.checked ? 'text' : 'password';
                password.type = type;
                confirmPassword.type = type;
            });

            // Form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(event) {
                if (password.value !== confirmPassword.value) {
                    alert('Passwords do not match!');
                    event.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
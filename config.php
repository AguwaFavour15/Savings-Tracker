<?php
// Read database config from environment variables or fall back to defaults
$host     = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: ''; // blank password
$database = getenv('DB_DATABASE') ?: 'acctrack';
$port     = getenv('DB_PORT') ?: 3306;

// Create connection
$conn = new mysqli($host, $username, $password, $database, $port);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>

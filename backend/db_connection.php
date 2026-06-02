<?php
$host = '127.0.0.1';
$db   = 'log_iasi';
$user = 'root';
$pass = ''; 
$charset = 'utf8mb4';

// Construim sirul de conexiune
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Setari suplimentare de securitate si formatare
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Eroare la conectarea cu baza de date: " . $e->getMessage());
}
?>
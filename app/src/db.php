<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $config = require __DIR__ . "/config.php";
    $d = $config["db"];
    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["port"], $d["database"]);
    $pdo = new PDO($dsn, $d["username"], $d["password"], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}

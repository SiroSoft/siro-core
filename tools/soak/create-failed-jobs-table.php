<?php
// Create failed_jobs table in soak SQLite DB
$dbPath = dirname(__DIR__, 2) . '/storage/soak.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS failed_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job TEXT NOT NULL,
        data TEXT NOT NULL DEFAULT '',
        error TEXT NOT NULL DEFAULT '',
        failed_at TEXT NOT NULL DEFAULT ''
    )
");

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables: " . implode(', ', $tables) . "\n";
echo "failed_jobs table OK\n";

<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/atelier-db.php';

$database = atelierDatabase();
atelierEnsureJsonImport($database);
$entries = atelierEntries($database);
$tableCount = (int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('media_items', 'media_tags')")->fetchColumn();

echo 'SQLite tables: ' . $tableCount . PHP_EOL;
echo 'Imported entries: ' . count($entries) . PHP_EOL;

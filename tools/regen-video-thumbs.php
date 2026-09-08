<?php
// tools/regen-video-thumbs.php
// Backfills a poster thumbnail for every existing video slide that has none.
// Run from the repo root:  php tools/regen-video-thumbs.php
require_once __DIR__ . '/../lib/atelier-db.php';

$database = atelierDatabase();
$rows = $database->query(
    "SELECT id, display_path FROM media_slides
     WHERE media_type = 'video' AND (thumbnail_path IS NULL OR thumbnail_path = '')"
)->fetchAll();
$update = $database->prepare('UPDATE media_slides SET thumbnail_path = :thumb WHERE id = :id');

$regenerated = 0;
$skipped = 0;
foreach ($rows as $row) {
    $rel = str_replace('\\', '/', (string) ($row['display_path'] ?? ''));
    $rel = preg_replace('#^(-\\./)+#', '', $rel);
        $rel = ltrim($rel, './');
    if ($rel === '') {
        $skipped++;
        continue;
    }
    $abs = realpath(__DIR__ . '/../' . $rel);
    if ($abs === false || !is_file($abs)) {
        $skipped++;
        continue;
    }
    $thumbName = pathinfo($abs, PATHINFO_FILENAME) . '_thumb.jpg';
    $thumbAbs = dirname($abs) . DIRECTORY_SEPARATOR . $thumbName;
    if (!function_exists('atelierVideoThumb') || !atelierVideoThumb($abs, $thumbAbs)) {
        $skipped++;
        continue;
    }
    $thumbRel = preg_replace('#/+#', '/', '../' . dirname($rel) . '/' . $thumbName);
    $update->execute([':thumb' => $thumbRel, ':id' => $row['id']]);
    $regenerated++;
}

$total = count($rows);
echo sprintf(
    "video thumbnails: %d regenerated, %d skipped, %d total\n",
    $regenerated,
    $skipped,
    $total
);

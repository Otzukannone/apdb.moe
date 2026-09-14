<?php
// tools/regen-streams.php
// Generates streaming-optimized copies (webp for images, webm for videos)
// for every existing slide that has none. Originals are never modified;
// downloads keep using them. Run from the repo root:
//   php tools/regen-streams.php
require_once __DIR__ . '/../lib/atelier-db.php';

$database = atelierDatabase();
$rows = $database->query(
    'SELECT id, original_path, display_path, media_type FROM media_slides'
)->fetchAll();
$update = $database->prepare('UPDATE media_slides SET stream_path = :stream WHERE id = :id');

@set_time_limit(0);

$generated = 0;
$skipped = 0;
$total = count($rows);

foreach ($rows as $row) {
    $sourceAbs = null;
    $sourceRel = '';

    foreach ([(string) $row['original_path'], (string) $row['display_path']] as $candidate) {
        $rel = str_replace('\\', '/', $candidate);
        if ($rel === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel)) {
            continue; // remote URLs cannot be transcoded locally
        }
        $rel = ltrim(preg_replace('#^(\.\./)+#', '', $rel), './');
        if ($rel === '') {
            continue;
        }
        $abs = realpath(__DIR__ . '/../' . $rel);
        if ($abs === false || !is_file($abs)) {
            continue;
        }
        $sourceAbs = $abs;
        $sourceRel = $rel;
        break;
    }

    if ($sourceAbs === null) {
        $skipped++;
        continue;
    }

    $streamAbs = atelierStreamPath($sourceAbs, (string) $row['media_type']);
    if ($streamAbs === '') {
        $skipped++;
        continue;
    }

    $streamRel = '../' . dirname($sourceRel) . '/' . basename($streamAbs);
    $update->execute([':stream' => $streamRel, ':id' => $row['id']]);
    echo sprintf("generated %s (%.1f KB)\n", $streamRel, filesize($streamAbs) / 1024);
    $generated++;
}

echo sprintf(
    "stream versions: %d generated, %d skipped, %d total\n",
    $generated,
    $skipped,
    $total
);

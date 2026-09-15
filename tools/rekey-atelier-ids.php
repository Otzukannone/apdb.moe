<?php
// tools/rekey-atelier-ids.php
// One-shot migration: replaces legacy "atelier_xxxxxxxx" entry ids with
// short youtube-style ids (11 chars, mixed case). Renames the media
// folder of each entry and rewrites every reference in the database
// (tags, slides, and all stored file paths). Safe to re-run: anything
// already rekeyed is skipped. Run from the repo root:
//   php tools/rekey-atelier-ids.php
require_once __DIR__ . '/../lib/atelier-db.php';

$database = atelierDatabase();

// ids are being rewritten across parent and child tables, so the FK
// guard has to step aside for the duration (verified again afterwards)
$database->exec('PRAGMA foreign_keys = OFF');

$items = $database->query(
    "SELECT id FROM media_items WHERE id LIKE 'atelier\\_%' ESCAPE '\\'"
)->fetchAll();

$insertTag = $database->prepare('INSERT OR IGNORE INTO media_tags (media_id, tag) VALUES (:new, :tag)');
$deleteTag = $database->prepare('DELETE FROM media_tags WHERE media_id = :old');
$updateTags = $database->prepare('UPDATE media_tags SET media_id = :new WHERE media_id = :old');
$updateItem = $database->prepare('UPDATE media_items SET id = :new WHERE id = :old');
$updateSlides = $database->prepare('UPDATE media_slides SET media_id = :new WHERE media_id = :old');
$rekeySlide = $database->prepare(
    'UPDATE media_slides
     SET original_path = REPLACE(original_path, :needle, :replacement),
         display_path = REPLACE(display_path, :needle, :replacement),
         thumbnail_path = REPLACE(thumbnail_path, :needle, :replacement),
         stream_path = REPLACE(stream_path, :needle, :replacement)
     WHERE media_id = :new'
);

$rekeyed = 0;
$skipped = 0;

foreach ($items as $item) {
    $oldId = (string) $item['id'];
    $newId = atelierGenerateId($database);
    $oldFolder = __DIR__ . '/../media/atelier/' . $oldId;
    $newFolder = __DIR__ . '/../media/atelier/' . $newId;

    $database->beginTransaction();
    try {
        $updateItem->execute([':new' => $newId, ':old' => $oldId]);
        $updateTags->execute([':new' => $newId, ':old' => $oldId]);
        $updateSlides->execute([':new' => $newId, ':old' => $oldId]);

        // the old tag rows (if any) had their media_id rewritten; drop duplicates
        $deleteTag->execute([':old' => $oldId]);

        // rewrite stored paths first (also for entries whose files are
        // already missing from disk), then rename the media folder if any
        $rekeySlide->execute([
            ':needle' => '/' . $oldId . '/',
            ':replacement' => '/' . $newId . '/',
            ':new' => $newId,
        ]);
        if (is_dir($oldFolder)) {
            if (!@rename($oldFolder, $newFolder)) {
                throw new RuntimeException("unable to rename folder for {$oldId}");
            }
        }

        $database->commit();
        echo $oldId . ' -> ' . $newId . PHP_EOL;
        $rekeyed++;
    } catch (Throwable $error) {
        $database->rollBack();
        // a partial rename could leave the folder under the new name without
        // db updates; move it back before continuing
        if (!is_dir($oldFolder) && is_dir($newFolder)) {
            @rename($newFolder, $oldFolder);
        }
        echo 'skipped ' . $oldId . ': ' . $error->getMessage() . PHP_EOL;
        $skipped++;
    }
}

$database->exec('PRAGMA foreign_keys = ON');

$violations = $database->query('PRAGMA foreign_key_check')->fetchAll();
if ($violations !== []) {
    echo 'WARNING: foreign key violations detected, run: PRAGMA foreign_key_check' . PHP_EOL;
}

echo sprintf(
    "rekeyed ids: %d rekeyed, %d skipped\n",
    $rekeyed,
    $skipped
);

<?php
declare(strict_types=1);

function atelierDatabase(): PDO
{
    static $database;

    if ($database instanceof PDO) {
        return $database;
    }

    $dataDirectory = __DIR__ . '/../data';
    if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0750, true) && !is_dir($dataDirectory)) {
        throw new RuntimeException('Unable to create the data directory.');
    }

    $database = new PDO('sqlite:' . $dataDirectory . '/atelierphischers.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS media_items (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            year TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            media_type TEXT NOT NULL DEFAULT 'image' CHECK (media_type IN ('image', 'video')),
            original_path TEXT NOT NULL DEFAULT '',
            display_path TEXT NOT NULL DEFAULT '',
            thumbnail_path TEXT NOT NULL DEFAULT '',
            mime_type TEXT NOT NULL DEFAULT '',
            width INTEGER,
            height INTEGER,
            duration_seconds REAL,
            file_size INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS media_tags (
            media_id TEXT NOT NULL,
            tag TEXT NOT NULL,
            PRIMARY KEY (media_id, tag),
            FOREIGN KEY (media_id) REFERENCES media_items(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS media_slides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            media_id TEXT NOT NULL,
            slide_order INTEGER NOT NULL,
            original_path TEXT NOT NULL,
            display_path TEXT NOT NULL,
            original_name TEXT NOT NULL DEFAULT '',
            mime_type TEXT NOT NULL,
            media_type TEXT NOT NULL CHECK (media_type IN ('image', 'video')),
            file_size INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (media_id, slide_order),
            FOREIGN KEY (media_id) REFERENCES media_items(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_media_items_year ON media_items(year);
        CREATE INDEX IF NOT EXISTS idx_media_tags_tag ON media_tags(tag);
        CREATE INDEX IF NOT EXISTS idx_media_slides_media_order ON media_slides(media_id, slide_order);
    SQL);

    $slideColumns = $database->query('PRAGMA table_info(media_slides)')->fetchAll();
    $hasOriginalName = array_filter($slideColumns, static fn ($column) => ($column['name'] ?? '') === 'original_name');
    if ($hasOriginalName === []) {
        $database->exec("ALTER TABLE media_slides ADD COLUMN original_name TEXT NOT NULL DEFAULT ''");
    }

    return $database;
}

function atelierEnsureJsonImport(PDO $database): void
{
    $count = (int) $database->query('SELECT COUNT(*) FROM media_items')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $jsonFile = __DIR__ . '/../data/atelierphischers.json';
    if (!is_file($jsonFile)) {
        return;
    }

    $entries = json_decode((string) file_get_contents($jsonFile), true);
    if (!is_array($entries) || $entries === []) {
        return;
    }

    $insertItem = $database->prepare(
        'INSERT OR IGNORE INTO media_items (id, title, year, description, media_type, original_path, display_path) VALUES (:id, :title, :year, :description, :media_type, :original_path, :display_path)'
    );
    $insertTag = $database->prepare('INSERT OR IGNORE INTO media_tags (media_id, tag) VALUES (:media_id, :tag)');

    $database->beginTransaction();
    try {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = trim((string) ($entry['id'] ?? ''));
            $title = trim((string) ($entry['title'] ?? 'untitled'));
            if ($id === '' || $title === '') {
                continue;
            }

            $image = trim((string) ($entry['image'] ?? ''));
            $insertItem->execute([
                ':id' => $id,
                ':title' => $title,
                ':year' => trim((string) ($entry['year'] ?? '')),
                ':description' => trim((string) ($entry['description'] ?? '')),
                ':media_type' => 'image',
                ':original_path' => $image,
                ':display_path' => $image,
            ]);

            foreach ((array) ($entry['tags'] ?? []) as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '') {
                    $insertTag->execute([':media_id' => $id, ':tag' => $tag]);
                }
            }
        }
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }
}

function atelierEntries(PDO $database): array
{
    $items = $database->query('SELECT * FROM media_items ORDER BY CAST(year AS INTEGER) DESC, created_at DESC')->fetchAll();
    $tags = $database->query('SELECT media_id, tag FROM media_tags ORDER BY tag')->fetchAll();
    $slides = $database->query('SELECT media_id, id, slide_order, display_path, original_name, mime_type, media_type FROM media_slides ORDER BY media_id, slide_order')->fetchAll();
    $tagsByItem = [];
    $slidesByItem = [];

    foreach ($tags as $tag) {
        $tagsByItem[$tag['media_id']][] = $tag['tag'];
    }

    foreach ($slides as $slide) {
        $slidesByItem[$slide['media_id']][] = $slide;
    }

    foreach ($items as &$item) {
        $item['image'] = $item['display_path'];
        $item['tags'] = $tagsByItem[$item['id']] ?? [];
        $item['slides'] = $slidesByItem[$item['id']] ?? ($item['image'] !== '' ? [[
            'slide_order' => 0,
            'display_path' => $item['image'],
            'original_name' => basename(parse_url($item['image'], PHP_URL_PATH) ?: $item['image']),
            'mime_type' => $item['mime_type'] ?: 'image/*',
            'media_type' => $item['media_type'] ?: 'image',
        ]] : []);
    }
    unset($item);

    return $items;
}

function atelierSaveEntry(PDO $database, array $entry): void
{
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'INSERT INTO media_items (id, title, year, description, media_type, original_path, display_path, updated_at)
             VALUES (:id, :title, :year, :description, :media_type, :original_path, :display_path, CURRENT_TIMESTAMP)
             ON CONFLICT(id) DO UPDATE SET title = excluded.title, year = excluded.year,
             description = excluded.description, original_path = excluded.original_path,
             display_path = excluded.display_path, updated_at = CURRENT_TIMESTAMP'
        );
        $image = trim((string) ($entry['image'] ?? ''));
        $statement->execute([
            ':id' => (string) $entry['id'],
            ':title' => (string) $entry['title'],
            ':year' => (string) ($entry['year'] ?? ''),
            ':description' => (string) ($entry['description'] ?? ''),
            ':media_type' => 'image',
            ':original_path' => $image,
            ':display_path' => $image,
        ]);

        $database->prepare('DELETE FROM media_tags WHERE media_id = :media_id')->execute([':media_id' => $entry['id']]);
        $tagStatement = $database->prepare('INSERT OR IGNORE INTO media_tags (media_id, tag) VALUES (:media_id, :tag)');
        foreach ((array) ($entry['tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $tagStatement->execute([':media_id' => $entry['id'], ':tag' => $tag]);
            }
        }
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }
}

function atelierDeleteEntry(PDO $database, string $id): void
{
    $database->prepare('DELETE FROM media_items WHERE id = :id')->execute([':id' => $id]);
}

function atelierUpdateSlideOrder(PDO $database, string $mediaId, array $slideIds): int
{
    $existing = $database->prepare('SELECT id FROM media_slides WHERE media_id = :media_id');
    $existing->execute([':media_id' => $mediaId]);
    $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
    $orderedIds = array_values(array_unique(array_map('intval', $slideIds)));
    $keptIds = array_values(array_intersect($orderedIds, $existingIds));

    $database->beginTransaction();
    try {
        $database->prepare('DELETE FROM media_slides WHERE media_id = :media_id AND id NOT IN (' . ($keptIds !== [] ? implode(',', $keptIds) : '0') . ')')->execute([':media_id' => $mediaId]);
        $database->prepare('UPDATE media_slides SET slide_order = -(id + 1) WHERE media_id = :media_id')->execute([':media_id' => $mediaId]);
        $update = $database->prepare('UPDATE media_slides SET slide_order = :slide_order WHERE id = :id AND media_id = :media_id');
        foreach ($keptIds as $order => $slideId) {
            $update->execute([':slide_order' => $order, ':id' => $slideId, ':media_id' => $mediaId]);
        }
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }

    return count($keptIds);
}

function atelierStoreUploads(PDO $database, string $mediaId, array $files, int $startOrder = 0): int
{
    $allowedTypes = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/gif' => 'image',
        'image/webp' => 'image',
        'image/avif' => 'image',
        'video/mp4' => 'video',
        'video/quicktime' => 'video',
        'video/mpeg' => 'video',
        'video/x-msvideo' => 'video',
        'video/x-ms-asf' => 'video',
    ];
    $fileCount = count($files['name'] ?? []);
    if ($fileCount + $startOrder > 20) {
        throw new RuntimeException('up to 20 media files can be added at once.');
    }

    $uploadDirectory = __DIR__ . '/../media/atelier/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $mediaId);
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('unable to create the media directory.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $insert = $database->prepare(
        'INSERT INTO media_slides (media_id, slide_order, original_path, display_path, original_name, mime_type, media_type, file_size) VALUES (:media_id, :slide_order, :original_path, :display_path, :original_name, :mime_type, :media_type, :file_size)'
    );
    $stored = 0;
    foreach (($files['tmp_name'] ?? []) as $index => $temporaryPath) {
        if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('one of the uploaded files could not be received.');
        }
        if (!is_uploaded_file($temporaryPath)) {
            throw new RuntimeException('invalid uploaded file.');
        }

        $mimeType = $fileInfo->file($temporaryPath) ?: '';
        $mediaType = $allowedTypes[$mimeType] ?? null;
        if ($mediaType === null) {
            throw new RuntimeException('unsupported media type: ' . $mimeType);
        }
        if (filesize($temporaryPath) > 250 * 1024 * 1024) {
            throw new RuntimeException('each media file must be 250 MB or smaller.');
        }
        if ($mediaType === 'image' && @getimagesize($temporaryPath) === false) {
            throw new RuntimeException('one uploaded image is invalid.');
        }

        $extension = strtolower(pathinfo((string) ($files['name'][$index] ?? ''), PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', $extension) : '');
        $destination = $uploadDirectory . '/' . $filename;
        if (!move_uploaded_file($temporaryPath, $destination)) {
            throw new RuntimeException('unable to store an uploaded file.');
        }

        $relativePath = '../media/atelier/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $mediaId) . '/' . $filename;
        $insert->execute([
            ':media_id' => $mediaId,
            ':slide_order' => $startOrder + $stored,
            ':original_path' => $relativePath,
            ':display_path' => $relativePath,
            ':original_name' => basename((string) ($files['name'][$index] ?? $filename)),
            ':mime_type' => $mimeType,
            ':media_type' => $mediaType,
            ':file_size' => filesize($destination),
        ]);
        $stored++;
    }

    return $stored;
}

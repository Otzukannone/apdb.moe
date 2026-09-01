<?php
session_start();

if (empty($_SESSION['apdb_admin'])) {
    header('Location: ./index.php');
    exit;
}

$dataFile = __DIR__ . '/../data/atelierphischers.json';

function loadEntries(string $file): array {
    if (!file_exists($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveEntries(string $file, array $entries): void {
    $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($file, $json . PHP_EOL, LOCK_EX);
}

$entries = loadEntries($dataFile);
$notice = '';
$editEntry = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = trim((string) ($_POST['id'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $year = trim((string) ($_POST['year'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $image = trim((string) ($_POST['image'] ?? ''));
    $tags = trim((string) ($_POST['tags'] ?? ''));

    if ($action === 'delete' && $id !== '') {
        $entries = array_values(array_filter($entries, static fn ($entry) => ($entry['id'] ?? '') !== $id));
        saveEntries($dataFile, $entries);
        $notice = 'entry deleted.';
    } elseif ($action === 'create' || $action === 'save') {
        if ($title === '') {
            $notice = 'title is required.';
        } else {
            $tagList = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $tags) ?: []), static fn ($tag) => $tag !== ''));

            if ($action === 'save' && $id !== '') {
                foreach ($entries as &$entry) {
                    if (($entry['id'] ?? '') === $id) {
                        $entry['title'] = $title;
                        $entry['year'] = $year;
                        $entry['description'] = $description;
                        $entry['image'] = $image;
                        $entry['tags'] = $tagList;
                        $notice = 'entry updated.';
                    }
                }
                unset($entry);
            } else {
                $entries[] = [
                    'id' => 'atelier_' . substr(md5((string) microtime(true) . $title), 0, 8),
                    'title' => $title,
                    'year' => $year,
                    'description' => $description,
                    'image' => $image,
                    'tags' => $tagList,
                ];
                $notice = 'entry added.';
            }

            saveEntries($dataFile, $entries);
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = trim((string) $_GET['edit']);
    foreach ($entries as $entry) {
        if (($entry['id'] ?? '') === $editId) {
            $editEntry = $entry;
            break;
        }
    }
}

$today = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>atelierphischers admin — apdb.moe</title>
    <link rel="icon" type="image/png" href="../assets/yuyuk.png" />
    <style>
      :root {
        --ink: #0c0e1d;
        --paper: #f0f0ee;
        --panel: rgba(255,255,255,0.8);
        --panel-border: rgba(12,14,29,0.18);
        --dot-pitch: 5vw;
        --dot-radius: clamp(3px, 0.6vw, 12px);
      }

      * { box-sizing: border-box; }

      html, body {
        margin: 0;
        min-height: 100vh;
        font-family: "Sazanami Gothic", "Consolas", "DejaVu Sans Mono", monospace;
        background: var(--paper);
        color: var(--ink);
      }

      body {
        padding: 32px 20px;
      }

      body::before {
        content: "";
        position: fixed;
        inset: 0;
        background-image:
          radial-gradient(circle, rgba(12,14,29,0.55) var(--dot-radius), transparent calc(var(--dot-radius) + 0.5px)),
          radial-gradient(circle, rgba(12,14,29,0.55) var(--dot-radius), transparent calc(var(--dot-radius) + 0.5px));
        background-size: var(--dot-pitch) var(--dot-pitch);
        background-position: 0 0, calc(var(--dot-pitch) / 2) calc(var(--dot-pitch) / 2);
        opacity: 0.8;
        pointer-events: none;
      }

      .admin-shell {
        position: relative;
        z-index: 1;
        max-width: 1100px;
        margin: 0 auto;
      }

      .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
      }

      .brand {
        font-size: clamp(1.3rem, 2vw, 2.1rem);
        letter-spacing: 0.12em;
        text-transform: lowercase;
      }

      .actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
      }

      a, button {
        font: inherit;
      }

      .link-button,
      button[type="submit"],
      .ghost {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        border: 1px solid rgba(12,14,29,0.18);
        border-radius: 999px;
        padding: 0 16px;
        background: #0d1323;
        color: #fff;
        text-decoration: none;
        cursor: pointer;
      }

      .ghost {
        background: transparent;
        color: var(--ink);
      }

      .layout {
        display: grid;
        grid-template-columns: 420px minmax(0, 1fr);
        gap: 24px;
      }

      .panel {
        background: var(--panel);
        border: 1px solid var(--panel-border);
        border-radius: 18px;
        padding: 18px 18px 20px;
      }

      .panel h2 {
        margin: 0 0 12px;
        font-size: 1.1rem;
        letter-spacing: 0.12em;
        text-transform: lowercase;
      }

      .notice {
        margin-bottom: 14px;
        padding: 10px 12px;
        border-radius: 12px;
        background: rgba(12,14,29,0.06);
        border: 1px solid rgba(12,14,29,0.08);
      }

      form {
        display: grid;
        gap: 12px;
      }

      label {
        display: grid;
        gap: 8px;
        font-size: 0.72rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(12,14,29,0.7);
      }

      input, textarea {
        width: 100%;
        border: 1px solid rgba(12,14,29,0.18);
        border-radius: 10px;
        background: rgba(255,255,255,0.2);
        color: var(--ink);
        font: inherit;
        font-size: 1rem;
        padding: 10px 12px;
      }

      textarea {
        min-height: 110px;
        resize: vertical;
      }

      .form-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 4px;
      }

      .entries {
        display: grid;
        gap: 14px;
      }

      .entry {
        border: 1px solid rgba(12,14,29,0.12);
        border-radius: 14px;
        background: rgba(255,255,255,0.3);
        padding: 14px 14px 12px;
      }

      .entry-head {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 8px;
      }

      .entry-head strong {
        display: block;
        font-size: 1.1rem;
      }

      .entry-head span {
        font-size: 0.78rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: rgba(12,14,29,0.7);
      }

      .entry p {
        margin: 0 0 10px;
        color: rgba(12,14,29,0.75);
      }

      .tags {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 12px;
      }

      .tag {
        display: inline-block;
        border: 1px solid rgba(12,14,29,0.14);
        border-radius: 999px;
        padding: 4px 8px;
        font-size: 0.7rem;
        letter-spacing: 0.08em;
        text-transform: lowercase;
      }

      .entry-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
      }

      .entry-actions form {
        display: inline;
      }

      .mini {
        background: transparent;
        border: 1px solid rgba(12,14,29,0.18);
        color: var(--ink);
        border-radius: 999px;
        padding: 8px 12px;
        cursor: pointer;
      }

      @media (max-width: 860px) {
        .layout {
          grid-template-columns: 1fr;
        }
      }
    </style>
  </head>
  <body>
    <div class="admin-shell">
      <div class="topbar">
        <div class="brand">atelierphischers admin</div>
        <div class="actions">
          <a class="link-button" href="../">home</a>
          <a class="ghost" href="./dashboard.php">dashboard</a>
          <a class="ghost" href="./logout.php">logout</a>
        </div>
      </div>

      <div class="layout">
        <section class="panel">
          <h2><?= $editEntry ? 'edit entry' : 'new entry' ?></h2>

          <?php if ($notice !== ''): ?>
            <div class="notice"><?= htmlspecialchars($notice) ?></div>
          <?php endif; ?>

          <form method="post" action="./atelier.php">
            <input type="hidden" name="action" value="<?= $editEntry ? 'save' : 'create' ?>" />
            <?php if ($editEntry): ?>
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($editEntry['id'] ?? '')) ?>" />
            <?php endif; ?>

            <label>
              title
              <input type="text" name="title" value="<?= htmlspecialchars((string) ($editEntry['title'] ?? '')) ?>" required />
            </label>

            <label>
              year
              <input type="text" name="year" value="<?= htmlspecialchars((string) ($editEntry['year'] ?? $today)) ?>" />
            </label>

            <label>
              image url
              <input type="url" name="image" value="<?= htmlspecialchars((string) ($editEntry['image'] ?? '')) ?>" placeholder="https://..." />
            </label>

            <label>
              tags
              <input type="text" name="tags" value="<?= htmlspecialchars(implode(', ', (array) ($editEntry['tags'] ?? []))) ?>" placeholder="study, mood, palette" />
            </label>

            <label>
              description
              <textarea name="description" placeholder="brief note..."><?= htmlspecialchars((string) ($editEntry['description'] ?? '')) ?></textarea>
            </label>

            <div class="form-actions">
              <button type="submit"><?= $editEntry ? 'save changes' : 'add entry' ?></button>
              <?php if ($editEntry): ?>
                <a class="ghost" href="./atelier.php">cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </section>

        <section class="panel">
          <h2>entries</h2>
          <div class="entries">
            <?php if (empty($entries)): ?>
              <div class="notice">no entries yet.</div>
            <?php else: ?>
              <?php foreach ($entries as $entry): ?>
                <article class="entry">
                  <div class="entry-head">
                    <div>
                      <strong><?= htmlspecialchars((string) ($entry['title'] ?? 'untitled')) ?></strong>
                      <span><?= htmlspecialchars((string) ($entry['year'] ?? 'draft')) ?></span>
                    </div>
                  </div>

                  <p><?= htmlspecialchars((string) ($entry['description'] ?? '')) ?: 'No description yet.' ?></p>

                  <?php if (!empty($entry['tags'])): ?>
                    <div class="tags">
                      <?php foreach ((array) $entry['tags'] as $tag): ?>
                        <span class="tag"><?= htmlspecialchars((string) $tag) ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <div class="entry-actions">
                    <a class="mini" href="./atelier.php?edit=<?= urlencode((string) ($entry['id'] ?? '')) ?>">edit</a>
                    <form method="post" action="./atelier.php" onsubmit="return confirm('delete this entry?');">
                      <input type="hidden" name="action" value="delete" />
                      <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($entry['id'] ?? '')) ?>" />
                      <button class="mini" type="submit">delete</button>
                    </form>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </body>
</html>

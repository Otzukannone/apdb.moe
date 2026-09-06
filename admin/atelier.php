<?php
session_start();

if (empty($_SESSION['apdb_admin'])) {
    header('Location: ./index.php');
    exit;
}

require_once __DIR__ . '/../lib/atelier-db.php';
$database = atelierDatabase();
atelierEnsureJsonImport($database);
$entries = atelierEntries($database);
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
    $uploadedFiles = $_FILES['media'] ?? [];

    if ($action === 'delete' && $id !== '') {
        atelierDeleteEntry($database, $id);
        $notice = 'entry deleted.';
    } elseif ($action === 'create' || $action === 'save') {
        if ($title === '') {
            $notice = 'title is required.';
        } else {
            $tagList = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $tags) ?: []), static fn ($tag) => $tag !== ''));

            $entryId = $action === 'save' && $id !== '' ? $id : 'atelier_' . substr(md5((string) microtime(true) . $title), 0, 8);
            $uploadNotice = '';
            atelierSaveEntry($database, [
              'id' => $entryId,
              'title' => $title,
              'year' => $year,
              'description' => $description,
              'image' => $image,
              'tags' => $tagList,
            ]);
            $hasUploadedFiles = array_filter((array) ($uploadedFiles['name'] ?? []), static fn ($name) => trim((string) $name) !== '');
            if ($hasUploadedFiles !== []) {
              try {
                $keptSlides = array_map('intval', (array) ($_POST['keep_slides'] ?? []));
                $keptCount = atelierUpdateSlideOrder($database, $entryId, $keptSlides);
                atelierStoreUploads($database, $entryId, $uploadedFiles, $keptCount);
              } catch (RuntimeException $error) {
                $uploadNotice = $error->getMessage();
              }
            } elseif ($action === 'save') {
              atelierUpdateSlideOrder($database, $entryId, array_map('intval', (array) ($_POST['keep_slides'] ?? [])));
            }
            $notice = $uploadNotice !== '' ? $uploadNotice : ($action === 'save' ? 'entry updated.' : 'entry added.');
            $entries = atelierEntries($database);
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

      .upload-label {
        display: grid;
        gap: 8px;
      }

      .upload-dropzone {
        border: 2px dashed rgba(12,14,29,0.24);
        border-radius: 12px;
        padding: 18px 14px;
        text-align: center;
        background: rgba(255,255,255,0.18);
        cursor: pointer;
      }

      .upload-dropzone.is-dragging,
      .upload-dropzone:hover {
        border-color: rgba(12,14,29,0.58);
        background: rgba(255,255,255,0.42);
      }

      .upload-dropzone strong,
      .upload-dropzone span {
        display: block;
      }

      .upload-dropzone span {
        margin-top: 5px;
        font-size: 0.68rem;
        letter-spacing: 0.04em;
        text-transform: none;
      }

      .upload-input {
        position: absolute;
        width: 1px;
        height: 1px;
        opacity: 0;
        pointer-events: none;
      }

      .upload-list {
        display: grid;
        gap: 6px;
        margin: 8px 0 0;
        padding: 0;
        list-style: none;
      }

      .upload-list li {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 9px;
        border: 1px solid rgba(12,14,29,0.12);
        border-radius: 8px;
        background: rgba(255,255,255,0.32);
        cursor: grab;
        font-size: 0.78rem;
        letter-spacing: 0.02em;
        text-transform: none;
        width: 100%;
        min-width: 0;
        overflow: hidden;
      }

      .upload-list .slide-thumb {
        flex: 0 0 48px;
        width: 48px;
        height: 48px;
        object-fit: cover;
        border-radius: 5px;
        background: rgba(12,14,29,0.12);
      }

      .upload-list .slide-name {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .upload-list li:active {
        cursor: grabbing;
      }

      .upload-list li::before {
        content: "::";
        color: rgba(12,14,29,0.42);
      }

      .upload-list li.is-removed {
        opacity: 0.45;
        text-decoration: line-through;
      }

      .upload-list li .remove-slide {
        margin-left: auto;
        border: 0;
        background: transparent;
        color: var(--ink);
        cursor: pointer;
        font: inherit;
        font-size: 0.72rem;
      }

      .existing-media-title {
        margin: 4px 0 0;
        font-size: 0.68rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: rgba(12,14,29,0.62);
      }

      .upload-status {
        margin: 0;
        color: rgba(12,14,29,0.62);
        font-size: 0.68rem;
        letter-spacing: 0.04em;
        text-transform: none;
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
        position: relative;
        min-height: 150px;
        border: 1px solid rgba(12,14,29,0.12);
        border-radius: 14px;
        background: rgba(255,255,255,0.3);
        padding: 14px 14px 12px;
      }

      .entry-copy {
        min-width: 0;
        padding-right: 118px;
      }

      .entry-thumbnail {
        position: absolute;
        top: 14px;
        right: 14px;
        width: 92px;
        height: 92px;
        border-radius: 8px;
        background: rgba(12,14,29,0.1);
        object-fit: cover;
      }

      .entry-thumbnail--empty {
        display: grid;
        place-items: center;
        color: rgba(12,14,29,0.45);
        font-size: 0.64rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
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
        <div class="brand">atelierphischers</div>
        <div class="actions">
          <a class="link-button" href="../">home</a>
          <a class="ghost" href="../atelierphischers/index.php">back</a>
        </div>
      </div>

      <div class="layout">
        <section class="panel">
          <h2><?= $editEntry ? 'edit entry' : 'new entry' ?></h2>

          <?php if ($notice !== ''): ?>
            <div class="notice"><?= htmlspecialchars($notice) ?></div>
          <?php endif; ?>

          <form method="post" action="./atelier.php" enctype="multipart/form-data">
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

            <div class="upload-label">
              <span>upload media</span>
              <?php if ($editEntry && array_filter((array) ($editEntry['slides'] ?? []), static fn ($slide) => (int) ($slide['id'] ?? 0) > 0) !== []): ?>
                <p class="existing-media-title">current media: drag to reorder or remove</p>
                <ol class="upload-list" id="existingMediaList">
                  <?php foreach ((array) $editEntry['slides'] as $slide): ?>
                    <?php if ((int) ($slide['id'] ?? 0) < 1) continue; ?>
                    <?php $slideName = trim((string) ($slide['original_name'] ?? '')) ?: basename((string) ($slide['display_path'] ?? 'media')); ?>
                    <li draggable="true" data-slide-id="<?= (int) ($slide['id'] ?? 0) ?>">
                      <?php if (($slide['media_type'] ?? '') === 'video'): ?>
                        <video class="slide-thumb" src="<?= htmlspecialchars((string) ($slide['display_path'] ?? '')) ?>" muted preload="metadata"></video>
                      <?php else: ?>
                        <img class="slide-thumb" src="<?= htmlspecialchars((string) ($slide['display_path'] ?? '')) ?>" alt="" />
                      <?php endif; ?>
                      <span class="slide-name"><?= htmlspecialchars($slideName) ?></span>
                      <button class="remove-slide" type="button">remove</button>
                      <input type="hidden" name="keep_slides[]" value="<?= (int) ($slide['id'] ?? 0) ?>" />
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php endif; ?>
              <label class="upload-dropzone" for="mediaInput" id="uploadDropzone">
                <strong>drop images or videos here</strong>
                <span>or click to choose up to 20 files</span>
              </label>
              <input class="upload-input" id="mediaInput" type="file" name="media[]" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,video/mp4,video/quicktime,video/mpeg,video/x-msvideo" multiple />
              <p class="upload-status" id="uploadStatus">files will be saved in the order shown.</p>
              <ol class="upload-list" id="uploadList"></ol>
            </div>

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
                  <?php $entrySlides = (array) ($entry['slides'] ?? []); $firstSlide = $entrySlides[0] ?? []; ?>
                  <?php if (!empty($firstSlide['display_path'])): ?>
                    <?php if (($firstSlide['media_type'] ?? '') === 'video'): ?>
                      <video class="entry-thumbnail" src="<?= htmlspecialchars((string) $firstSlide['display_path']) ?>" muted preload="metadata"></video>
                    <?php else: ?>
                      <img class="entry-thumbnail" src="<?= htmlspecialchars((string) $firstSlide['display_path']) ?>" alt="" />
                    <?php endif; ?>
                  <?php else: ?>
                    <div class="entry-thumbnail entry-thumbnail--empty" aria-hidden="true">no media</div>
                  <?php endif; ?>
                  <div class="entry-copy">
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
                  </div>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>

    <script>
      const uploadInput = document.getElementById('mediaInput');
      const uploadDropzone = document.getElementById('uploadDropzone');
      const uploadList = document.getElementById('uploadList');
      const uploadStatus = document.getElementById('uploadStatus');
      let selectedFiles = [];

      function syncUploadInput() {
        const transfer = new DataTransfer();
        selectedFiles.forEach((file) => transfer.items.add(file));
        uploadInput.files = transfer.files;
        uploadStatus.textContent = selectedFiles.length + ' file' + (selectedFiles.length === 1 ? '' : 's') + ' selected; drag to set the slide order.';
        uploadList.innerHTML = '';
        selectedFiles.forEach((file, index) => {
          const item = document.createElement('li');
          item.draggable = true;
          item.dataset.index = String(index);
          item.textContent = (index + 1) + '. ' + file.name;
          item.addEventListener('dragstart', () => item.classList.add('is-dragging'));
          item.addEventListener('dragend', () => item.classList.remove('is-dragging'));
          item.addEventListener('dragover', (event) => event.preventDefault());
          item.addEventListener('drop', (event) => {
            event.preventDefault();
            const from = Number(uploadList.querySelector('.is-dragging')?.dataset.index);
            const to = Number(item.dataset.index);
            if (!Number.isInteger(from) || from === to) return;
            const [file] = selectedFiles.splice(from, 1);
            selectedFiles.splice(to, 0, file);
            syncUploadInput();
          });
          uploadList.appendChild(item);
        });
      }

      uploadInput.addEventListener('change', () => {
        selectedFiles = [...uploadInput.files].slice(0, 20);
        syncUploadInput();
      });

      ['dragenter', 'dragover'].forEach((eventName) => uploadDropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        uploadDropzone.classList.add('is-dragging');
      }));
      ['dragleave', 'drop'].forEach((eventName) => uploadDropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        uploadDropzone.classList.remove('is-dragging');
      }));
      uploadDropzone.addEventListener('drop', (event) => {
        selectedFiles = [...event.dataTransfer.files].slice(0, 20);
        syncUploadInput();
      });

      const existingMediaList = document.getElementById('existingMediaList');
      if (existingMediaList) {
        let draggedSlide = null;
        existingMediaList.querySelectorAll('li').forEach((item) => {
          item.addEventListener('dragstart', () => {
            draggedSlide = item;
            item.classList.add('is-dragging');
          });
          item.addEventListener('dragend', () => {
            draggedSlide = null;
            item.classList.remove('is-dragging');
          });
          item.addEventListener('dragover', (event) => event.preventDefault());
          item.addEventListener('drop', (event) => {
            event.preventDefault();
            if (!draggedSlide || draggedSlide === item) return;
            const items = [...existingMediaList.children];
            const from = items.indexOf(draggedSlide);
            const to = items.indexOf(item);
            if (from < to) {
              item.after(draggedSlide);
            } else {
              item.before(draggedSlide);
            }
          });
          item.querySelector('.remove-slide').addEventListener('click', () => {
            item.remove();
          });
        });
      }
    </script>
  </body>
</html>

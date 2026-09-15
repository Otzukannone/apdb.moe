<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

if (empty($_SESSION['apdb_admin'])) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'notice' => 'your session expired — log in again, then retry.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ./index.php');
    exit;
}

require_once __DIR__ . '/../lib/atelier-db.php';
$database = atelierDatabase();
atelierEnsureJsonImport($database);
$entries = atelierEntries($database);
$notice = '';
$editEntry = null;

// one-shot token per page load: blocks accidental double "add entry" submissions
if (empty($_SESSION['atelier_form_token']) || !empty($_SESSION['atelier_form_token_used'])) {
    $_SESSION['atelier_form_token'] = bin2hex(random_bytes(8));
    $_SESSION['atelier_form_token_used'] = false;
}
$formToken = (string) $_SESSION['atelier_form_token'];
$ajaxOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = trim((string) ($_POST['id'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $year = trim((string) ($_POST['year'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $image = trim((string) ($_POST['image'] ?? ''));
    $tags = trim((string) ($_POST['tags'] ?? ''));
    $uploadedFiles = $_FILES['media'] ?? [];

    if ($isAjax && $action === '') {
        // the request body never reached php — usually an upload past the server limits
        $ajaxOk = false;
        $notice = 'upload rejected — the files are too large for the server limit.';
    } elseif ($action === 'delete' && $id !== '') {
        atelierDeleteEntry($database, $id);
        $notice = 'entry deleted.';
    } elseif ($action === 'create' || $action === 'save') {
        if ($action === 'create' && (string) ($_POST['form_token'] ?? '') !== $formToken) {
            $notice = 'duplicate blocked — this entry was already submitted. reload the page to start a new one.';
        } elseif ($title === '') {
            $ajaxOk = false;
            $notice = 'title is required.';
        } else {
            if ($action === 'create') {
                $_SESSION['atelier_form_token_used'] = true;
            }
            $tagList = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $tags) ?: []), static function ($tag) { return $tag !== ''; }));

            $entryId = $action === 'save' && $id !== '' ? $id : atelierGenerateId($database);
            $uploadNotice = '';
            atelierSaveEntry($database, [
              'id' => $entryId,
              'title' => $title,
              'year' => $year,
              'description' => $description,
              'image' => $image,
              'tags' => $tagList,
            ]);
            $hasUploadedFiles = array_filter((array) ($uploadedFiles['name'] ?? []), static function ($name) { return trim((string) $name) !== ''; });
            if ($hasUploadedFiles !== []) {
              try {
                $keptSlides = array_map('intval', (array) ($_POST['keep_slides'] ?? []));
                $keptCount = atelierUpdateSlideOrder($database, $entryId, $keptSlides);
                $customNames = array_map('strval', (array) ($_POST['media_names'] ?? []));
                atelierStoreUploads($database, $entryId, $uploadedFiles, $keptCount, array_values($customNames));
              } catch (RuntimeException $error) {
                $uploadNotice = $error->getMessage();
              }
            } elseif ($action === 'save') {
              atelierUpdateSlideOrder($database, $entryId, array_map('intval', (array) ($_POST['keep_slides'] ?? [])));
            }
            atelierUpdateSlideNames($database, $entryId, (array) ($_POST['slide_names'] ?? []));
            $notice = $uploadNotice !== '' ? $uploadNotice : ($action === 'save' ? 'entry updated.' : 'entry added.');
            $entries = atelierEntries($database);
        }
    }
}

if ($isAjax) {
    // rotate the one-shot token on every ajax response so the same page can
    // keep submitting without a reload race (kills stale-token failures)
    $_SESSION['atelier_form_token'] = bin2hex(random_bytes(8));
    $_SESSION['atelier_form_token_used'] = false;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ajaxOk, 'notice' => $notice, 'token' => $_SESSION['atelier_form_token']], JSON_UNESCAPED_UNICODE);
    exit;
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

      button[type="submit"]:disabled {
        opacity: 0.55;
        cursor: wait;
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

            .upload-list li {
        position: relative;
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

      .upload-list li {
        position: relative;
        padding-right: 5px;
      }

      .upload-list li::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(13,19,35,0) 60%, rgba(13,19,35,0.05) 80%, rgba(13,19,35,0.16) 100%);
        opacity: 0;
        transition: opacity 150ms ease;
        pointer-events: none;
      }

      .upload-list li:hover::after {
        opacity: 1;
      }

      .upload-list li .tile-btn {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        margin-left: -2px;
        padding: 0;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: var(--ink);
        cursor: pointer;
        opacity: 0;
        visibility: hidden;
        transition: opacity 150ms ease, visibility 150ms ease;
      }

      .upload-list li:hover .tile-btn {
        opacity: 1;
        visibility: visible;
      }

      .upload-list li .tile-btn svg {
        display: block;
        width: 13px;
        height: 13px;
      }

      .upload-list li .tile-btn-rename {
        color: #2456c4;
      }

      .upload-list li .tile-btn-rename:hover {
        background: rgba(42,91,255,0.14);
      }

      .upload-list li .tile-btn-remove {
        color: #b41414;
      }

      .upload-list li .tile-btn-remove:hover {
        background: rgba(180,20,20,0.12);
      }

      .upload-list li .rename-input {
        flex: 1 1 auto;
        width: auto;
        min-width: 0;
        padding: 3px 7px;
        font: inherit;
        font-size: 0.78rem;
        border: 1px solid rgba(42,91,255,0.55);
        border-radius: 6px;
        background: rgba(255,255,255,0.9);
        color: var(--ink);
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

      .entries-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-top: 16px;
        margin-bottom: 18px;
      }

      .entries-head h2 {
        margin: 0;
      }

      .entries {
        display: grid;
        gap: 14px;
      }

      .entry {
        position: relative;
        min-width: 0;
        min-height: 150px;
        border: 1px solid rgba(12,14,29,0.12);
        border-radius: 14px;
        background: rgba(255,255,255,0.3);
        padding: 14px 14px 12px;
      }

      .entry-copy {
        min-width: 0;
        padding-right: 118px;
        overflow-wrap: anywhere;
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

      .upload-progress {
        display: grid;
        gap: 8px;
        border: 1px solid rgba(12,14,29,0.18);
        border-radius: 12px;
        background: rgba(255,255,255,0.45);
        padding: 12px 14px;
      }

      .upload-progress[hidden] {
        display: none;
      }

      .upload-progress-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 10px;
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        text-transform: lowercase;
      }

      .upload-progress-head span:last-child {
        font-variant-numeric: tabular-nums;
      }

      .upload-progress-track {
        height: 8px;
        border-radius: 999px;
        background: rgba(12,14,29,0.12);
        overflow: hidden;
      }

      .upload-progress-fill {
        width: 0%;
        height: 100%;
        border-radius: 999px;
        background: #0d1323;
        transition: width 160ms ease;
      }

      .upload-progress.is-processing .upload-progress-fill {
        width: 100%;
        background: linear-gradient(90deg, rgba(13,19,35,0.30), rgba(13,19,35,0.92), rgba(13,19,35,0.30));
        background-size: 200% 100%;
        animation: uploadShimmer 1.1s linear infinite;
      }

      .upload-progress.is-done .upload-progress-fill {
        background: rgba(46,158,68,0.9);
      }

      .upload-progress.is-error {
        border-color: rgba(180,20,20,0.4);
      }

      .upload-progress.is-error .upload-progress-fill {
        background: rgba(180,20,20,0.75);
      }

      .upload-progress-hint {
        margin: 0;
        font-size: 0.66rem;
        letter-spacing: 0.03em;
        color: rgba(12,14,29,0.62);
        text-transform: none;
      }

      @keyframes uploadShimmer {
        from { background-position: 0% 0; }
        to { background-position: 200% 0; }
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

          <form method="post" action="./atelier.php" enctype="multipart/form-data" id="entryForm">
            <input type="hidden" name="action" value="<?= $editEntry ? 'save' : 'create' ?>" />
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>" />
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
              <?php if ($editEntry && array_filter((array) ($editEntry['slides'] ?? []), static function ($slide) { return (int) ($slide['id'] ?? 0) > 0; }) !== []): ?>
                <p class="existing-media-title">current media: drag to reorder or remove</p>
                <ol class="upload-list" id="existingMediaList">
                  <?php foreach ((array) $editEntry['slides'] as $slide): ?>
                    <?php if ((int) ($slide['id'] ?? 0) < 1) continue; ?>
                    <?php $slideName = trim((string) ($slide['original_name'] ?? '')) ?: basename((string) ($slide['display_path'] ?? 'media')); ?>
                    <li draggable="true" data-slide-id="<?= (int) ($slide['id'] ?? 0) ?>">
                      <?php if (($slide['media_type'] ?? '') === 'video'): ?>
                                                                    <?php if (!empty($slide['thumbnail_path'])): ?>
                        <img class="slide-thumb" src="<?= htmlspecialchars((string) $slide['thumbnail_path']) ?>" alt="" />
                      <?php else: ?>
                        <video class="slide-thumb" src="<?= htmlspecialchars((string) $slide['display_path']) ?>" muted preload="metadata"></video>
                      <?php endif; ?>
                      <?php else: ?>
                        <img class="slide-thumb" src="<?= htmlspecialchars((string) ($slide['display_path'] ?? '')) ?>" alt="" />
                      <?php endif; ?>
                      <span class="slide-name"><?= htmlspecialchars($slideName) ?></span>
                      <button class="tile-btn tile-btn-rename" type="button" title="rename file"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.3 2.6l2.1 2.1L5.2 12.9l-2.7.6.6-2.7z"/><path d="M9.6 4.3l2.1 2.1"/></svg></button>
                      <button class="remove-slide" type="button">remove</button>
                      <input type="hidden" name="keep_slides[]" value="<?= (int) ($slide['id'] ?? 0) ?>" />
                      <input type="hidden" name="slide_names[<?= (int) ($slide['id'] ?? 0) ?>]" value="<?= htmlspecialchars($slideName) ?>" />
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

            <div class="upload-progress" id="uploadProgress" hidden>
              <div class="upload-progress-head">
                <span id="uploadProgressLabel">working…</span>
                <span id="uploadProgressPercent"></span>
              </div>
              <div class="upload-progress-track">
                <div class="upload-progress-fill" id="uploadProgressFill"></div>
              </div>
              <p class="upload-progress-hint" id="uploadProgressHint">keep this tab open — the entry saves itself when the bar fills.</p>
            </div>
          </form>
        </section>

        <section class="panel">
          <div class="entries-head">
            <h2>entries</h2>
            <a class="link-button" href="./atelier.php">new entry</a>
          </div>
          <div class="entries">
            <?php if (empty($entries)): ?>
              <div class="notice">no entries yet.</div>
            <?php else: ?>
              <?php foreach ($entries as $entry): ?>
                <article class="entry">
                  <?php $entrySlides = (array) ($entry['slides'] ?? []); $firstSlide = $entrySlides[0] ?? []; ?>
                  <?php if (!empty($firstSlide['display_path'])): ?>
                    <?php if (($firstSlide['media_type'] ?? '') === 'video'): ?>
                      <?php if (!empty($firstSlide['thumbnail_path'])): ?>
                        <img class="entry-thumbnail" src="<?= htmlspecialchars((string) $firstSlide['thumbnail_path']) ?>" alt="" />
                      <?php else: ?>
                        <video class="entry-thumbnail" src="<?= htmlspecialchars((string) $firstSlide['display_path']) ?>" muted preload="metadata"></video>
                      <?php endif; ?>
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
      let selectedNames = [];
      let tileObjectUrls = [];
      const pencilIcon = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.3 2.6l2.1 2.1L5.2 12.9l-2.7.6.6-2.7z"/><path d="M9.6 4.3l2.1 2.1"/></svg>';
      const trashIcon = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>';

      function appendFiles(pickedFiles) {
        const room = Math.max(0, 20 - selectedFiles.length);
        const accepted = pickedFiles.slice(0, room);
        selectedFiles = selectedFiles.concat(accepted);
        selectedNames = selectedNames.concat(accepted.map((file) => file.name));
        return pickedFiles.length - accepted.length;
      }

      function createTileThumb(file) {
        if (file.type.startsWith('video/')) {
          const url = URL.createObjectURL(file);
          tileObjectUrls.push(url);
          const video = document.createElement('video');
          video.className = 'slide-thumb';
          video.muted = true;
          video.preload = 'metadata';
          video.src = url;
          return video;
        }
        if (file.type.startsWith('image/')) {
          const url = URL.createObjectURL(file);
          tileObjectUrls.push(url);
          const image = document.createElement('img');
          image.className = 'slide-thumb';
          image.alt = '';
          image.src = url;
          return image;
        }
        return null;
      }

      function syncUploadInput(extraNote) {
        const transfer = new DataTransfer();
        selectedFiles.forEach((file) => transfer.items.add(file));
        uploadInput.files = transfer.files;
        uploadStatus.textContent = selectedFiles.length + ' file' + (selectedFiles.length === 1 ? '' : 's') + ' selected; drag to set the slide order.' + (extraNote ? ' ' + extraNote : '');
        tileObjectUrls.splice(0).forEach((url) => URL.revokeObjectURL(url));
        uploadList.innerHTML = '';
        selectedFiles.forEach((file, index) => {
          const displayName = selectedNames[index] || file.name;
          const item = document.createElement('li');
          item.draggable = true;
          item.dataset.index = String(index);

          const thumb = createTileThumb(file);
          if (thumb) {
            item.append(thumb);
          }

          const name = document.createElement('span');
          name.className = 'slide-name';
          name.textContent = (index + 1) + '. ' + displayName;

          const renameButton = document.createElement('button');
          renameButton.type = 'button';
          renameButton.className = 'tile-btn tile-btn-rename';
          renameButton.title = 'rename file';
          renameButton.innerHTML = pencilIcon;

          const removeButton = document.createElement('button');
          removeButton.type = 'button';
          removeButton.className = 'tile-btn tile-btn-remove';
          removeButton.title = 'remove file';
          removeButton.innerHTML = trashIcon;

          const nameField = document.createElement('input');
          nameField.type = 'hidden';
          nameField.name = 'media_names[]';
          nameField.value = displayName;

          item.append(name, renameButton, removeButton, nameField);

          renameButton.addEventListener('click', () => beginRename(item, index));
          removeButton.addEventListener('click', () => {
            if (!window.confirm('trash "' + displayName + '" from this upload?')) return;
            selectedFiles.splice(index, 1);
            selectedNames.splice(index, 1);
            syncUploadInput();
          });

          item.addEventListener('dragstart', () => item.classList.add('is-dragging'));
          item.addEventListener('dragend', () => item.classList.remove('is-dragging'));
          item.addEventListener('dragover', (event) => event.preventDefault());
          item.addEventListener('drop', (event) => {
            event.preventDefault();
            const from = Number(uploadList.querySelector('.is-dragging')?.dataset.index);
            const to = Number(item.dataset.index);
            if (!Number.isInteger(from) || from === to) return;
            const [movedFile] = selectedFiles.splice(from, 1);
            const [movedName] = selectedNames.splice(from, 1);
            selectedFiles.splice(to, 0, movedFile);
            selectedNames.splice(to, 0, movedName);
            syncUploadInput();
          });
          uploadList.appendChild(item);
        });
      }

      function beginRename(item, index) {
        if (uploadList.querySelector('.rename-input')) return;
        const nameSpan = item.querySelector('.slide-name');
        if (!nameSpan) return;
        const editor = document.createElement('input');
        editor.type = 'text';
        editor.className = 'rename-input';
        editor.value = selectedNames[index] || selectedFiles[index].name;
        nameSpan.replaceWith(editor);
        item.draggable = false;
        editor.focus();
        editor.select();

        let settled = false;
        const settle = (save) => {
          if (settled) return;
          settled = true;
          if (save) {
            const cleaned = editor.value.trim();
            if (cleaned !== '') {
              selectedNames[index] = cleaned;
            }
          }
          item.draggable = true;
          syncUploadInput();
        };

        editor.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            settle(true);
          } else if (event.key === 'Escape') {
            settle(false);
          }
        });
        editor.addEventListener('blur', () => settle(true));
      }

      uploadInput.addEventListener('change', () => {
        const picked = [...uploadInput.files];
        uploadInput.value = '';
        if (picked.length === 0) return;
        const skipped = appendFiles(picked);
        syncUploadInput(skipped > 0 ? 'the 20-file limit skipped ' + skipped + '.' : '');
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
        const picked = [...event.dataTransfer.files];
        if (picked.length === 0) return;
        const skipped = appendFiles(picked);
        syncUploadInput(skipped > 0 ? 'the 20-file limit skipped ' + skipped + '.' : '');
      });

      function beginExistingRename(item) {
        if (document.querySelector('.rename-input')) return;
        const nameSpan = item.querySelector('.slide-name');
        const nameField = item.querySelector('input[name^="slide_names"]');
        if (!nameSpan || !nameField) return;
        const editor = document.createElement('input');
        editor.type = 'text';
        editor.className = 'rename-input';
        editor.value = nameField.value;
        nameSpan.replaceWith(editor);
        item.draggable = false;
        editor.focus();
        editor.select();

        let settled = false;
        const settle = (save) => {
          if (settled) return;
          settled = true;
          if (save) {
            const cleaned = editor.value.trim();
            if (cleaned !== '') {
              nameField.value = cleaned;
            }
          }
          const updated = document.createElement('span');
          updated.className = 'slide-name';
          updated.textContent = nameField.value;
          editor.replaceWith(updated);
          item.draggable = true;
        };

        editor.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            settle(true);
          } else if (event.key === 'Escape') {
            settle(false);
          }
        });
        editor.addEventListener('blur', () => settle(true));
      }

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
          const existingRenameButton = item.querySelector('.tile-btn-rename');
          if (existingRenameButton) {
            existingRenameButton.addEventListener('click', () => beginExistingRename(item));
          }
          item.querySelector('.remove-slide').addEventListener('click', () => {
            item.remove();
          });
        });
      }

      const entryForm = document.getElementById('entryForm');
      const submitButton = entryForm ? entryForm.querySelector('button[type="submit"]') : null;
      const progressBox = document.getElementById('uploadProgress');
      const progressLabel = document.getElementById('uploadProgressLabel');
      const progressPercent = document.getElementById('uploadProgressPercent');
      const progressFill = document.getElementById('uploadProgressFill');
      const progressHint = document.getElementById('uploadProgressHint');
      let isUploading = false;

      function formatBytes(bytes) {
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
      }

      function setProgressState(mode, label, percent) {
        if (!progressBox) return;
        progressBox.hidden = false;
        progressBox.classList.remove('is-processing', 'is-error', 'is-done');
        if (mode !== 'uploading') {
          progressBox.classList.add('is-' + mode);
        }
        progressLabel.textContent = label;
        if (typeof percent === 'number') {
          progressPercent.textContent = Math.round(percent) + '%';
          progressFill.style.width = Math.min(100, Math.max(0, percent)) + '%';
        }
      }

      function releaseForm(message) {
        isUploading = false;
        if (submitButton) submitButton.disabled = false;
        if (message) setProgressState('error', message, 100);
      }

      if (entryForm && submitButton) {
        entryForm.addEventListener('submit', (event) => {
          event.preventDefault();
          if (isUploading) return; // one upload at a time — no double entries
          isUploading = true;
          submitButton.disabled = true;

          const files = [...uploadInput.files];
          const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
          const summary = files.length > 0
            ? 'uploading ' + files.length + ' file' + (files.length === 1 ? '' : 's') + ' · ' + formatBytes(totalBytes)
            : 'saving entry…';
          setProgressState('uploading', summary, files.length > 0 ? 0 : null);

          const data = new FormData(entryForm);
          const xhr = new XMLHttpRequest();
          // read the raw attribute: a control named "action" inside the form
          // shadows the form.action DOM property (classic named-access footgun)
          const actionUrl = entryForm.getAttribute('action') || './atelier.php';
          xhr.open('POST', actionUrl, true);
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

          xhr.upload.addEventListener('progress', (progressEvent) => {
            if (!progressEvent.lengthComputable || files.length === 0) return;
            const percent = (progressEvent.loaded / progressEvent.total) * 100;
            setProgressState('uploading', summary, percent);
          });

          xhr.upload.addEventListener('load', () => {
            if (files.length > 0) {
              setProgressState('processing', 'upload complete — server is processing the media…', 100);
            }
          });

          xhr.addEventListener('load', () => {
            let payload = null;
            try {
              const raw = xhr.responseText;
              const start = raw.indexOf('{');
              payload = JSON.parse(start > 0 ? raw.slice(start) : raw);
            } catch (error) {
              payload = null;
            }
            if (payload && typeof payload.token === 'string' && payload.token !== '') {
              const tokenField = entryForm.querySelector('input[name="form_token"]');
              if (tokenField) tokenField.value = payload.token;
            }
            if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
              setProgressState('done', (payload.notice || 'saved') + ' — refreshing…', 100);
              if (progressHint) progressHint.textContent = 'all done — bringing your entry back up…';
              setTimeout(() => window.location.reload(), 800);
            } else if (payload && !payload.ok) {
              releaseForm(payload.notice || 'the upload was rejected — nothing was saved.');
            } else if (xhr.status === 401 || (xhr.responseURL && xhr.responseURL.indexOf('index.php') !== -1)) {
              releaseForm('your session expired — log in again, then retry. your files are still in the form.');
            } else if (xhr.status >= 200 && xhr.status < 300) {
              const finalUrl = xhr.responseURL || '';
              const body = (xhr.responseText || '').replace(/\s+/g, ' ').trim();
              if (finalUrl.indexOf('dashboard.php') !== -1 || finalUrl.indexOf('index.php') !== -1) {
                releaseForm('the upload bounced to the dashboard — the server does not recognize the admin session, or it is serving an outdated atelier page.');
                if (progressHint) progressHint.textContent = 'the upload was redirected to ' + finalUrl + ' instead of saving. restart the php server in this site folder, log in again, then retry.';
              } else {
                releaseForm('unexpected server answer (status ' + xhr.status + ') — details below.');
                if (progressHint) progressHint.textContent = 'server replied: ' + (body !== '' ? body.slice(0, 400) : '(empty body — is the php server still running?)');
              }
            } else {
              const body = (xhr.responseText || '').replace(/\s+/g, ' ').trim();
              releaseForm('upload failed — status ' + xhr.status + '. details below.');
              if (progressHint) progressHint.textContent = 'server replied: ' + (body !== '' ? body.slice(0, 400) : '(no response body — check that the php server is running)');
            }
          });

          xhr.addEventListener('error', () => releaseForm('network error — the upload did not finish. nothing was saved.'));
          xhr.addEventListener('abort', () => releaseForm('upload cancelled. nothing was saved.'));
          xhr.send(data);
        });

        window.addEventListener('beforeunload', (unloadEvent) => {
          if (isUploading) {
            unloadEvent.preventDefault();
            unloadEvent.returnValue = '';
          }
        });
      }
    </script>
  </body>
</html>

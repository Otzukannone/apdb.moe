<?php
session_start();

$dataFile = __DIR__ . '/../data/atelierphischers.json';
$isAdmin = !empty($_SESSION['apdb_admin']);

$entries = [];
if (file_exists($dataFile)) {
    $raw = file_get_contents($dataFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $entries = $decoded;
    }
}

usort($entries, static function ($a, $b) {
    $yearA = (int) ($a['year'] ?? 0);
    $yearB = (int) ($b['year'] ?? 0);
    return $yearB <=> $yearA;
});
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>atelierphischers — apdb.moe</title>
    <link rel="icon" type="image/png" href="../assets/yuyuk.png" />
    <link rel="stylesheet" href="../shared.css" />
    <style>
      .mode-wrap {
        display: inline-flex;
        align-items: center;
        gap: 10px;
      }

      .view-toggle {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px;
        border: 1px solid var(--line);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.36);
      }

      .admin-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 74px;
        height: 34px;
        padding: 0 14px;
        border: 1px solid var(--line);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.36);
        color: var(--ink);
        font: inherit;
        font-size: 0.72rem;
        letter-spacing: 0.04em;
        text-transform: lowercase;
      }

      .view-btn {
        appearance: none;
        border: 0;
        background: transparent;
        color: var(--ink);
        border-radius: 999px;
        padding: 5px 10px;
        min-width: 52px;
        font: inherit;
        font-size: 0.72rem;
        letter-spacing: 0.04em;
        text-transform: lowercase;
        cursor: pointer;
        transition: all 140ms ease;
      }

      .view-btn.active {
        background: var(--ink);
        color: #fff;
      }

      .gallery-grid {
        --grid-columns: 5;
        display: grid;
        grid-template-columns: repeat(var(--grid-columns), minmax(0, 1fr));
        gap: 6px;
        border-left: 1px solid var(--line);
        background: rgba(255, 255, 255, 0.15);
        padding: 0;
      }

      .gallery-grid[data-size="default"] {
        --grid-columns: 5;
      }

      .gallery-grid[data-size="wide"] {
        --grid-columns: 3;
      }

      body.list-mode .gallery-grid {
        grid-template-columns: 1fr;
        border-left: 0;
      }

      body.list-mode .post-card {
        aspect-ratio: auto;
        min-height: 120px;
      }

      .post-card {
        position: relative;
        width: 100%;
        aspect-ratio: 1 / 1;
        border: 1px solid var(--line);
        background: rgba(23, 27, 38, 0.02);
        overflow: hidden;
        cursor: pointer;
        padding: 0;
        text-align: left;
        color: var(--ink);
      }

      .post-card.photo-card {
        background-size: cover;
        background-position: center;
      }

      .post-card.placeholder {
        background: linear-gradient(135deg, rgba(12,14,29,0.06), rgba(12,14,29,0.03));
      }

      .post-card__label {
        position: absolute;
        left: 12px;
        bottom: 10px;
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        text-transform: lowercase;
        color: rgba(13, 19, 35, 0.7);
        z-index: 2;
      }

      .post-card.photo-card .post-card__label {
        color: #f8f9fb;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
      }

      .post-card.photo-card::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(12, 14, 29, 0), rgba(12, 14, 29, 0.5));
      }

      .post-card .card-admin-actions {
        position: absolute;
        right: 10px;
        bottom: 10px;
        left: auto;
        top: auto;
        z-index: 4;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        padding: 0;
        border: 0;
        background: transparent;
        opacity: 0;
        visibility: hidden;
        transform: none;
        transition: all 120ms ease;
      }

      .post-card:hover .card-admin-actions,
      .post-card:focus .card-admin-actions,
      .post-card:focus-within .card-admin-actions {
        opacity: 1;
        visibility: visible;
        transform: none;
      }

      .card-admin-actions button,
      .card-admin-actions a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        padding: 0;
        border: 1px solid rgba(13, 19, 35, 0.18);
        background: rgba(255, 255, 255, 0.85);
        color: var(--ink);
        font-size: 0.9rem;
        cursor: pointer;
        text-decoration: none;
        border-radius: 4px;
      }

      .card-admin-actions button:hover,
      .card-admin-actions a:hover {
        background: rgba(13, 19, 35, 0.08);
      }

      .card-admin-actions form {
        display: inline;
      }

      .media-modal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 50;
        padding: 24px;
      }

      .media-modal.open {
        display: flex;
      }

      .media-modal__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(12, 14, 29, 0.72);
      }

      .media-modal__panel {
        position: relative;
        z-index: 1;
        width: min(840px, calc(100vw - 32px));
        max-height: 86vh;
        display: grid;
        grid-template-columns: minmax(280px, 1.2fr) minmax(220px, 0.8fr);
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(255, 255, 255, 0.94);
        overflow: hidden;
        box-shadow: 0 30px 80px rgba(0, 0, 0, 0.28);
      }

      .media-modal__image {
        min-height: 420px;
        background: rgba(13, 19, 35, 0.08);
        background-size: cover;
        background-position: center;
      }

      .media-modal__content {
        padding: 24px 22px 20px;
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .media-modal__meta {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        font-size: 0.7rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--muted);
      }

      .media-modal__content h2 {
        margin: 0;
        font-size: clamp(1.7rem, 3vw, 2.6rem);
        letter-spacing: -0.06em;
        line-height: 1;
      }

      .media-modal__content p {
        margin: 0;
        font-size: 0.96rem;
        line-height: 1.7;
        color: rgba(13, 19, 35, 0.76);
      }

      .media-modal__tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: auto;
      }

      .media-modal__tag {
        border: 1px solid var(--line);
        border-radius: 999px;
        padding: 5px 8px;
        font-size: 0.68rem;
        letter-spacing: 0.08em;
        text-transform: lowercase;
      }

      .media-modal__close {
        position: absolute;
        top: 12px;
        right: 12px;
        z-index: 2;
        width: 34px;
        height: 34px;
        border: 1px solid rgba(13, 19, 35, 0.2);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.9);
        color: var(--ink);
        font-size: 1.3rem;
        cursor: pointer;
      }

      .media-editor-fields {
        display: grid;
        gap: 10px;
      }

      .media-editor-fields label {
        display: grid;
        gap: 6px;
        font-size: 0.7rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(13, 19, 35, 0.7);
      }

      .media-editor-fields input,
      .media-editor-fields textarea {
        width: 100%;
        border: 1px solid rgba(13, 19, 35, 0.18);
        border-radius: 10px;
        background: rgba(255,255,255,0.4);
        color: var(--ink);
        padding: 10px 12px;
        font: inherit;
      }

      .media-editor-fields textarea {
        resize: vertical;
        min-height: 110px;
      }

      .media-editor-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 14px;
      }

      .media-editor-actions button {
        border: 1px solid rgba(13, 19, 35, 0.18);
        border-radius: 999px;
        background: #0d1323;
        color: white;
        padding: 10px 16px;
        font: inherit;
        cursor: pointer;
      }

      .media-editor-actions .secondary {
        background: transparent;
        color: var(--ink);
      }

      @media (max-width: 760px) {
        .media-modal__panel {
          grid-template-columns: 1fr;
        }

        .media-modal__image {
          min-height: 260px;
        }
      }
    </style>
  </head>
  <body class="atelier-mode grid-mode">
    <div class="site-shell">
      <header class="topbar">
        <div class="brand">atelierphischers</div>
        <nav class="nav" aria-label="Main navigation">
          <a href="../index.html">home</a>
          <a href="../what/">what</a>
          <a href="../booru/">booru</a>
          <a href="./">atelierphischers</a>
          <a href="../tba/">tba</a>
        </nav>
      </header>

      <main class="atelier-page">
        <div class="toolbar">
          <div class="toolbar-title">
            <span class="kicker">creative archive</span>
          </div>

          <div class="mode-wrap">
            <div class="view-toggle" aria-label="View mode toggle">
              <button class="view-btn active" data-mode="grid" type="button">grid</button>
              <button class="view-btn" data-mode="list" type="button">list</button>
            </div>
            <?php if ($isAdmin): ?>
              <a class="admin-link" href="../admin/atelier.php">admin</a>
            <?php endif; ?>
          </div>
        </div>

        <div class="gallery-grid" id="galleryGrid">
          <?php foreach ($entries as $entry): ?>
            <?php
              $title = htmlspecialchars((string) ($entry['title'] ?? 'untitled'), ENT_QUOTES, 'UTF-8');
              $year = htmlspecialchars((string) ($entry['year'] ?? 'draft'), ENT_QUOTES, 'UTF-8');
              $description = htmlspecialchars((string) ($entry['description'] ?? ''), ENT_QUOTES, 'UTF-8');
              $image = trim((string) ($entry['image'] ?? ''));
              $tags = (array) ($entry['tags'] ?? []);
              $tagCsv = implode(',', array_map(static fn ($tag) => trim((string) $tag), $tags));
              $entryId = htmlspecialchars((string) ($entry['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            ?>
            <div
              class="post-card <?= $image !== '' ? 'photo-card' : 'placeholder' ?>"
              style="<?= $image !== '' ? 'background-image: url(' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . ');' : '' ?>"
              data-id="<?= $entryId ?>"
              data-title="<?= $title ?>"
              data-year="<?= $year ?>"
              data-description="<?= $description !== '' ? $description : 'no notes yet.' ?>"
              data-image="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
              data-tags="<?= htmlspecialchars($tagCsv, ENT_QUOTES, 'UTF-8') ?>"
              aria-label="Open <?= $title ?>"
              tabindex="0"
              role="button"
            >
              <?php if ($isAdmin): ?>
                <span class="card-admin-actions" aria-label="Admin actions">
                  <button type="button" class="edit-card-button" data-id="<?= $entryId ?>" aria-label="Edit <?= $title ?>" title="Edit">✎</button>
                  <form method="post" action="../admin/atelier.php" onsubmit="return confirm('Delete this entry?');">
                    <input type="hidden" name="action" value="delete" />
                    <input type="hidden" name="id" value="<?= $entryId ?>" />
                    <button type="submit" aria-label="Delete <?= $title ?>" title="Delete">🗑</button>
                  </form>
                </span>
              <?php endif; ?>
              <span class="post-card__label"><?= $title ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </main>

      <footer>apdb.moe / atelierphischers</footer>
    </div>

    <div class="media-modal" id="mediaEditorModal" aria-hidden="true">
      <div class="media-modal__backdrop" data-close="true"></div>
      <div class="media-modal__panel" role="dialog" aria-modal="true" aria-labelledby="editorTitle">
        <button class="media-modal__close" type="button" aria-label="Close editor">×</button>
        <div class="media-modal__image" id="editorImagePreview"></div>
        <div class="media-modal__content">
          <div class="media-modal__meta">
            <span>edit entry</span>
          </div>
          <h2 id="editorTitle">edit item</h2>

          <form method="post" action="../admin/atelier.php" id="editorForm">
            <input type="hidden" name="action" value="save" />
            <input type="hidden" name="id" id="editorId" />

            <div class="media-editor-fields">
              <label>
                <span>title</span>
                <input type="text" name="title" id="editorTitleField" required />
              </label>
              <label>
                <span>year</span>
                <input type="text" name="year" id="editorYearField" />
              </label>
              <label>
                <span>image url</span>
                <input type="url" name="image" id="editorImageField" placeholder="https://..." />
              </label>
              <label>
                <span>tags</span>
                <input type="text" name="tags" id="editorTagsField" placeholder="study, mood" />
              </label>
              <label>
                <span>description</span>
                <textarea name="description" id="editorDescriptionField" rows="4"></textarea>
              </label>
            </div>

            <div class="media-editor-actions">
              <button type="submit">save changes</button>
              <button type="button" class="secondary" data-close="true">cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="media-modal" id="mediaModal" aria-hidden="true">
      <div class="media-modal__backdrop" data-close="true"></div>
      <div class="media-modal__panel" role="dialog" aria-modal="true" aria-labelledby="mediaTitle">
        <button class="media-modal__close" type="button" aria-label="Close viewer">×</button>
        <div class="media-modal__image" id="modalImage"></div>
        <div class="media-modal__content">
          <div class="media-modal__meta">
            <span id="modalYear">year</span>
            <span>atelier</span>
          </div>
          <h2 id="mediaTitle">title</h2>
          <p id="mediaDescription">description</p>
          <div class="media-modal__tags" id="modalTags"></div>
        </div>
      </div>
    </div>

    <script>
      const body = document.body;
      const viewButtons = document.querySelectorAll('.view-btn');
      const galleryGrid = document.getElementById('galleryGrid');
      const modal = document.getElementById('mediaModal');
      const modalImage = document.getElementById('modalImage');
      const modalYear = document.getElementById('modalYear');
      const modalTitle = document.getElementById('mediaTitle');
      const modalDescription = document.getElementById('mediaDescription');
      const modalTags = document.getElementById('modalTags');
      const editorModal = document.getElementById('mediaEditorModal');
      const editorImagePreview = document.getElementById('editorImagePreview');
      const editorTitleField = document.getElementById('editorTitleField');
      const editorYearField = document.getElementById('editorYearField');
      const editorImageField = document.getElementById('editorImageField');
      const editorTagsField = document.getElementById('editorTagsField');
      const editorDescriptionField = document.getElementById('editorDescriptionField');
      const editorIdField = document.getElementById('editorId');
      const closeButton = document.querySelector('.media-modal__close');
      const closeTriggers = document.querySelectorAll('[data-close="true"]');

      let currentMode = 'grid';
      let currentDensity = 'default';

      function applyLayout() {
        body.classList.toggle('list-mode', currentMode === 'list');
        body.classList.toggle('grid-mode', currentMode === 'grid');
        galleryGrid.dataset.size = currentDensity;

        viewButtons.forEach((btn) => {
          btn.classList.toggle('active', btn.dataset.mode === currentMode);
        });
      }

      viewButtons.forEach((button) => {
        button.addEventListener('click', () => {
          if (button.dataset.mode === 'grid' && currentMode === 'grid') {
            currentDensity = currentDensity === 'default' ? 'wide' : 'default';
            applyLayout();
            return;
          }

          currentMode = button.dataset.mode;
          if (currentMode === 'grid' && currentDensity === 'default') {
            currentDensity = 'default';
          }
          applyLayout();
        });
      });

      function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
      }

      function closeEditor() {
        editorModal.classList.remove('open');
        editorModal.setAttribute('aria-hidden', 'true');
      }

      function openEditor(card) {
        const id = card.dataset.id || '';
        const title = card.dataset.title || 'untitled';
        const year = card.dataset.year || 'draft';
        const description = card.dataset.description || '';
        const image = card.dataset.image || '';
        const tags = (card.dataset.tags || '').split(',').map((tag) => tag.trim()).filter(Boolean).join(', ');

        editorIdField.value = id;
        editorTitleField.value = title;
        editorYearField.value = year;
        editorDescriptionField.value = description;
        editorImageField.value = image;
        editorTagsField.value = tags;

        if (image) {
          editorImagePreview.style.backgroundImage = 'url("' + image + '")';
          editorImagePreview.style.backgroundColor = '#dfe4ea';
        } else {
          editorImagePreview.style.backgroundImage = 'linear-gradient(135deg, rgba(12,14,29,0.08), rgba(12,14,29,0.02))';
          editorImagePreview.style.backgroundColor = '#eef0f3';
        }

        editorModal.classList.add('open');
        editorModal.setAttribute('aria-hidden', 'false');
      }

      function openModal(card) {
        const title = card.dataset.title || 'untitled';
        const year = card.dataset.year || 'draft';
        const description = card.dataset.description || 'no notes yet.';
        const image = card.dataset.image || '';
        const tags = (card.dataset.tags || '').split(',').map((tag) => tag.trim()).filter(Boolean);

        modalTitle.textContent = title;
        modalYear.textContent = year;
        modalDescription.textContent = description;

        modalTags.innerHTML = '';
        tags.forEach((tag) => {
          const tagNode = document.createElement('span');
          tagNode.className = 'media-modal__tag';
          tagNode.textContent = tag;
          modalTags.appendChild(tagNode);
        });

        if (image) {
          modalImage.style.backgroundImage = 'url("' + image + '")';
          modalImage.style.backgroundColor = '#dfe4ea';
        } else {
          modalImage.style.backgroundImage = 'linear-gradient(135deg, rgba(12,14,29,0.08), rgba(12,14,29,0.02))';
          modalImage.style.backgroundColor = '#eef0f3';
        }

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
      }

      document.querySelectorAll('.post-card').forEach((card) => {
        card.addEventListener('click', (event) => {
          if (event.target.closest('.edit-card-button')) {
            return;
          }
          openModal(card);
        });
      });

      document.querySelectorAll('.edit-card-button').forEach((button) => {
        button.addEventListener('click', (event) => {
          event.stopPropagation();
          const card = button.closest('.post-card');
          if (card) {
            openEditor(card);
          }
        });
      });

      document.querySelectorAll('.card-admin-actions form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
          event.preventDefault();

          const confirmed = window.confirm('Delete this entry?');
          if (!confirmed) {
            return;
          }

          const formData = new FormData(form);
          const response = await fetch('../admin/atelier.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          });

          if (response.ok) {
            const card = form.closest('.post-card');
            if (card) {
              card.remove();
            }
          }
        });
      });

      const editorForm = document.getElementById('editorForm');
      if (editorForm) {
        editorForm.addEventListener('submit', async (event) => {
          event.preventDefault();

          const formData = new FormData(editorForm);
          const response = await fetch('../admin/atelier.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          });

          if (response.ok) {
            const card = document.querySelector('.post-card[data-id="' + editorIdField.value + '"]');
            if (card) {
              card.dataset.title = editorTitleField.value.trim();
              card.dataset.year = editorYearField.value.trim() || 'draft';
              card.dataset.description = editorDescriptionField.value.trim() || 'no notes yet.';
              card.dataset.image = editorImageField.value.trim();
              card.dataset.tags = editorTagsField.value.trim();
              card.setAttribute('aria-label', 'Open ' + editorTitleField.value.trim());
              card.querySelector('.post-card__label').textContent = editorTitleField.value.trim();
              const image = editorImageField.value.trim();
              if (image) {
                card.style.backgroundImage = 'url("' + image + '")';
              } else {
                card.style.backgroundImage = 'linear-gradient(135deg, rgba(12,14,29,0.06), rgba(12,14,29,0.03))';
              }
            }
          }

          closeEditor();
        });
      }

      closeButton.addEventListener('click', () => {
        closeModal();
        closeEditor();
      });
      closeTriggers.forEach((trigger) => trigger.addEventListener('click', () => {
        closeModal();
        closeEditor();
      }));

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeModal();
          closeEditor();
        }
      });

      applyLayout();
    </script>
  </body>
</html>

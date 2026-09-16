<?php
declare(strict_types=1);

Auth::requireRole('admin', 'editor');
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Only the super admin can manage pages.');
}
$pdo = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'list';
$errors = [];

/** Unique slug check, ignoring a page id (for edits). Returns an error message or null. */
function pageSlugError(PDO $pdo, string $slug, int $ignoreId = 0): ?string
{
    if ($slug === '') {
        return 'A slug is required (or leave blank to auto-generate from the title).';
    }
    $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = ? AND id <> ? LIMIT 1');
    $stmt->execute([$slug, $ignoreId]);
    return $stmt->fetch() ? 'That slug is already in use — pick another.' : null;
}

/** AJAX image upload for page sections (hero / image blocks). */
if ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        jsonResponse(['status' => 'error', 'message' => 'No image received.'], 400);
    }
    if ((int) ($_FILES['image']['size'] ?? 0) > 8 * 1024 * 1024) {
        jsonResponse(['status' => 'error', 'message' => 'Image is too large — max 8MB.'], 413);
    }
    $name = MediaProcessor::compressImage($_FILES['image']['tmp_name'], UPLOADS_CMS_PATH, 1920, 80, 'cms_');
    if (!$name) {
        jsonResponse(['status' => 'error', 'message' => 'Unsupported image file. Use JPG, PNG, GIF, WebP, BMP or AVIF.'], 422);
    }
    jsonResponse(['status' => 'success', 'path' => 'cms/' . $name, 'url' => uploadUrl('cms/' . $name)]);
}

/** Save / update a page. */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $slugRaw = trim($_POST['slug'] ?? '');
    $slug = $slugRaw !== '' ? slugify($slugRaw) : slugify($title);
    $eyebrow = trim($_POST['eyebrow'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $navLabel = trim($_POST['nav_label'] ?? '');
    $isPublished = isset($_POST['is_published']) ? 1 : 0;
    $inNav = isset($_POST['in_nav']) ? 1 : 0;
    $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
    $contentRaw = (string) ($_POST['content'] ?? '');

    if ($title === '') {
        $errors[] = 'Page title is required.';
    }
    if ($slugError = pageSlugError($pdo, $slug, $id)) {
        $errors[] = $slugError;
    }
    $content = [];
    if ($contentRaw !== '') {
        $decoded = json_decode($contentRaw, true);
        if (!is_array($decoded)) {
            $errors[] = 'The page content could not be read — please rebuild it and try again.';
        } else {
            $content = $decoded;
        }
    }

    if (!$errors) {
        $contentJson = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($id > 0) {
            $pdo->prepare('UPDATE pages SET title = ?, slug = ?, eyebrow = ?, content = ?, meta_description = ?, in_nav = ?, nav_label = ?, is_published = ?, sort_order = ? WHERE id = ?')
                ->execute([$title, $slug, $eyebrow, $contentJson, $metaDescription, $inNav, $navLabel, $isPublished, $sortOrder, $id]);
            flash('success', 'Page saved.');
        } else {
            $pdo->prepare('INSERT INTO pages (title, slug, eyebrow, content, meta_description, in_nav, nav_label, is_published, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$title, $slug, $eyebrow, $contentJson, $metaDescription, $inNav, $navLabel, $isPublished, $sortOrder]);
            flash('success', 'Page created.');
        }
        redirect('/admin/pages');
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT content FROM pages WHERE id = ?');
    $stmt->execute([$id]);
    $content = (string) ($stmt->fetchColumn() ?: '');
    // Clean up referenced CMS images.
    preg_match_all('/cms\/[a-zA-Z0-9_.]+/i', $content, $m);
    foreach (array_unique($m[0] ?? []) as $rel) {
        @unlink(UPLOADS_PATH . '/' . $rel);
    }
    $pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);
    flash('success', 'Page deleted.');
    redirect('/admin/pages');
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('UPDATE pages SET is_published = NOT is_published WHERE id = ?')->execute([$id]);
    redirect('/admin/pages');
}

if ($action === 'toggle_nav' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('UPDATE pages SET in_nav = NOT in_nav WHERE id = ?')->execute([$id]);
    redirect('/admin/pages');
}

$editPage = null;
if ($action === 'edit') {
    $editPage = $pdo->prepare('SELECT * FROM pages WHERE id = ?');
    $editPage->execute([(int) ($_GET['id'] ?? 0)]);
    $editPage = $editPage->fetch() ?: null;
}

$pages = $action === 'list' ? $pdo->query('SELECT * FROM pages ORDER BY sort_order ASC, id ASC')->fetchAll() : [];

$pageTitle = $action === 'edit' ? 'Edit Page' : ($action === 'create' ? 'New Page' : 'Pages');
$activeNav = 'pages';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($action === 'create' || ($action === 'edit' && $editPage)): ?>

  <link rel="stylesheet" href="<?= asset('css/admin-pages.css') ?>">
  <?php $pg = $action === 'edit' ? $editPage : null; ?>

  <form method="post" action="/admin/pages?action=save" id="pageForm">
    <?= Csrf::field() ?>
    <?php if ($pg): ?><input type="hidden" name="id" value="<?= (int) $pg['id'] ?>"><?php endif; ?>

    <div class="card">
      <h2><?= $pg ? 'Edit Page' : 'New Page' ?></h2>
      <div class="row two">
        <div>
          <label for="page_title">Page Title</label>
          <input type="text" id="page_title" name="title" value="<?= e($pg['title'] ?? '') ?>" required>
        </div>
        <div>
          <label for="page_slug">Slug <small>(leave blank to auto-generate)</small></label>
          <input type="text" id="page_slug" name="slug" value="<?= e($pg['slug'] ?? '') ?>" placeholder="our-story">
        </div>
      </div>
      <label for="page_eyebrow">Eyebrow <small>(small label above the page title)</small></label>
      <input type="text" id="page_eyebrow" name="eyebrow" value="<?= e($pg['eyebrow'] ?? '') ?>" placeholder="Our Story">
      <label for="page_meta">Meta Description (SEO)</label>
      <textarea id="page_meta" name="meta_description"><?= e($pg['meta_description'] ?? '') ?></textarea>
      <div class="row two">
        <div class="checkbox-row">
          <input type="checkbox" id="page_published" name="is_published" <?= !$pg || $pg['is_published'] ? 'checked' : '' ?>>
          <label for="page_published" style="margin:0;">Published</label>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" id="page_nav" name="in_nav" <?= $pg && $pg['in_nav'] ? 'checked' : '' ?>>
          <label for="page_nav" style="margin:0;">Show in site navigation</label>
        </div>
      </div>
      <div class="row two">
        <div>
          <label for="page_nav_label">Navigation Label</label>
          <input type="text" id="page_nav_label" name="nav_label" value="<?= e($pg['nav_label'] ?? '') ?>" placeholder="About">
        </div>
        <div>
          <label for="page_sort">Sort Order <small>(lowest first)</small></label>
          <input type="number" id="page_sort" name="sort_order" value="<?= e((string) ($pg['sort_order'] ?? 0)) ?>" min="0" style="max-width:120px;">
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Page Content</h2>
      <p class="sub">Compose the page from design blocks. Drag a block's handle to reorder it, or use the arrows. Images are uploaded straight into the block.</p>
      <div class="cms-toolbar">
        <select id="cmsAddType" aria-label="Add section">
          <option value="hero">Banner (hero)</option>
          <option value="text">Text block</option>
          <option value="columns">Cards (columns)</option>
          <option value="image">Image</option>
          <option value="quote">Quote</option>
          <option value="cta">Call to action</option>
        </select>
        <button type="button" class="btn secondary" id="cmsAddBtn">+ Add Section</button>
      </div>
      <div class="cms-builder" id="cmsBuilder"></div>
      <textarea name="content" id="pageContent" hidden><?= e((string) ($pg['content'] ?? '')) ?></textarea>
    </div>

    <div class="btn-row">
      <button class="btn" type="submit">Save Page</button>
      <a class="btn secondary" href="/admin/pages">Cancel</a>
      <?php if ($pg): ?>
        <a class="btn secondary" href="<?= e($pg['slug'] === 'about' ? '/about' : '/page/' . rawurlencode((string) $pg['slug'])) ?>" target="_blank">↗ View Page</a>
      <?php endif; ?>
    </div>
  </form>

  <script src="<?= asset('js/admin-pages.js') ?>"></script>

<?php else: ?>

  <div class="btn-row" style="margin-bottom:20px;">
    <a class="btn" href="/admin/pages?action=create">+ New Page</a>
  </div>

  <div class="card">
    <?php if (!$pages): ?>
      <div class="empty">No pages yet. Create your first one above.</div>
    <?php else: ?>
      <table>
        <tr><th>Title</th><th>Slug</th><th>Status</th><th>In Nav</th><th>Order</th><th>Updated</th><th></th></tr>
        <?php foreach ($pages as $pg): ?>
        <tr>
          <td><strong><?= e($pg['title']) ?></strong></td>
          <td><code style="color:var(--ink-dim);"><?= e($pg['slug']) ?></code></td>
          <td>
            <form method="post" action="/admin/pages?action=toggle" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $pg['id'] ?>">
              <button type="submit" class="badge <?= $pg['is_published'] ? 'ok' : 'warn' ?>" style="border:none;cursor:pointer;">
                <?= $pg['is_published'] ? 'published' : 'draft' ?>
              </button>
            </form>
          </td>
          <td>
            <form method="post" action="/admin/pages?action=toggle_nav" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $pg['id'] ?>">
              <button type="submit" class="badge <?= $pg['in_nav'] ? 'ok' : '' ?>" style="border:none;cursor:pointer;">
                <?= $pg['in_nav'] ? 'in nav' : 'hidden' ?>
              </button>
            </form>
          </td>
          <td><?= (int) $pg['sort_order'] ?></td>
          <td><?= e(timeAgo($pg['updated_at'])) ?></td>
          <td style="white-space:nowrap;">
            <?php if ($pg['is_published']): ?>
              <a class="btn sm secondary" href="<?= e($pg['slug'] === 'about' ? '/about' : '/page/' . rawurlencode((string) $pg['slug'])) ?>" target="_blank">View</a>
            <?php endif; ?>
            <a class="btn sm secondary" href="/admin/pages?action=edit&id=<?= (int) $pg['id'] ?>">Edit</a>
            <form method="post" action="/admin/pages?action=delete" onsubmit="return confirm('Delete this page permanently?');" style="display:inline;">
              <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $pg['id'] ?>">
              <button type="submit" class="btn danger sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>

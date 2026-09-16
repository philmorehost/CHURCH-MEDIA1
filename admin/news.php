<?php
declare(strict_types=1);

/**
 * Admin → News & blog: write it, categorise it, publish it.
 *
 * **Who may use it:** `admin`, `editor` and `media_team` — the three roles the request names. The media
 * team is the role handed to the most volunteers, which is exactly why the body is sanitised against a
 * whitelist in `News::save()` rather than trusted: a role given to many people cannot be treated as a
 * licence to store arbitrary HTML on a public page.
 *
 * **The featured image cannot be large because it is never stored large.**
 * `MediaProcessor::compressImage(…, 1280, 78, 'news_')` caps the long edge at 1280px, re-encodes to WebP
 * and keeps whichever of the two is smaller. The form then shows the dimensions and the file size that
 * were actually saved, so a heavy image is visible before publishing rather than discovered by a reader on
 * a slow connection. An image too large to be useful is the single most common way a news page becomes
 * slow, and it is cheaper to bound it here than to explain it later.
 *
 * **News is church-scoped and deliberately not unit-scoped.** A post is the whole church's news, not one
 * parish's — so `News::find()` and friends carry the church and nothing carries a unit. See the note in
 * `core/News.php`.
 */

Auth::requireRole('admin', 'editor', 'media_team');

$pdo = Database::getInstance()->getConnection();
$errors = [];
$action = (string) ($_GET['action'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$user = Auth::user();

// ------------------------------------------------------------------ categories

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    Csrf::requireValid();

    try {
        $categoryId = News::saveCategory(
            (int) ($_POST['category_id'] ?? 0) > 0 ? (int) $_POST['category_id'] : null,
            [
                'name' => (string) ($_POST['name'] ?? ''),
                'slug' => (string) ($_POST['slug'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            ]
        );
        flash('success', 'Category saved.');
        redirect('/admin/news?action=categories&id=' . $categoryId);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    Csrf::requireValid();
    $categoryId = (int) ($_POST['category_id'] ?? 0);

    // The posts are not deleted with it: the foreign key is ON DELETE SET NULL, so a news item survives
    // its category and comes back as uncategorised. Deleting a category must never delete a church's news.
    if (News::deleteCategory($categoryId)) {
        flash('success', 'Category deleted. Its posts are still here, under no category.');
    } else {
        flash('error', 'That category was not found.');
    }
    redirect('/admin/news?action=categories');
}

// ------------------------------------------------------------------ posts

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_post'])) {
    Csrf::requireValid();

    $postId = (int) ($_POST['post_id'] ?? 0) > 0 ? (int) $_POST['post_id'] : null;
    $existing = $postId !== null ? News::find($postId) : null;

    // The loader above is church-scoped: a post id from another church resolves to null, so this cannot
    // be used to edit somebody else's news by posting an id.
    if ($postId !== null && $existing === null) {
        $errors[] = 'That news item was not found.';
    } else {
        try {
            $data = [
                'title' => (string) ($_POST['title'] ?? ''),
                'slug' => (string) ($_POST['slug'] ?? ''),
                'category_id' => (int) ($_POST['category_id'] ?? 0),
                'excerpt' => (string) ($_POST['excerpt'] ?? ''),
                'body' => (string) ($_POST['body'] ?? ''),
                'seo_title' => (string) ($_POST['seo_title'] ?? ''),
                'seo_description' => (string) ($_POST['seo_description'] ?? ''),
                'status' => (string) ($_POST['status'] ?? 'draft'),
                'is_featured' => isset($_POST['is_featured']),
                'author_id' => (int) ($user['id'] ?? 0),
                'author_name' => (string) ($user['name'] ?? 'Church Media'),
            ];

            if (trim($data['title']) === '') {
                throw new InvalidArgumentException('A news item needs a title.');
            }

            // An image is only ever replaced by an upload, and only ever cleared by the explicit checkbox —
            // never by leaving the file input empty, which is how a re-save silently drops an image.
            if (!empty($_FILES['featured']['name'])) {
                $stored = news_store_featured_image($_FILES['featured']);
                if ($stored === null) {
                    $errors[] = 'The featured image could not be used. Please upload a JPG, PNG or WebP image.';
                } else {
                    $data['featured_path'] = $stored['path'];
                    $data['featured_width'] = $stored['width'];
                    $data['featured_height'] = $stored['height'];
                    $data['featured_alt'] = (string) ($_POST['featured_alt'] ?? '');
                }
            } elseif (!empty($_POST['remove_featured']) && $existing !== null) {
                if (!empty($existing['featured_path']) && is_file(UPLOADS_PATH . '/' . $existing['featured_path'])) {
                    @unlink(UPLOADS_PATH . '/' . $existing['featured_path']);
                }
                $data['featured_path'] = '';
                $data['featured_alt'] = '';
                $data['featured_width'] = 0;
                $data['featured_height'] = 0;
            } elseif ($existing !== null) {
                // Untouched: re-state what is stored so the save cannot drop it.
                $data['featured_path'] = (string) ($existing['featured_path'] ?? '');
                $data['featured_alt'] = (string) ($existing['featured_alt'] ?? '');
                $data['featured_width'] = (int) ($existing['featured_width'] ?? 0);
                $data['featured_height'] = (int) ($existing['featured_height'] ?? 0);
            }

            if (!$errors) {
                $savedId = News::save($postId, $data);
                flash('success', $data['status'] === 'published'
                    ? 'Published. It is live on the news page now.'
                    : 'Saved as a draft — not visible to visitors yet.');
                redirect('/admin/news?action=edit&id=' . $savedId);
            }

            $id = $postId ?? 0;
            $action = 'edit';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post'])) {
    Csrf::requireValid();
    $postId = (int) ($_POST['post_id'] ?? 0);

    if (News::delete($postId)) {
        flash('success', 'News item deleted, along with its featured image.');
    } else {
        flash('error', 'That news item was not found.');
    }
    redirect('/admin/news');
}

/**
 * Moves a post between draft and published from the list, without opening the editor.
 *
 * The most common action on this screen by a distance, and it must not require a full form round trip:
 * unpublishing something wrong should be one click, not a page load and a save.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publish'])) {
    Csrf::requireValid();
    $post = News::find((int) ($_POST['post_id'] ?? 0));

    if ($post !== null) {
        News::save((int) $post['id'], [
            'title' => (string) $post['title'],
            'slug' => (string) $post['slug'],
            'category_id' => (int) ($post['category_id'] ?? 0),
            'excerpt' => (string) ($post['excerpt'] ?? ''),
            'body' => (string) ($post['body'] ?? ''),
            'seo_title' => (string) ($post['seo_title'] ?? ''),
            'seo_description' => (string) ($post['seo_description'] ?? ''),
            'is_featured' => (int) $post['is_featured'] === 1,
            'status' => $post['status'] === 'published' ? 'draft' : 'published',
            'featured_path' => (string) ($post['featured_path'] ?? ''),
            'featured_alt' => (string) ($post['featured_alt'] ?? ''),
            'featured_width' => (int) ($post['featured_width'] ?? 0),
            'featured_height' => (int) ($post['featured_height'] ?? 0),
        ]);
        flash('success', $post['status'] === 'published' ? 'Unpublished — back to draft.' : 'Published.');
    }
    redirect('/admin/news');
}

/**
 * Stores a featured image, bounded.
 *
 * @param  array<string, mixed> $file one entry from `$_FILES`
 * @return array{path:string,width:int,height:int}|null null when it is not a usable image
 */
function news_store_featured_image(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        return null;
    }

    // The mime is read from the file's own bytes, never from the name the browser sent: an extension is a
    // claim, and this one decides what gets written into an uploads directory that a web server serves.
    $info = @getimagesize((string) $file['tmp_name']);
    if ($info === false || !in_array((string) ($info['mime'] ?? ''), ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/avif'], true)) {
        return null;
    }

    $name = MediaProcessor::compressImage((string) $file['tmp_name'], UPLOADS_WEBP_PATH, 1280, 78, 'news_');
    if ($name === null) {
        return null;
    }

    // Measured after processing, so what the screen reports is what was actually written.
    $stored = @getimagesize(UPLOADS_WEBP_PATH . '/' . $name);

    return [
        'path' => 'webp/' . $name,
        'width' => $stored !== false ? (int) $stored[0] : (int) $info[0],
        'height' => $stored !== false ? (int) $stored[1] : (int) $info[1],
    ];
}

// ------------------------------------------------------------------ data for the screen

$categories = News::categories();
$statusFilter = in_array((string) ($_GET['status'] ?? ''), ['draft', 'published'], true) ? (string) $_GET['status'] : null;
$posts = News::all($statusFilter);
$editing = $action === 'edit' && $id > 0 ? News::find($id) : null;
$editingCategory = $action === 'categories' && $id > 0 ? News::category($id) : null;

$pageTitle = 'News';
$activeNav = 'news';
require __DIR__ . '/partials/layout-open.php';
?>

<?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
  <?php
  $isNew = $editing === null;
  $v = static fn (string $key, string $default = ''): string => (string) ($editing[$key] ?? $default);
  ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/news">← All news</a>
    <?php if (!$isNew): ?>
      <a class="btn secondary sm" href="/news/<?= e($v('slug')) ?>" target="_blank" rel="noopener">View on the site ↗</a>
    <?php endif; ?>
  </div>

  <form method="post" action="/admin/news?action=<?= $isNew ? 'new' : 'edit' ?>&id=<?= (int) ($editing['id'] ?? 0) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="save_post" value="1">
    <input type="hidden" name="post_id" value="<?= (int) ($editing['id'] ?? 0) ?>">

    <div class="card" style="margin-bottom:18px;">
      <h2 style="margin-top:0;"><?= $isNew ? 'New news item' : 'Edit news item' ?></h2>

      <div style="margin-bottom:12px;">
        <label for="title">Headline</label>
        <input type="text" id="title" name="title" value="<?= e($v('title')) ?>" required maxlength="200" style="width:100%;">
      </div>

      <div style="display:flex; gap:12px; flex-wrap:wrap;">
        <div style="flex:1; min-width:240px;">
          <label for="slug">URL</label>
          <input type="text" id="slug" name="slug" value="<?= e($v('slug')) ?>" placeholder="left blank, made from the headline" style="width:100%;">
          <small style="color:var(--ink-faint);">/news/<?= e($v('slug') !== '' ? $v('slug') : 'made-from-the-headline') ?></small>
        </div>
        <div style="flex:1; min-width:240px;">
          <label for="category_id">Category</label>
          <select id="category_id" name="category_id" style="width:100%;">
            <option value="0">— none —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int) $cat['id'] ?>"<?= (int) $v('category_id', '0') === (int) $cat['id'] ? ' selected' : '' ?>><?= e((string) $cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--ink-faint);"><a href="/admin/news?action=categories">Manage categories</a></small>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <h2 style="margin-top:0;">Story</h2>
      <div style="margin-bottom:12px;">
        <label for="body-editor">Body</label>
        <?php /* The textarea is the form field. CKEditor takes it over when it loads; if it does not, this
                 is still a working input and the note below says so. Scripts and event handlers typed here
                 are stripped on save, so the fallback cannot smuggle anything through. */ ?>
        <textarea id="body-editor" name="body" rows="14" style="width:100%;"><?= e($v('body')) ?></textarea>
        <small style="color:var(--ink-faint);">If the editor does not load, you can type HTML here — headings, links, lists, images and bold/italic are kept; anything else is removed when you save.</small>
      </div>
      <div style="margin-bottom:0;">
        <label for="excerpt">Summary</label>
        <textarea id="excerpt" name="excerpt" rows="3" maxlength="400" style="width:100%;"><?= e($v('excerpt')) ?></textarea>
        <small style="color:var(--ink-faint);">Shown on the news list and used as the search description if you leave the one below blank.</small>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <h2 style="margin-top:0;">Featured image</h2>
      <?php $hasImage = $v('featured_path') !== ''; ?>
      <?php if ($hasImage): ?>
        <?php
        $imageFile = UPLOADS_PATH . '/' . $v('featured_path');
        $bytes = is_file($imageFile) ? (int) filesize($imageFile) : 0;
        ?>
        <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap; margin-bottom:12px;">
          <img src="<?= e(uploadUrl($v('featured_path'))) ?>" alt="" style="max-width:280px; border-radius:10px; border:1px solid var(--border-soft);">
          <div style="font-size:13px; color:var(--ink-dim);">
            <div><?= (int) $v('featured_width', '0') ?> × <?= (int) $v('featured_height', '0') ?> px</div>
            <div><?= $bytes > 0 ? e(number_format($bytes / 1024, 0)) . ' KB' : 'file missing' ?></div>
            <label style="display:block; margin-top:10px;"><input type="checkbox" name="remove_featured" value="1"> Remove it</label>
          </div>
        </div>
      <?php endif; ?>
      <div style="margin-bottom:12px;">
        <label for="featured"><?= $hasImage ? 'Replace image' : 'Choose an image' ?></label>
        <input type="file" id="featured" name="featured" accept="image/*">
        <small style="color:var(--ink-faint);">Resized on upload to at most 1280px on the long edge and converted to WebP, so a 6 MB phone photo becomes a few hundred kilobytes. Landscape (16:9) looks best.</small>
      </div>
      <div style="margin-bottom:0;">
        <label for="featured_alt">Describe the image</label>
        <input type="text" id="featured_alt" name="featured_alt" value="<?= e($v('featured_alt')) ?>" maxlength="200" style="width:100%;">
        <small style="color:var(--ink-faint);">Read aloud by screen readers and shown if the image fails to load. Search engines read it too.</small>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      <h2 style="margin-top:0;">Search &amp; publishing</h2>
      <div style="margin-bottom:12px;">
        <label for="seo_title">Search title</label>
        <input type="text" id="seo_title" name="seo_title" value="<?= e($v('seo_title')) ?>" maxlength="200" style="width:100%;" placeholder="<?= e($v('title')) ?>">
        <small style="color:var(--ink-faint);">Leave blank to use the headline. Around 60 characters is what Google shows.</small>
      </div>
      <div style="margin-bottom:12px;">
        <label for="seo_description">Search description</label>
        <textarea id="seo_description" name="seo_description" rows="2" maxlength="300" style="width:100%;"><?= e($v('seo_description')) ?></textarea>
        <small style="color:var(--ink-faint);">Leave blank to use the summary. Around 155 characters is what Google shows.</small>
      </div>
      <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center;">
        <div>
          <label for="status">State</label>
          <select id="status" name="status">
            <option value="draft"<?= $v('status', 'draft') === 'draft' ? ' selected' : '' ?>>Draft — not visible</option>
            <option value="published"<?= $v('status') === 'published' ? ' selected' : '' ?>>Published — live</option>
          </select>
        </div>
        <label style="margin:0;"><input type="checkbox" name="is_featured" value="1"<?= (int) $v('is_featured', '0') === 1 ? ' checked' : '' ?>> Make this the lead story on the news page</label>
      </div>
    </div>

    <div class="btn-row">
      <button class="btn" type="submit"><?= $isNew ? 'Create' : 'Save changes' ?></button>
      <a class="btn secondary" href="/admin/news">Cancel</a>
    </div>
  </form>

  <?php if (!$isNew): ?>
    <form method="post" action="/admin/news" style="margin-top:24px;" onsubmit="return confirm('Delete this news item and its image? This cannot be undone.');">
      <?= Csrf::field() ?>
      <input type="hidden" name="delete_post" value="1">
      <input type="hidden" name="post_id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <button class="btn danger" type="submit">Delete this news item</button>
    </form>
  <?php endif; ?>

<?php elseif ($action === 'categories'): ?>
  <div class="btn-row" style="margin-bottom:16px;">
    <a class="btn secondary sm" href="/admin/news">← All news</a>
  </div>

  <div class="card" style="margin-bottom:18px;">
    <h2 style="margin-top:0;"><?= $editingCategory !== null ? 'Edit category' : 'New category' ?></h2>
    <form method="post" action="/admin/news?action=categories">
      <?= Csrf::field() ?>
      <input type="hidden" name="save_category" value="1">
      <input type="hidden" name="category_id" value="<?= (int) ($editingCategory['id'] ?? 0) ?>">
      <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:2; min-width:220px;">
          <label for="name">Name</label>
          <input type="text" id="name" name="name" value="<?= e((string) ($editingCategory['name'] ?? '')) ?>" required maxlength="120" style="width:100%;">
        </div>
        <div style="flex:2; min-width:200px;">
          <label for="cat_slug">URL</label>
          <input type="text" id="cat_slug" name="slug" value="<?= e((string) ($editingCategory['slug'] ?? '')) ?>" placeholder="from the name" style="width:100%;">
        </div>
        <div style="flex:1; min-width:110px;">
          <label for="sort_order">Order</label>
          <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($editingCategory['sort_order'] ?? 0) ?>" style="width:100%;">
        </div>
        <div>
          <button class="btn" type="submit"><?= $editingCategory !== null ? 'Save' : 'Add category' ?></button>
        </div>
      </div>
      <div style="margin-top:12px;">
        <label for="cat_description">Description</label>
        <input type="text" id="cat_description" name="description" value="<?= e((string) ($editingCategory['description'] ?? '')) ?>" maxlength="255" style="width:100%;">
      </div>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Categories</h2>
    <?php if (!$categories): ?>
      <p class="sub">No categories yet. Add one above and it will appear in the editor.</p>
    <?php else: ?>
      <table style="width:100%; border-collapse:collapse; font-size:14px;">
        <thead>
          <tr style="text-align:left; color:var(--ink-dim);">
            <th style="padding:8px 6px;">Name</th>
            <th style="padding:8px 6px;">URL</th>
            <th style="padding:8px 6px;">Posts</th>
            <th style="padding:8px 6px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $cat): ?>
            <tr style="border-top:1px solid var(--border-soft);">
              <td style="padding:8px 6px;"><?= e((string) $cat['name']) ?></td>
              <td style="padding:8px 6px; color:var(--ink-dim);">/news/category/<?= e((string) $cat['slug']) ?></td>
              <td style="padding:8px 6px; color:var(--ink-dim);"><?= (int) ($cat['post_count'] ?? 0) ?></td>
              <td style="padding:8px 6px; text-align:right;">
                <a class="btn secondary sm" href="/admin/news?action=categories&id=<?= (int) $cat['id'] ?>">Edit</a>
                <form method="post" action="/admin/news?action=categories" style="display:inline;" onsubmit="return confirm('Delete this category? Its posts are kept.');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="delete_category" value="1">
                  <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
                  <button class="btn danger sm" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div class="btn-row" style="margin-bottom:16px; align-items:center;">
    <a class="btn" href="/admin/news?action=new">+ New news item</a>
    <a class="btn secondary" href="/admin/news?action=categories">Categories</a>
    <span style="flex:1;"></span>
    <?php foreach (['' => 'All', 'published' => 'Published', 'draft' => 'Drafts'] as $key => $label): ?>
      <a class="btn <?= ($statusFilter ?? '') === $key ? '' : 'secondary' ?> sm" href="/admin/news<?= $key !== '' ? '?status=' . $key : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$posts): ?>
    <div class="card"><p class="sub" style="margin:0;">Nothing here yet. <a href="/admin/news?action=new">Write the first news item.</a></p></div>
  <?php else: ?>
    <div class="card">
      <table style="width:100%; border-collapse:collapse; font-size:14px;">
        <thead>
          <tr style="text-align:left; color:var(--ink-dim);">
            <th style="padding:8px 6px;">Headline</th>
            <th style="padding:8px 6px;">Category</th>
            <th style="padding:8px 6px;">State</th>
            <th style="padding:8px 6px;">Views</th>
            <th style="padding:8px 6px;">Written</th>
            <th style="padding:8px 6px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posts as $post): ?>
            <tr style="border-top:1px solid var(--border-soft);">
              <td style="padding:8px 6px;">
                <?php if ((int) $post['is_featured'] === 1): ?><span title="Lead story" style="color:#e8b95f;">★</span> <?php endif; ?>
                <a href="/admin/news?action=edit&id=<?= (int) $post['id'] ?>"><?= e((string) $post['title']) ?></a>
              </td>
              <td style="padding:8px 6px; color:var(--ink-dim);"><?= e((string) ($post['category_name'] ?? '—')) ?></td>
              <td style="padding:8px 6px;">
                <?php if ($post['status'] === 'published'): ?>
                  <span style="color:#4ade80;">Published</span>
                <?php else: ?>
                  <span style="color:var(--ink-dim);">Draft</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px 6px; color:var(--ink-dim);"><?= (int) $post['views_count'] ?></td>
              <td style="padding:8px 6px; color:var(--ink-dim);"><?= e(date('M j, Y', strtotime((string) ($post['published_at'] ?? $post['created_at'])))) ?></td>
              <td style="padding:8px 6px; text-align:right; white-space:nowrap;">
                <?php if ($post['status'] === 'published'): ?>
                  <a class="btn secondary sm" href="/news/<?= e((string) $post['slug']) ?>" target="_blank" rel="noopener">View</a>
                <?php endif; ?>
                <form method="post" action="/admin/news" style="display:inline;">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="toggle_publish" value="1">
                  <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                  <button class="btn <?= $post['status'] === 'published' ? 'secondary' : '' ?> sm" type="submit"><?= $post['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button>
                </form>
                <a class="btn secondary sm" href="/admin/news?action=edit&id=<?= (int) $post['id'] ?>">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
  <?php
  /*
   * CKEditor 5, served from this site's own assets.
   *
   * A CDN build would be blocked: bootstrap.php sends `script-src 'self'` and the admin only adds
   * `'unsafe-inline'`. Vendoring it also means the editor works when the CDN is unreachable from the
   * church's network, which is a real condition on the connections these churches use.
   *
   * Loaded on this screen only — it is 1.4 MB, and the news list has no use for it.
   */
  ?>
  <script src="/assets/vendor/ckeditor5/ckeditor.js?v=<?= e(ASSET_VERSION) ?>"></script>
  <script>
  (function () {
    var field = document.getElementById('body-editor');
    if (!field || !window.ClassicEditor) {
      // Left as a plain textarea on purpose: writing the story still works, and the note under the field
      // tells the author what is and is not kept.
      return;
    }

    window.ClassicEditor.create(field, {
      // 'GPL' because this build is used under the GPL branch of its dual licence. Without a key CKEditor
      // shows a licence warning banner over the editor.
      licenseKey: 'GPL',
      toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', '|',
        'blockQuote', 'insertTable', 'uploadImage', 'mediaEmbed', '|', 'undo', 'redo'],
      heading: {
        options: [
          { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
          { model: 'heading2', view: 'h2', title: 'Heading', class: 'ck-heading_heading2' },
          { model: 'heading3', view: 'h3', title: 'Subheading', class: 'ck-heading_heading3' }
        ]
      },
      // The image button is a URL prompt rather than an upload: this stage stores one image, the featured
      // one, so a body upload would produce files nothing ever cleans up.
      image: { toolbar: ['imageTextAlternative'] }
    }).catch(function (error) {
      console.warn('CKEditor did not start; the plain textarea is still usable.', error);
    });
  })();
  </script>
<?php endif; ?>

<?php
declare(strict_types=1);

/**
 * News & blog.
 *
 * Three decisions this class exists to enforce, because each one is easy to get wrong further up:
 *
 * 1. **News belongs to a church, and only to a church.** Every read and every write here is scoped to
 *    `Tenant::id()`, on the same principle as the SMS screens: filtering a *list* while leaving the
 *    single-row loader unscoped is the bug that lets somebody open another church's post by guessing an
 *    id. So `find()`, `category()`, `save()` and `delete()` all carry the church, not just the list
 *    queries.
 *
 *    It is deliberately **not** unit-scoped. A post is the whole church's news, not one parish's — the
 *    same asymmetry the SMS spend tiles have, and the opposite of the admin screens that are unit-scoped.
 *
 * 2. **The body is sanitised against a whitelist on the way in.** The people who can post are admins,
 *    editors and the media team, and the media team is the role handed to the most volunteers. Storing
 *    whatever an editor produced and printing it unescaped on a public page is stored XSS waiting for one
 *    careless paste — from a compromised account, or from HTML written by somebody who did not know that
 *    pasting from another site brings its scripts along. Allowed tags and attributes are listed below and
 *    nothing else survives; URLs must be `http(s)`, or root-relative, or `mailto`; and `style` keeps only
 *    a short list of harmless properties, none of which can contain `url(...)`.
 *
 * 3. **A slug is not an identifier.** Slugs are unique per church (`(tenant_id, slug)`), so two churches
 *    can both own `news/announcement` — unlike `org_units.slug`, whose global uniqueness forces a `-2`
 *    suffix on the second church. Within one church a repeat is disambiguated, because a URL has to be
 *    unique to be a URL.
 */
final class News
{
    /**
     * Tags the editor may produce that we keep. Anything absent is removed along with its content, so a
     * pasted `<script>` cannot survive by being renamed.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED = [
        'p' => ['style'],
        'br' => [],
        'hr' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [],
        'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => ['class'], 'figure' => ['class'], 'figcaption' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
        'code' => [], 'pre' => [], 'span' => ['style'], 'div' => ['class'],
    ];

    /** CSS properties kept inside a `style` attribute. None of them can load anything. */
    private const ALLOWED_CSS = ['text-align', 'font-weight', 'font-style', 'text-decoration', 'color',
        'background-color', 'width', 'height', 'max-width', 'min-width', 'border', 'border-collapse'];

    /** Tags removed together with everything inside them, rather than unwrapped. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'select', 'textarea', 'noscript', 'svg', 'math', 'link', 'meta', 'base', 'template'];

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    /** The church being served. 0 for "none", which matches no row. */
    private static function tenantId(): int
    {
        return (int) (class_exists('Tenant') ? Tenant::id() : 0);
    }

    // ------------------------------------------------------------------ categories

    /**
     * Every category for this church, with how many published posts each holds.
     *
     * The count is a subquery rather than a JOIN so a category with no posts still appears — an editor who
     * has just made one needs to see it in the list to believe it exists.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categories(bool $withCounts = true): array
    {
        $counts = $withCounts
            ? ', (SELECT COUNT(*) FROM news_posts p WHERE p.category_id = c.id AND p.tenant_id = c.tenant_id AND p.status = "published") AS post_count'
            : '';

        $stmt = self::db()->prepare(
            'SELECT c.*' . $counts . ' FROM news_categories c WHERE c.tenant_id = ? ORDER BY c.sort_order ASC, c.name ASC'
        );
        $stmt->execute([self::tenantId()]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function category(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM news_categories WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, self::tenantId()]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function categoryBySlug(string $slug): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM news_categories WHERE slug = ? AND tenant_id = ?');
        $stmt->execute([$slug, self::tenantId()]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Creates or updates a category. Returns the id.
     *
     * @param array<string, mixed> $data name, slug?, description?, sort_order?
     */
    public static function saveCategory(?int $id, array $data): int
    {
        $tenantId = self::tenantId();
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('A category needs a name.');
        }

        $slug = self::uniqueCategorySlug(
            trim((string) ($data['slug'] ?? '')) !== '' ? (string) $data['slug'] : $name,
            $id
        );

        $fields = [
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        if ($id !== null && self::category($id) !== null) {
            $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($fields)));
            $stmt = self::db()->prepare('UPDATE news_categories SET ' . $set . ' WHERE id = ? AND tenant_id = ?');
            $stmt->execute([...array_values($fields), $id, $tenantId]);

            return $id;
        }

        $cols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($fields)));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = self::db()->prepare('INSERT INTO news_categories (tenant_id, ' . $cols . ') VALUES (?, ' . $marks . ')');
        $stmt->execute([$tenantId, ...array_values($fields)]);

        return (int) self::db()->lastInsertId();
    }

    /**
     * Deletes a category. Its posts are kept and become uncategorised — the foreign key is ON DELETE SET
     * NULL, so deleting a category can never delete a church's news as a side effect.
     */
    public static function deleteCategory(int $id): bool
    {
        $stmt = self::db()->prepare('DELETE FROM news_categories WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, self::tenantId()]);

        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------ posts

    /**
     * Posts for the admin list, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(?string $status = null, ?int $categoryId = null, int $limit = 200, int $offset = 0): array
    {
        $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug
                FROM news_posts p LEFT JOIN news_categories c ON c.id = p.category_id AND c.tenant_id = p.tenant_id
                WHERE p.tenant_id = ?';
        $params = [self::tenantId()];

        if ($status !== null && in_array($status, ['draft', 'published'], true)) {
            $sql .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }

        $sql .= ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** How many posts match, for pagination. */
    public static function count(?string $status = null, ?int $categoryId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM news_posts p WHERE p.tenant_id = ?';
        $params = [self::tenantId()];

        if ($status !== null && in_array($status, ['draft', 'published'], true)) {
            $sql .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** One post, scoped to this church. The loader the admin screens must use before acting on an id. */
    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM news_posts WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, self::tenantId()]);

        return $stmt->fetch() ?: null;
    }

    /**
     * A published post by its slug — what the public route uses.
     *
     * A draft is invisible here on purpose: the public URL must not be able to show work in progress, and
     * "not published yet" and "does not exist" are the same answer to a visitor.
     */
    public static function publishedBySlug(string $slug): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM news_posts WHERE slug = ? AND tenant_id = ? AND status = "published" AND published_at <= NOW()'
        );
        $stmt->execute([$slug, self::tenantId()]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The public list, with the reader's page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function published(?int $categoryId = null, int $limit = 12, int $offset = 0, string $search = ''): array
    {
        $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug
                FROM news_posts p LEFT JOIN news_categories c ON c.id = p.category_id AND c.tenant_id = p.tenant_id
                WHERE p.tenant_id = ? AND p.status = "published" AND p.published_at <= NOW()';
        $params = [self::tenantId()];

        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        if (trim($search) !== '') {
            $sql .= ' AND (p.title LIKE ? OR p.excerpt LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // The lead story is shown separately at the top, so it is excluded from the grid it heads.
        $sql .= ' ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** The lead story: the newest post an editor has marked, else the newest post at all. */
    public static function leadStory(): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM news_posts p LEFT JOIN news_categories c ON c.id = p.category_id AND c.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.status = "published" AND p.published_at <= NOW()
             ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC LIMIT 1'
        );
        $stmt->execute([self::tenantId()]);

        return $stmt->fetch() ?: null;
    }

    /**
     * More reading on the same subject, falling back to the newest posts so the block is never empty.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function related(array $post, int $limit = 3): array
    {
        $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug
                FROM news_posts p LEFT JOIN news_categories c ON c.id = p.category_id AND c.tenant_id = p.tenant_id
                WHERE p.tenant_id = ? AND p.status = "published" AND p.published_at <= NOW() AND p.id <> ?';
        $params = [self::tenantId(), (int) $post['id']];

        if (!empty($post['category_id'])) {
            $sql .= ' AND p.category_id = ?';
            $params[] = (int) $post['category_id'];
        }

        $sql .= ' ORDER BY p.published_at DESC LIMIT ' . max(1, $limit);

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        $found = $stmt->fetchAll();

        if (count($found) >= $limit || empty($post['category_id'])) {
            return $found;
        }

        // Nothing else in that category — fill from the rest rather than showing an empty block.
        $exclude = [(int) $post['id'], ...array_map(static fn (array $p): int => (int) $p['id'], $found)];
        $marks = implode(', ', array_fill(0, count($exclude), '?'));
        $stmt = self::db()->prepare(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM news_posts p LEFT JOIN news_categories c ON c.id = p.category_id AND c.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.status = "published" AND p.published_at <= NOW() AND p.id NOT IN (' . $marks . ')
             ORDER BY p.published_at DESC LIMIT ' . max(1, $limit - count($found))
        );
        $stmt->execute([self::tenantId(), ...$exclude]);

        return [...$found, ...$stmt->fetchAll()];
    }

    /** Adds one to the view counter. Deliberately fire-and-forget: a post must not fail to render because a counter failed. */
    public static function countView(int $id): void
    {
        try {
            $stmt = self::db()->prepare('UPDATE news_posts SET views_count = views_count + 1 WHERE id = ? AND tenant_id = ?');
            $stmt->execute([$id, self::tenantId()]);
        } catch (Throwable $e) {
            // Ignored on purpose.
        }
    }

    /**
     * Creates or updates a post, and returns its id.
     *
     * Every value that reaches the database goes through here, so sanitising happens in one place rather
     * than at each caller — a caller that forgets cannot store raw HTML by accident.
     *
     * @param  array<string, mixed> $data
     * @return int
     */
    public static function save(?int $id, array $data): int
    {
        $tenantId = self::tenantId();
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException('A news item needs a title.');
        }

        $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $existing = $id !== null ? self::find($id) : null;

        // The publish time is stamped the first time it is published and left alone afterwards, so
        // re-saving a live post does not silently re-date it to the top of the list.
        $publishedAt = $existing['published_at'] ?? null;
        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $body = self::sanitizeHtml((string) ($data['body'] ?? ''));
        $excerpt = trim((string) ($data['excerpt'] ?? ''));

        $fields = [
            'title' => $title,
            'slug' => self::uniqueSlug((string) ($data['slug'] ?? '') !== '' ? (string) $data['slug'] : $title, $id),
            'category_id' => (int) ($data['category_id'] ?? 0) > 0 ? (int) $data['category_id'] : null,
            'excerpt' => $excerpt !== '' ? mb_substr($excerpt, 0, 400) : self::excerptFrom($body, 200),
            'body' => $body,
            'seo_title' => trim((string) ($data['seo_title'] ?? '')) ?: null,
            'seo_description' => trim((string) ($data['seo_description'] ?? '')) ?: null,
            'status' => $status,
            'is_featured' => !empty($data['is_featured']) ? 1 : 0,
            'published_at' => $status === 'published' ? $publishedAt : null,
        ];

        if (array_key_exists('featured_path', $data)) {
            $fields['featured_path'] = trim((string) $data['featured_path']) ?: null;
            $fields['featured_alt'] = trim((string) ($data['featured_alt'] ?? '')) ?: null;
            $fields['featured_width'] = (int) ($data['featured_width'] ?? 0);
            $fields['featured_height'] = (int) ($data['featured_height'] ?? 0);
        }

        if ($existing !== null) {
            $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($fields)));
            $stmt = self::db()->prepare('UPDATE news_posts SET ' . $set . ' WHERE id = ? AND tenant_id = ?');
            $stmt->execute([...array_values($fields), $id, $tenantId]);

            return (int) $id;
        }

        $fields['author_id'] = (int) ($data['author_id'] ?? 0) > 0 ? (int) $data['author_id'] : null;
        $fields['author_name'] = trim((string) ($data['author_name'] ?? '')) ?: 'Church Media';

        $cols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($fields)));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = self::db()->prepare('INSERT INTO news_posts (tenant_id, ' . $cols . ') VALUES (?, ' . $marks . ')');
        $stmt->execute([$tenantId, ...array_values($fields)]);

        return (int) self::db()->lastInsertId();
    }

    /** Deletes a post and the image that belongs to it, so a deleted post leaves no orphan on disk. */
    public static function delete(int $id): bool
    {
        $post = self::find($id);
        if ($post === null) {
            return false;
        }

        if (!empty($post['featured_path']) && is_file(UPLOADS_PATH . '/' . $post['featured_path'])) {
            @unlink(UPLOADS_PATH . '/' . $post['featured_path']);
        }

        $stmt = self::db()->prepare('DELETE FROM news_posts WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, self::tenantId()]);

        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------ slugs and text

    private static function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-') ?: 'news';
    }

    /** A slug no other post in this church is using. Repeats get `-2`, `-3`, … */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        return self::uniqueInTable('news_posts', self::slugify($title), $ignoreId);
    }

    private static function uniqueCategorySlug(string $name, ?int $ignoreId = null): string
    {
        return self::uniqueInTable('news_categories', self::slugify($name), $ignoreId);
    }

    private static function uniqueInTable(string $table, string $slug, ?int $ignoreId): string
    {
        $table = $table === 'news_categories' ? 'news_categories' : 'news_posts';
        $candidate = $slug;
        $n = 2;

        // Scoped to the church, because the unique key is (tenant_id, slug): two churches may both own
        // `news/announcement`, and this must not hand the second one `announcement-2`.
        while (true) {
            $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE slug = ? AND tenant_id = ?';
            $params = [$candidate, self::tenantId()];
            if ($ignoreId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }

            $stmt = self::db()->prepare($sql);
            $stmt->execute($params);

            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }

            $candidate = $slug . '-' . $n;
            $n++;
        }
    }

    /**
     * The body as stored: a whitelist rebuild, not a blacklist filter.
     *
     * A blacklist is the wrong shape for this. `onerror=`, `javascript:`, `<svg/onload>` and a hundred
     * other spellings are all "not `<script>`", and the list of ways to write a script in HTML is longer
     * than the list of ways to write a paragraph. So the markup is parsed, disallowed tags and attributes
     * are dropped, and the result is rebuilt from what is left.
     *
     * With DOM unavailable the fallback is a denylist, which is weaker — hence the harness assertion that
     * the DOM path is the one actually taken on this machine.
     */
    public static function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (!class_exists('DOMDocument')) {
            return self::sanitizeWithoutDom($html);
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        // The XML encoding instruction is what makes loadHTML treat the input as UTF-8 rather than
        // latin-1, which would mangle every Yoruba diacritic in a body.
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="news-root">' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('news-root');
        if ($root === null) {
            return '';
        }

        self::cleanNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function cleanNode(DOMNode $node): void
    {
        // Collected first: removing while iterating a live DOMNodeList skips every other sibling.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!array_key_exists($tag, self::ALLOWED)) {
                // Unknown but harmless (a `<section>` from a paste): keep the words, drop the wrapper.
                self::cleanNode($child);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $allowed = self::ALLOWED[$tag];

            // Attributes are read into an array before removal, because removing during iteration over
            // `attributes` corrupts the list.
            $attributes = [];
            foreach ($child->attributes as $attr) {
                $attributes[strtolower($attr->name)] = (string) $attr->value;
            }

            foreach (array_keys($attributes) as $name) {
                if (!in_array($name, $allowed, true)) {
                    $child->removeAttribute($name);
                }
            }

            foreach ($attributes as $name => $value) {
                if (!in_array($name, $allowed, true)) {
                    continue;
                }

                if (in_array($name, ['href', 'src'], true) && !self::safeUrl($value)) {
                    $child->removeAttribute($name);
                    continue;
                }

                if ($name === 'style') {
                    $clean = self::cleanStyle($value);
                    if ($clean === '') {
                        $child->removeAttribute('style');
                    } else {
                        $child->setAttribute('style', $clean);
                    }
                }
            }

            // Everything else in the document gets a `rel="noopener"` it did not ask for; a link off a
            // news page should not hand the destination a handle on this tab.
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            self::cleanNode($child);
        }
    }

    /** Only the three schemes a news body has any business using, plus a root-relative path. */
    private static function safeUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '') {
            return false;
        }

        // Control characters are stripped by browsers before the scheme is read, so `java\0script:` would
        // otherwise slip past a naive prefix test.
        $collapsed = preg_replace('/[\x00-\x20]/', '', $url) ?? $url;

        if (str_starts_with($collapsed, '//')) {
            return true; // Protocol-relative, resolves to https on this site.
        }

        if (str_starts_with($collapsed, '/') || str_starts_with($collapsed, '#')) {
            return true;
        }

        return (bool) preg_match('#^(https?:|mailto:)#i', $collapsed);
    }

    /** A `style` attribute reduced to the properties that cannot load or execute anything. */
    private static function cleanStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $value = trim($value);

            if (!in_array($property, self::ALLOWED_CSS, true) || $value === '') {
                continue;
            }
            // No `url(...)`, no `expression(...)`, no custom properties: a value is letters, digits, spaces
            // and a handful of punctuation, and nothing that can call out.
            if (preg_match('#^[a-zA-Z0-9\s\#%\.\,\-\(\)]+$#', $value) !== 1) {
                continue;
            }
            if (stripos($value, 'url(') !== false || stripos($value, 'expression') !== false) {
                continue;
            }

            $kept[] = $property . ': ' . $value;
        }

        return implode('; ', $kept);
    }

    /**
     * The fallback when DOM is not available: strip what is known to be dangerous.
     *
     * Weaker than the whitelist above and kept only so a server without php-xml still sanitises *something*
     * rather than storing whatever arrived. The harness asserts the DOM path is used.
     */
    private static function sanitizeWithoutDom(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form|svg|math)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|style|iframe|object|embed|form|svg|math|input|button|link|meta)\b[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html) ?? '';
        $html = preg_replace('#(href|src)\s*=\s*("|\')\s*(javascript|vbscript|data):[^"\']*\2#is', '$1="#"', $html) ?? '';

        return trim($html);
    }

    // ------------------------------------------------------------------ presentation helpers

    /** How long the piece takes to read, at a deliberately slow 200 words a minute. */
    public static function readingMinutes(string $html): int
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        if ($text === '') {
            return 1;
        }

        return max(1, (int) ceil(str_word_count($text) / 200));
    }

    /** Plain text from a body, for an excerpt or a meta description. */
    public static function excerptFrom(string $html, int $length = 200): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        return $text === '' ? '' : trim(mb_strimwidth($text, 0, $length, '…'));
    }

    /** The meta description a search engine should use: the post's own, else its excerpt. */
    public static function metaDescription(array $post): string
    {
        $own = trim((string) ($post['seo_description'] ?? ''));

        return $own !== '' ? $own : (string) ($post['excerpt'] ?? '');
    }

    /** The `<title>`: the post's own SEO title if set, else the headline. */
    public static function metaTitle(array $post): string
    {
        $own = trim((string) ($post['seo_title'] ?? ''));

        return $own !== '' ? $own : (string) $post['title'];
    }
}

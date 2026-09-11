<?php
declare(strict_types=1);

/**
 * Organizational hierarchy for church units (RCCG-style by default:
 * Province → Zone → Area → Parish — but the levels are configurable, see
 * levels()).
 *
 * Stored in a single self-referencing `org_units` table. Leaves are parishes;
 * a parish uniquely determines its full ancestor chain, so posts only need to
 * be tagged with a parish (`media_posts.org_unit_id`) and every roll-up
 * (zone / area / province) is derived by walking up.
 */
class Unit
{
    private static ?PDO $pdo = null;

    private static function db(): PDO
    {
        return self::$pdo ??= Database::getInstance()->getConnection();
    }

    /** Fallback hierarchy used when `unit_levels` is missing or empty. */
    private const DEFAULT_LEVELS = [
        ['type' => 'province', 'label' => 'Province', 'plural' => 'Provinces'],
        ['type' => 'zone', 'label' => 'Zone', 'plural' => 'Zones'],
        ['type' => 'area', 'label' => 'Area', 'plural' => 'Areas'],
        ['type' => 'parish', 'label' => 'Parish', 'plural' => 'Parishes'],
    ];

    private static ?array $levelsCache = null;

    /**
     * Configured hierarchy levels, root → leaf. Read from `unit_levels` so the
     * super admin can rename, reorder, add or remove levels; falls back to the
     * classic Province → Zone → Area → Parish set when the table is unavailable.
     *
     * @return array<int, array{type:string,label:string,plural:string}>
     */
    public static function levels(): array
    {
        if (self::$levelsCache !== null) {
            return self::$levelsCache;
        }
        try {
            $rows = self::db()->query('SELECT type, label, plural FROM unit_levels ORDER BY sort_order ASC, id ASC')->fetchAll();
            if ($rows) {
                return self::$levelsCache = array_map(static fn (array $r): array => [
                    'type' => (string) $r['type'],
                    'label' => (string) $r['label'],
                    'plural' => (string) $r['plural'],
                ], $rows);
            }
        } catch (Throwable $e) {
            // Not migrated yet — use the defaults.
        }
        return self::$levelsCache = self::DEFAULT_LEVELS;
    }

    /** Drops the cached levels (call after adding/renaming/reordering them). */
    public static function forgetLevels(): void
    {
        self::$levelsCache = null;
    }

    /** Level keys in hierarchy order (root → leaf). */
    public static function types(): array
    {
        return array_map(static fn (array $l): string => $l['type'], self::levels());
    }

    /** Number of configured levels. */
    public static function levelCount(): int
    {
        return count(self::levels());
    }

    /** Position of a level (0 = top level), or null when the type is unknown. */
    public static function levelIndex(string $type): ?int
    {
        $i = array_search($type, self::types(), true);
        return $i === false ? null : (int) $i;
    }

    /** The level directly above $type (null when $type is the top level). */
    public static function parentType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $i = self::levelIndex($type);
        return $i === null || $i === 0 ? null : self::types()[$i - 1];
    }

    /** The level directly below $type (null when $type is the deepest level). */
    public static function childType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $i = self::levelIndex($type);
        $types = self::types();
        return $i === null || $i >= count($types) - 1 ? null : $types[$i + 1];
    }

    /** The deepest level — where churches actually live. */
    public static function leafType(): string
    {
        $types = self::types();
        return $types[count($types) - 1];
    }

    /** The shallowest level. */
    public static function rootType(): string
    {
        return self::types()[0];
    }

    /** Singular display name for a level, e.g. "Province". */
    public static function labelFor(string $type): string
    {
        foreach (self::levels() as $level) {
            if ($level['type'] === $type) {
                return $level['label'];
            }
        }
        return ucfirst($type);
    }

    /** Plural display name for a level, e.g. "Provinces". */
    public static function pluralFor(string $type): string
    {
        foreach (self::levels() as $level) {
            if ($level['type'] === $type) {
                return $level['plural'];
            }
        }
        return self::labelFor($type) . 's';
    }

    /** "A Parish must belong to an Area." — using the configured level names. */
    private static function parentError(string $type, string $parentType): string
    {
        $parentLabel = self::labelFor($parentType);
        $article = preg_match('/^[aeiou]/i', $parentLabel) ? 'an' : 'a';
        return 'A ' . self::labelFor($type) . ' must belong to ' . $article . ' ' . $parentLabel . '.';
    }

    /** Sorts units by hierarchy level (root → leaf), then by name. */
    public static function sortByLevel(array $units): array
    {
        $order = array_flip(self::types());
        usort($units, static function (array $a, array $b) use ($order): int {
            $ai = $order[$a['type']] ?? 99;
            $bi = $order[$b['type']] ?? 99;
            return $ai === $bi ? strcasecmp((string) $a['name'], (string) $b['name']) : $ai <=> $bi;
        });
        return $units;
    }

    /**
     * Validates an ordered list of unit ids as a root → depth chain. Returns the
     * unit rows when every link checks out, otherwise an empty array. The
     * registration form posts the chosen branch this way.
     */
    public static function validateChain(array $ids): array
    {
        $types = self::types();
        $chain = [];
        foreach (array_values($ids) as $depth => $rawId) {
            $id = (int) $rawId;
            if ($id <= 0 || !isset($types[$depth])) {
                return [];
            }
            $unit = self::find($id);
            if (!$unit || $unit['type'] !== $types[$depth]) {
                return [];
            }
            $expectedParent = $depth === 0 ? null : (int) $ids[$depth - 1];
            $actualParent = $unit['parent_id'] !== null ? (int) $unit['parent_id'] : null;
            if ($actualParent !== $expectedParent) {
                return [];
            }
            $chain[] = $unit;
        }
        return $chain;
    }

    /**
     * Reads the posted branch of the tree as an ordered list of ids. Accepts the
     * JSON array the registration form posts (`unit_path`) and falls back to the
     * legacy province_id/zone_id/area_id trio so older pages still submit.
     */
    public static function decodePath($raw, ?int $legacyAreaId = null): array
    {
        $ids = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $ids[] = is_array($entry) ? (int) ($entry['id'] ?? 0) : (int) $entry;
                }
            }
        }
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
        if ($ids) {
            return $ids;
        }
        if ($legacyAreaId !== null && $legacyAreaId > 0) {
            return array_map(static fn (array $u): int => (int) $u['id'], self::path($legacyAreaId));
        }
        return [];
    }

    /** Level rows with their depth and how many units use them (Unit Levels admin). */
    public static function levelsWithCounts(): array
    {
        $counts = [];
        foreach (self::db()->query('SELECT type, COUNT(*) AS n FROM org_units GROUP BY type')->fetchAll() as $r) {
            $counts[(string) $r['type']] = (int) $r['n'];
        }
        $out = [];
        foreach (self::levels() as $i => $level) {
            $level['position'] = $i + 1;
            $level['unit_count'] = $counts[$level['type']] ?? 0;
            $out[] = $level;
        }
        return $out;
    }

    /** Appends a new level at the bottom of the hierarchy. */
    public static function levelCreate(string $label, string $plural): array
    {
        $label = trim($label);
        if ($label === '') {
            return ['errors' => ['Please provide a level name.']];
        }
        $plural = trim($plural) !== '' ? trim($plural) : $label . 's';
        $type = self::levelKey($label);
        if ($type === '') {
            return ['errors' => ['Please use at least one letter or number in the level name.']];
        }
        if (in_array($type, self::types(), true)) {
            $type .= '_2';
        }
        $next = (int) self::db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM unit_levels')->fetchColumn();
        self::db()->prepare('INSERT INTO unit_levels (type, label, plural, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$type, $label, $plural, $next]);
        self::forgetLevels();
        return ['id' => (int) self::db()->lastInsertId(), 'type' => $type];
    }

    /** Renames a level (singular + plural). */
    public static function levelUpdate(string $type, string $label, string $plural): array
    {
        $label = trim($label);
        if ($label === '') {
            return ['errors' => ['Please provide a level name.']];
        }
        if (self::levelIndex($type) === null) {
            return ['errors' => ['That level no longer exists.']];
        }
        $plural = trim($plural) !== '' ? trim($plural) : $label . 's';
        self::db()->prepare('UPDATE unit_levels SET label = ?, plural = ? WHERE type = ?')->execute([$label, $plural, $type]);
        self::forgetLevels();
        return ['type' => $type];
    }

    /** Moves a level one step up or down the hierarchy. */
    public static function levelMove(string $type, string $direction): array
    {
        $levels = self::levelsWithCounts();
        $index = null;
        foreach ($levels as $i => $l) {
            if ($l['type'] === $type) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return ['errors' => ['That level no longer exists.']];
        }
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swap < 0 || $swap >= count($levels)) {
            return ['errors' => ['That level is already at the ' . ($direction === 'up' ? 'top' : 'bottom') . '.']];
        }
        // Rewrite every sort_order in one pass. sort_order is UNIQUE, so park all
        // rows out of the target range first — otherwise assigning 5 to a level
        // that is swapping with 4 collides with the row not yet moved.
        $order = array_column($levels, 'type');
        [$order[$index], $order[$swap]] = [$order[$swap], $order[$index]];
        $pdo = self::db();
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE unit_levels SET sort_order = sort_order + 1000');
            $stmt = $pdo->prepare('UPDATE unit_levels SET sort_order = ? WHERE type = ?');
            foreach ($order as $i => $t) {
                $stmt->execute([$i + 1, $t]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['errors' => ['Could not reorder the levels.']];
        }
        self::forgetLevels();
        return ['type' => $type];
    }

    /** Removes a level — refused while any unit still uses it. */
    public static function levelDelete(string $type): array
    {
        if (self::levelCount() <= 1) {
            return ['errors' => ['At least one level is required.']];
        }
        if (self::levelIndex($type) === null) {
            return ['errors' => ['That level no longer exists.']];
        }
        $stmt = self::db()->prepare('SELECT COUNT(*) FROM org_units WHERE type = ?');
        $stmt->execute([$type]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            return ['errors' => ['Cannot remove ' . self::labelFor($type) . ' — ' . $count . ' unit(s) still use it. Move or delete them first.']];
        }
        self::db()->prepare('DELETE FROM unit_levels WHERE type = ?')->execute([$type]);
        self::forgetLevels();
        return ['type' => $type];
    }

    /** Stable key for a new level, e.g. "District Group" → "district_group". */
    private static function levelKey(string $label): string
    {
        return strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
    }

    public static function all(string $order = 'sort_order ASC, name ASC'): array
    {
        return self::db()->query("SELECT * FROM org_units ORDER BY {$order}")->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM org_units WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function byType(string $type): array
    {
        $stmt = self::db()->prepare('SELECT * FROM org_units WHERE type = ? ORDER BY name ASC');
        $stmt->execute([$type]);
        return $stmt->fetchAll();
    }

    /** Direct children of a unit, ordered. */
    public static function children(int $parentId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM org_units WHERE parent_id = ? ORDER BY name ASC');
        $stmt->execute([$parentId]);
        return $stmt->fetchAll();
    }

    /** Ancestor chain root→child, excluding $id itself. */
    public static function ancestors(int $id): array
    {
        $chain = [];
        $unit = self::find($id);
        while ($unit && $unit['parent_id'] !== null) {
            $unit = self::find((int) $unit['parent_id']);
            if ($unit) {
                $chain[] = $unit;
            }
        }
        return array_reverse($chain);
    }

    /** Full path from root down to $id (missing levels omitted). */
    public static function path(int $id): array
    {
        $path = self::ancestors($id);
        $unit = self::find($id);
        if ($unit) {
            $path[] = $unit;
        }
        return $path;
    }

    /** Human label, e.g. "Parish 12 · Area A · Zone 3 · LP 63". */
    public static function label(int $id): string
    {
        return implode(' · ', array_map(fn (array $u): string => $u['name'], self::path($id)));
    }

    /** id => full label for every unit, computed in a single pass. */
    public static function labelsById(): array
    {
        $all = self::all('id ASC');
        $byId = [];
        foreach ($all as $u) {
            $byId[(int) $u['id']] = $u;
        }
        $labelOf = function (array $u) use (&$labelOf, $byId): string {
            $parts = [$u['name']];
            $cur = $u;
            while ($cur['parent_id'] !== null && isset($byId[(int) $cur['parent_id']])) {
                $cur = $byId[(int) $cur['parent_id']];
                $parts[] = $cur['name'];
            }
            return implode(' · ', array_reverse($parts));
        };
        $labels = [];
        foreach ($all as $u) {
            $labels[(int) $u['id']] = $labelOf($u);
        }
        return $labels;
    }

    /**
     * Admin isolation scope for a non-super user: strictly their own church
     * (exact org_unit_id match — no roll-up). Returns '' for the super admin
     * (see everything) and '1 = 0' when the user has no assigned unit.
     */
    public static function scopeClause(?array $user, string $column): string
    {
        if ($user && !empty($user['is_super_admin'])) {
            return '';
        }
        $unitId = ($user && !empty($user['org_unit_id'])) ? (int) $user['org_unit_id'] : 0;
        return $unitId > 0 ? $column . ' = ' . $unitId : '1 = 0';
    }

    /** Whether a record's org_unit_id is inside the user's strict admin scope. */
    public static function inScope(?array $user, ?int $orgUnitId): bool
    {
        if ($user && !empty($user['is_super_admin'])) {
            return true;
        }
        $unitId = ($user && !empty($user['org_unit_id'])) ? (int) $user['org_unit_id'] : 0;
        return $unitId > 0 && $orgUnitId === $unitId;
    }

    /** Loads a row's org_unit_id and checks it against the user's admin scope. */
    public static function recordInScope(PDO $pdo, string $table, int $id, ?array $user): bool
    {
        if ($user && !empty($user['is_super_admin'])) {
            return true;
        }
        $stmt = $pdo->prepare("SELECT org_unit_id FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        $oid = $stmt->fetchColumn();
        return self::inScope($user, $oid === false || $oid === null ? null : (int) $oid);
    }

    /** Units the user may assign content to: all for the super admin, subtree otherwise. */
    public static function assignableScope(?array $user): array
    {
        if ($user && !empty($user['is_super_admin'])) {
            return self::sortByLevel(self::all('name ASC'));
        }
        $unitId = ($user && !empty($user['org_unit_id'])) ? (int) $user['org_unit_id'] : 0;
        if ($unitId <= 0) {
            return [];
        }
        $ids = array_flip(self::subtreeIds($unitId));
        $out = [];
        foreach (self::all('name ASC') as $u) {
            if (isset($ids[(int) $u['id']])) {
                $out[] = $u;
            }
        }
        return self::sortByLevel($out);
    }

    /** Whether a unit id is inside the user's assignable scope. */
    public static function inAssignableScope(?array $user, int $unitId): bool
    {
        foreach (self::assignableScope($user) as $u) {
            if ((int) $u['id'] === $unitId) {
                return true;
            }
        }
        return false;
    }

    /** $id plus every descendant id — used for "all media in a unit" roll-ups. */
    public static function subtreeIds(int $id): array
    {
        $ids = [$id];
        $childrenOf = [];
        foreach (self::all('id ASC') as $u) {
            if ($u['parent_id'] !== null) {
                $childrenOf[(int) $u['parent_id']][] = (int) $u['id'];
            }
        }
        $queue = [$id];
        while ($queue) {
            $cur = array_shift($queue);
            foreach ($childrenOf[$cur] ?? [] as $child) {
                $ids[] = $child;
                $queue[] = $child;
            }
        }
        return $ids;
    }

    /** Nested tree [{... 'children' => [...]}] rooted at the top level. */
    public static function tree(): array
    {
        $byParent = [];
        foreach (self::all('sort_order ASC, name ASC') as $u) {
            $byParent[(int) ($u['parent_id'] ?? 0)][] = $u;
        }
        $build = function (int $parentId) use (&$build, $byParent): array {
            $out = [];
            foreach ($byParent[$parentId] ?? [] as $u) {
                $u['children'] = $build((int) $u['id']);
                $out[] = $u;
            }
            return $out;
        };
        return $build(0);
    }

    /** Church names are normalised to CAPS. */
    public static function nameFor(string $name): string
    {
        return mb_strtoupper(trim($name));
    }

    /** Find a unit by (case-insensitive) name + type + parent. */
    public static function findByName(string $type, string $name, ?int $parentId): ?array
    {
        $sql = 'SELECT * FROM org_units WHERE type = ? AND UPPER(name) = UPPER(?) AND parent_id ' . ($parentId === null ? 'IS NULL' : '= ?') . ' ORDER BY id ASC LIMIT 1';
        $stmt = self::db()->prepare($sql);
        $params = [$type, $name];
        if ($parentId !== null) {
            $params[] = $parentId;
        }
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /** Find a unit with this name anywhere in the hierarchy (used for correction flags). */
    public static function findByNameAnywhere(string $name): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM org_units WHERE UPPER(name) = UPPER(?) ORDER BY id ASC LIMIT 1');
        $stmt->execute([$name]);
        return $stmt->fetch() ?: null;
    }

    /** Find an existing unit (case-insensitive) or create it; names normalised to CAPS. */
    public static function findOrCreate(string $type, ?int $parentId, string $name): array
    {
        $name = self::nameFor($name);
        if ($name === '') {
            return ['errors' => ['Please provide a name.']];
        }
        $existing = self::findByName($type, $name, $parentId);
        if ($existing) {
            return ['id' => (int) $existing['id']];
        }
        return self::create($type, $parentId, $name);
    }

    /** Lightweight nested tree (id, name, type, children) safe to embed in a page/API. */
    public static function treeLight(): array
    {
        $map = function (array $nodes) use (&$map): array {
            $out = [];
            foreach ($nodes as $n) {
                $out[] = [
                    'id' => (int) $n['id'],
                    'name' => $n['name'],
                    'type' => $n['type'],
                    'children' => $map($n['children'] ?? []),
                ];
            }
            return $out;
        };
        return $map(self::tree());
    }

    /** Create a unit; returns ['id'=>..] or ['errors'=>[..]]. */
    public static function create(string $type, ?int $parentId, string $name): array
    {
        $type = in_array($type, self::types(), true) ? $type : self::rootType();
        $expectedParent = self::parentType($type);

        if ($expectedParent === null) {
            $parentId = null;
        } elseif ($parentId !== null) {
            $parent = self::find($parentId);
            if (!$parent || $parent['type'] !== $expectedParent) {
                return ['errors' => [self::parentError($type, $expectedParent)]];
            }
        } else {
            return ['errors' => [self::parentError($type, $expectedParent)]];
        }

        $name = self::nameFor($name);
        if ($name === '') {
            return ['errors' => ['Please provide a name.']];
        }

        $slug = self::uniqueSlug($name);
        $stmt = self::db()->prepare('INSERT INTO org_units (parent_id, type, name, slug) VALUES (?, ?, ?, ?)');
        $stmt->execute([$parentId, $type, $name, $slug]);
        return ['id' => (int) self::db()->lastInsertId()];
    }

    /** Update a unit; returns ['id'=>..] or ['errors'=>[..]]. */
    public static function update(int $id, string $type, ?int $parentId, string $name): array
    {
        $existing = self::find($id);
        if (!$existing) {
            return ['errors' => ['Unit not found.']];
        }
        $type = in_array($type, self::types(), true) ? $type : $existing['type'];
        $expectedParent = self::parentType($type);

        if ($parentId !== null && in_array($id, self::subtreeIds($parentId), true)) {
            return ['errors' => ['A unit cannot be nested inside itself or one of its own children.']];
        }
        if ($expectedParent === null) {
            $parentId = null;
        } elseif ($parentId !== null) {
            $parent = self::find($parentId);
            if (!$parent || $parent['type'] !== $expectedParent) {
                return ['errors' => [self::parentError($type, $expectedParent)]];
            }
        } else {
            return ['errors' => [self::parentError($type, $expectedParent)]];
        }

        $name = self::nameFor($name);
        if ($name === '') {
            return ['errors' => ['Please provide a name.']];
        }

        $slug = self::uniqueSlug($name, $id);
        $stmt = self::db()->prepare('UPDATE org_units SET parent_id = ?, type = ?, name = ?, slug = ? WHERE id = ?');
        $stmt->execute([$parentId, $type, $name, $slug, $id]);
        return ['id' => $id];
    }

    /** Delete a unit (children cascade; posts/users under it are set to NULL). */
    public static function delete(int $id): void
    {
        self::db()->prepare('DELETE FROM org_units WHERE id = ?')->execute([$id]);
    }

    private static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-')) ?: 'unit';
        $slug = $base;
        $i = 2;
        while (true) {
            $stmt = self::db()->prepare('SELECT id FROM org_units WHERE slug = ? AND id <> ?');
            $stmt->execute([$slug, $ignoreId ?? 0]);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $slug = $base . '-' . $i++;
        }
    }
}

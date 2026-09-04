<?php
declare(strict_types=1);

/**
 * Organizational hierarchy: Province → Zone → Area → Parish (RCCG-style).
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

    /** Level names in hierarchy order (root → leaf). */
    public static function types(): array
    {
        return ['province', 'zone', 'area', 'parish'];
    }

    /** Valid parent type for a unit type (null = top level). */
    public static function parentType(?string $type): ?string
    {
        return match ($type) {
            'zone' => 'province',
            'area' => 'zone',
            'parish' => 'area',
            default => null, // province (and anything unknown) has no parent
        };
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
            return self::all('type ASC, name ASC');
        }
        $unitId = ($user && !empty($user['org_unit_id'])) ? (int) $user['org_unit_id'] : 0;
        if ($unitId <= 0) {
            return [];
        }
        $ids = array_flip(self::subtreeIds($unitId));
        $out = [];
        foreach (self::all('type ASC, name ASC') as $u) {
            if (isset($ids[(int) $u['id']])) {
                $out[] = $u;
            }
        }
        return $out;
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
        $stmt = self::db()->prepare('SELECT * FROM org_units WHERE UPPER(name) = UPPER(?) ORDER BY type ASC, id ASC LIMIT 1');
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
        $type = in_array($type, self::types(), true) ? $type : 'province';
        $expectedParent = self::parentType($type);

        if ($expectedParent === null) {
            $parentId = null;
        } elseif ($parentId !== null) {
            $parent = self::find($parentId);
            if (!$parent || $parent['type'] !== $expectedParent) {
                return ['errors' => ['A ' . $type . ' must belong to a ' . $expectedParent . '.']];
            }
        } else {
            return ['errors' => ['A ' . $type . ' must belong to a ' . $expectedParent . '.']];
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
                return ['errors' => ['A ' . $type . ' must belong to a ' . $expectedParent . '.']];
            }
        } else {
            return ['errors' => ['A ' . $type . ' must belong to a ' . $expectedParent . '.']];
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

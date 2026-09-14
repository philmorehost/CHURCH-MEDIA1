<?php
declare(strict_types=1);

/**
 * Duty roster — who is serving at a given service.
 *
 * Three things with three different lifetimes, which is why they are three tables: a *service* is a
 * date, a *role* is a slot within that service ("Ushering ×4"), and an *assignment* is a person in
 * that slot. Roles carry the count rather than one row per person because that is how a church says
 * it out loud; assignments carry the people.
 *
 * Two decisions worth knowing before changing anything here:
 *
 *  - **A person does not have to be a member.** Ushers and choir members frequently are not
 *    registered, so `member_id` is nullable and a typed name is enough. Requiring sign-up before
 *    somebody can be put on a rota would make this unusable in an ordinary church.
 *  - **A declined slot is kept, not deleted.** The planner needs to see who said no in order to ask
 *    somebody else, and a row that quietly disappears loses that.
 *
 * Scope is the strict one used everywhere else in the admin (`Unit::inScope`): a super admin sees
 * every church, a church admin sees only rosters whose `org_unit_id` is exactly their own.
 */
final class ServiceRoster
{
    public const MAX_TITLE = 150;
    public const MAX_TIME = 40;
    public const MAX_LOCATION = 200;
    public const MAX_ROLE = 80;
    public const MAX_SLOTS = 200;
    public const MAX_NAME = 150;
    public const MAX_NOTES = 255;

    /** Offered as suggestions in the form. Free text — a church's roles are its own. */
    public const ROLE_PRESETS = ['Ushering', 'Choir', 'Media', 'Children', 'Sanctuary', 'Worship', 'Protocol', 'Prayer'];

    public const STATUSES = ['invited', 'accepted', 'declined'];

    private static ?PDO $pdo = null;

    private static function db(): PDO
    {
        return self::$pdo ??= Database::getInstance()->getConnection();
    }

    /* ------------------------------------------------------------------ services -- */

    /**
     * Creates or updates one service. Passing id 0 creates.
     *
     * @return array{errors?: array<int, string>, ok?: bool, id?: int}
     */
    public static function savePlan(int $id, array $in): array
    {
        $errors = [];

        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Give the service a name — "Sunday 1st Service" is enough.';
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $errors[] = 'That service name is too long.';
        }

        $date = self::validDate((string) ($in['service_date'] ?? ''));
        if ($date === null) {
            $errors[] = 'Choose the date of the service.';
        }

        $time = trim((string) ($in['service_time'] ?? ''));
        if (mb_strlen($time) > self::MAX_TIME) {
            $errors[] = 'That time is too long — keep it short, like "8:00 AM".';
        }

        $location = trim((string) ($in['location'] ?? ''));
        if (mb_strlen($location) > self::MAX_LOCATION) {
            $errors[] = 'That location is too long.';
        }

        $notes = trim((string) ($in['notes'] ?? ''));
        $unitId = (int) ($in['org_unit_id'] ?? 0);

        if ($errors) {
            return ['errors' => $errors];
        }

        $pdo = self::db();
        $fields = [
            'title' => $title,
            'service_date' => $date,
            'service_time' => $time !== '' ? $time : null,
            'location' => $location !== '' ? $location : null,
            'notes' => $notes !== '' ? $notes : null,
            'is_cancelled' => empty($in['is_cancelled']) ? 0 : 1,
            'org_unit_id' => $unitId > 0 ? $unitId : null,
        ];

        if ($id > 0) {
            if (self::find($id) === null) {
                return ['errors' => ['That service no longer exists.']];
            }
            $sets = [];
            $params = [];
            foreach ($fields as $column => $value) {
                $sets[] = '`' . $column . '` = ?';
                $params[] = $value;
            }
            $params[] = $id;
            $pdo->prepare('UPDATE service_plans SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
            return ['ok' => true, 'id' => $id];
        }

        $fields['tenant_id'] = class_exists('Tenant') ? (Tenant::id() ?? 0) : 0;
        $fields['created_by'] = (int) ($in['created_by'] ?? 0) ?: null;

        $columns = array_keys($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $pdo->prepare('INSERT INTO service_plans (`' . implode('`, `', $columns) . '`) VALUES (' . $placeholders . ')')
            ->execute(array_values($fields));

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    public static function deletePlan(int $id): void
    {
        // Roles and assignments go with it — both foreign keys cascade. Losing the assignments is
        // the point of deleting a service: it never happened.
        self::db()->prepare('DELETE FROM service_plans WHERE id = ?')->execute([$id]);
    }

    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM service_plans WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Services the user may see, newest first within each window.
     *
     * @param string $scope 'upcoming' (today onward), 'past', or 'all'
     * @return array<int, array<string, mixed>>
     */
    public static function listPlans(?array $user, string $scope = 'upcoming', int $limit = 200): array
    {
        $where = [];
        if ($scope === 'upcoming') {
            $where[] = 'service_date >= CURDATE()';
        } elseif ($scope === 'past') {
            $where[] = 'service_date < CURDATE()';
        }

        $sql = 'SELECT * FROM service_plans';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        // Upcoming reads best soonest-first (what is next), past reads best most-recent-first.
        $sql .= $scope === 'past' ? ' ORDER BY service_date DESC, id DESC' : ' ORDER BY service_date ASC, id ASC';
        $sql .= ' LIMIT ' . max(1, min(1000, $limit));

        $plans = self::db()->query($sql)->fetchAll();

        $out = [];
        foreach ($plans as $plan) {
            if (!self::inScope($user, $plan)) {
                continue;
            }
            $plan['id'] = (int) $plan['id'];
            $plan['is_cancelled'] = (int) $plan['is_cancelled'];
            $out[] = $plan;
        }
        return $out;
    }

    /** True when this plan is inside the user's admin scope. */
    public static function inScope(?array $user, array $plan): bool
    {
        $unit = $plan['org_unit_id'] ?? null;
        return Unit::inScope($user, $unit === null ? null : (int) $unit);
    }

    /** True when the user may edit this exact plan. */
    public static function canEdit(?array $user, int $planId): bool
    {
        $plan = self::find($planId);
        return $plan !== null && self::inScope($user, $plan);
    }

    /* --------------------------------------------------------------------- roles -- */

    /** @return array<int, array<string, mixed>> */
    public static function roles(int $planId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM service_roles WHERE plan_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$planId]);
        $roles = $stmt->fetchAll();
        foreach ($roles as &$role) {
            $role['id'] = (int) $role['id'];
            $role['slots_needed'] = (int) $role['slots_needed'];
        }
        unset($role);
        return $roles;
    }

    /** @return array{errors?: array<int, string>, ok?: bool, id?: int} */
    public static function saveRole(int $planId, int $roleId, array $in): array
    {
        if (self::find($planId) === null) {
            return ['errors' => ['That service no longer exists.']];
        }

        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Name the role — "Ushering", "Choir", or whatever your church calls it.';
        } elseif (mb_strlen($name) > self::MAX_ROLE) {
            $errors[] = 'That role name is too long.';
        }

        $slotsRaw = trim((string) ($in['slots_needed'] ?? '1'));
        $slots = (int) $slotsRaw;
        if (!preg_match('/^\d+$/', $slotsRaw) || $slots < 1 || $slots > self::MAX_SLOTS) {
            $errors[] = 'How many people are needed? Give a whole number between 1 and ' . self::MAX_SLOTS . '.';
        }

        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > self::MAX_NOTES) {
            $errors[] = 'Those notes are too long.';
        }

        if ($errors) {
            return ['errors' => $errors];
        }

        $pdo = self::db();

        if ($roleId > 0) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM service_roles WHERE id = ? AND plan_id = ?');
            $check->execute([$roleId, $planId]);
            if ((int) $check->fetchColumn() === 0) {
                return ['errors' => ['That role is not part of this service.']];
            }
            $pdo->prepare('UPDATE service_roles SET name = ?, slots_needed = ?, notes = ? WHERE id = ?')
                ->execute([$name, $slots, $notes !== '' ? $notes : null, $roleId]);
            return ['ok' => true, 'id' => $roleId];
        }

        // Appended to the end: a new role belongs at the bottom of the list, where the planner
        // is looking, rather than wherever an unstable sort happens to place it.
        $nextStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM service_roles WHERE plan_id = ?');
        $nextStmt->execute([$planId]);
        $next = (int) $nextStmt->fetchColumn();

        $pdo->prepare('INSERT INTO service_roles (plan_id, name, slots_needed, notes, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$planId, $name, $slots, $notes !== '' ? $notes : null, $next]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    public static function deleteRole(int $roleId): void
    {
        self::db()->prepare('DELETE FROM service_roles WHERE id = ?')->execute([$roleId]);
    }

    /** The role row plus the plan id it belongs to, so callers can check scope. */
    public static function findRole(int $roleId): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT r.*, p.id AS plan_id, p.org_unit_id, p.service_date, p.title AS plan_title'
            . ' FROM service_roles r JOIN service_plans p ON p.id = r.plan_id WHERE r.id = ? LIMIT 1'
        );
        $stmt->execute([$roleId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The assignment plus the plan it belongs to.
     *
     * Every write to an assignment needs the owning plan so scope can be checked, and doing that
     * lookup here rather than in each screen means a screen cannot forget to do it.
     */
    public static function findAssignment(int $assignmentId): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT a.*, r.plan_id, r.name AS role_name, p.org_unit_id, p.service_date, p.title AS plan_title'
            . ' FROM service_assignments a'
            . ' JOIN service_roles r ON r.id = a.role_id'
            . ' JOIN service_plans p ON p.id = r.plan_id WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$assignmentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Members who could be put on a roster.
     *
     * Narrowed to the user's own church rather than everyone in the install: an admin should not be
     * choosing from another church's member list, and a picker of every member in a multi-church
     * install would be unusable besides. Suspended members are left out — they have been asked not
     * to serve.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function assignableMembers(?array $user, int $limit = 400): array
    {
        $unitId = (int) ($user['org_unit_id'] ?? 0);
        $sql = 'SELECT id, name, phone, org_unit_id FROM members WHERE is_suspended = 0';
        $params = [];

        // A super admin sees every church, which is the same rule as Unit::inScope.
        if (!($user && !empty($user['is_super_admin'])) && $unitId > 0) {
            $sql .= ' AND org_unit_id = ?';
            $params[] = $unitId;
        }

        $sql .= ' ORDER BY name ASC LIMIT ' . max(1, min(1000, $limit));
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        return $rows;
    }

    /* --------------------------------------------------------------- assignments -- */

    /** @return array<int, array<string, mixed>> */
    public static function assignments(int $roleId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM service_assignments WHERE role_id = ? ORDER BY id ASC');
        $stmt->execute([$roleId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['member_id'] = $row['member_id'] !== null ? (int) $row['member_id'] : null;
            $row['phone_display'] = Phone::display($row['person_phone']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Puts somebody in a slot. Accepts either a member id or a typed name.
     *
     * @return array{errors?: array<int, string>, ok?: bool, id?: int}
     */
    public static function assign(int $roleId, array $in): array
    {
        $role = self::findRole($roleId);
        if ($role === null) {
            return ['errors' => ['That role no longer exists.']];
        }

        $pdo = self::db();
        $memberId = (int) ($in['member_id'] ?? 0);
        $member = null;

        if ($memberId > 0) {
            $stmt = $pdo->prepare('SELECT id, name, phone FROM members WHERE id = ? LIMIT 1');
            $stmt->execute([$memberId]);
            $member = $stmt->fetch() ?: null;
            if ($member === null) {
                return ['errors' => ['That member no longer exists.']];
            }
        }

        $errors = [];
        // A member's own name is used unless the planner typed something different, which is how a
        // preferred name ("Ada B.") gets on the printed rota instead of the registered one.
        $name = trim((string) ($in['person_name'] ?? ''));
        if ($name === '' && $member !== null) {
            $name = trim((string) $member['name']);
        }
        if ($name === '') {
            $errors[] = 'Who is serving? Pick a member or type their name.';
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors[] = 'That name is too long.';
        }

        $phoneRaw = trim((string) ($in['person_phone'] ?? ''));
        if ($phoneRaw === '' && $member !== null) {
            $phoneRaw = trim((string) ($member['phone'] ?? ''));
        }
        $phone = null;
        if ($phoneRaw !== '') {
            $phone = Phone::normalise($phoneRaw);
            if ($phone === null) {
                $errors[] = 'That contact number does not look right. Include the country code, or leave it empty.';
            }
        }

        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > self::MAX_NOTES) {
            $errors[] = 'Those notes are too long.';
        }

        if ($errors) {
            return ['errors' => $errors];
        }

        $status = in_array((string) ($in['status'] ?? ''), self::STATUSES, true) ? (string) $in['status'] : 'invited';
        $stmt = $pdo->prepare(
            'INSERT INTO service_assignments (role_id, member_id, person_name, person_phone, status, invited_at, notes)'
            . ' VALUES (?, ?, ?, ?, ?, NOW(), ?)'
        );
        $stmt->execute([
            $roleId,
            $member !== null ? (int) $member['id'] : null,
            $name,
            $phone,
            $status,
            $notes !== '' ? $notes : null,
        ]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    /** @return array{errors?: array<int, string>, ok?: bool} */
    public static function respond(int $assignmentId, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            return ['errors' => ['That is not a valid answer.']];
        }
        $stmt = self::db()->prepare('UPDATE service_assignments SET status = ?, responded_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $assignmentId]);
        return $stmt->rowCount() > 0 ? ['ok' => true] : ['errors' => ['That slot no longer exists.']];
    }

    /**
     * Removes somebody from a slot.
     *
     * Different from responding "declined": this says the invitation should never have existed.
     */
    public static function unassign(int $assignmentId): void
    {
        self::db()->prepare('DELETE FROM service_assignments WHERE id = ?')->execute([$assignmentId]);
    }

    /* --------------------------------------------------------------------- views -- */

    /**
     * One service with its roles, the people in them, and the counts a planner actually reads.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function roster(int $planId): array
    {
        $out = [];
        foreach (self::roles($planId) as $role) {
            $people = self::assignments((int) $role['id']);
            $accepted = 0;
            $invited = 0;
            $declined = 0;
            foreach ($people as $person) {
                if ($person['status'] === 'accepted') {
                    $accepted++;
                } elseif ($person['status'] === 'invited') {
                    $invited++;
                } else {
                    $declined++;
                }
            }
            // "Still to find" counts confirmed people only. An invitation is not a promise, and a
            // planner who reads invited as filled stops looking before anyone has said yes.
            $role['people'] = $people;
            $role['accepted'] = $accepted;
            $role['invited'] = $invited;
            $role['declined'] = $declined;
            $role['open'] = max(0, (int) $role['slots_needed'] - $accepted);
            $out[] = $role;
        }
        return $out;
    }

    /**
     * Roles that still need somebody, most under-staffed first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function shortfall(array $roster): array
    {
        $short = array_values(array_filter($roster, static fn (array $r): bool => $r['open'] > 0));
        usort($short, static fn (array $a, array $b): int => $b['open'] <=> $a['open']);
        return $short;
    }

    /** Total slots, and how many are confirmed — the number at the top of the roster. */
    public static function totals(array $roster): array
    {
        $needed = 0;
        $filled = 0;
        foreach ($roster as $role) {
            $needed += (int) $role['slots_needed'];
            $filled += (int) $role['accepted'];
        }
        return ['needed' => $needed, 'filled' => $filled, 'open' => max(0, $needed - $filled)];
    }

    /**
     * "Who is serving" on a date — every plan that day, in scope, with its roster.
     *
     * The unit filter is optional because the common question is church-wide ("who is on this
     * Sunday?"), while a church admin's own scope already narrows it.
     *
     * @return array<int, array{plan: array<string, mixed>, roster: array<int, array<string, mixed>>, totals: array<string, int>}>
     */
    public static function servingOn(string $date, ?array $user = null, ?int $unitId = null): array
    {
        $date = self::validDate($date);
        if ($date === null) {
            return [];
        }

        $sql = 'SELECT * FROM service_plans WHERE service_date = ? AND is_cancelled = 0';
        $params = [$date];
        if ($unitId !== null && $unitId > 0) {
            $sql .= ' AND org_unit_id = ?';
            $params[] = $unitId;
        }
        $sql .= ' ORDER BY service_time ASC, id ASC';

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $plan) {
            if (!self::inScope($user, $plan)) {
                continue;
            }
            $roster = self::roster((int) $plan['id']);
            $out[] = ['plan' => $plan, 'roster' => $roster, 'totals' => self::totals($roster)];
        }
        return $out;
    }

    /** How many slots are still open across every upcoming service — the sidebar's nudge. */
    public static function openSlots(?array $user, int $days = 14): int
    {
        $stmt = self::db()->prepare(
            'SELECT id, org_unit_id FROM service_plans'
            . ' WHERE service_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND is_cancelled = 0'
        );
        $stmt->execute([max(1, $days)]);

        $open = 0;
        foreach ($stmt->fetchAll() as $plan) {
            if (!self::inScope($user, $plan)) {
                continue;
            }
            $totals = self::totals(self::roster((int) $plan['id']));
            $open += $totals['open'];
        }
        return $open;
    }

    /**
     * A date in Y-m-d, or null when it is not a real one.
     *
     * The round-trip check is what rejects 2026-02-31 — PHP will happily roll it forward to March 3,
     * which would silently file a service under the wrong day.
     */
    public static function validDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        return $parsed !== false && $parsed->format('Y-m-d') === $raw ? $raw : null;
    }

    /** "Sunday 2 March" for a date, used in headings. */
    public static function dateLabel(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed === false ? $date : $parsed->format('l j F Y');
    }
}

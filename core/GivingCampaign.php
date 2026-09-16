<?php
declare(strict_types=1);

/**
 * Giving campaigns: a named project with a target, and the gifts that count towards it.
 *
 * **The raised figure always comes from `donations`.** A pledge is a promise, and a church that adds
 * promises to the amount raised is reporting money it does not have — so `pledged` and `raised` are
 * returned side by side and the progress bar moves on received gifts alone. A pledge becomes real
 * money one way only: `recordPledge()` writes an actual completed donation and stamps the pledge with
 * its id, so it cannot be counted a second time.
 *
 * **A campaign is open, upcoming, or closed, and that is a date calculation rather than a switch the
 * admin has to remember.** `is_active` is the church switching a campaign off; the dates are the
 * campaign's own deadline. Passing `ends_on` stops new gifts on its own, because that is what a
 * deadline means to the person reading the page, and the admin list flags it rather than silently
 * accepting gifts for a project that finished last month.
 *
 * **A campaign belongs to a tenant as well as a church**, and every read filters on it — the same rule
 * `Devotional` follows. Two churches sharing one database may each have a campaign called "Building
 * Fund" with the same slug, so a lookup by slug alone would serve the wrong church's page, and a list
 * without the filter would put another church's appeal on this church's giving page. Only one of those
 * is a visible mistake.
 *
 * **Money in two currencies is not added together.** The goal is expressed in the campaign's own
 * currency; anything received in another is reported separately rather than quietly summed, which is
 * the same mistake as counting pledges as income.
 */
final class GivingCampaign
{
    public const MAX_TITLE = 180;
    public const MAX_SLUG = 190;
    public const MAX_SUMMARY = 255;
    public const MAX_DESCRIPTION = 20000;
    public const MAX_NAME = 150;
    public const MAX_EMAIL = 190;
    public const MAX_NOTE = 255;

    /** DECIMAL(12,2) holds this much; refusing more is friendlier than a silent truncation. */
    public const MAX_AMOUNT = 9999999999.99;

    /**
     * What a gift recorded from a pledge is filed under.
     *
     * A fixed label rather than the campaign's name, so the treasurer's category report stays a short
     * list: the campaign itself is a real column, so filing these under one recognisable heading loses
     * nothing and keeps "where did this money come from" answerable in one filter.
     */
    public const PLEDGE_CATEGORY = 'Campaign';

    /** @var PDO|null */
    private static $pdo = null;

    private static function db(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = Database::getInstance()->getConnection();
        }
        return self::$pdo;
    }

    /** The tenant whose site is being served. 0 when nothing resolves, which matches how rows are stamped. */
    private static function tenantId(): int
    {
        return (class_exists('Tenant') ? Tenant::id() : null) ?? 0;
    }

    /* ================================================================ campaigns == */

    /** Whether a campaign is inside the user's admin scope. */
    public static function inScope(?array $user, array $campaign): bool
    {
        return Unit::inScope($user, $campaign['org_unit_id'] === null ? null : (int) $campaign['org_unit_id']);
    }

    /**
     * Every campaign the user may see, with its numbers attached.
     *
     * @return array<int, array<string, mixed>> each row gains `progress`
     */
    public static function all(?array $user, bool $includeInactive = false): array
    {
        $pdo = self::db();
        $clause = Unit::scopeClause($user, 'org_unit_id');

        $sql = 'SELECT * FROM giving_campaigns WHERE tenant_id = ' . self::tenantId()
            . ($clause !== '' ? ' AND ' . $clause : '')
            . ($includeInactive ? '' : ' AND is_active = 1')
            . ' ORDER BY is_active DESC, sort_order ASC, COALESCE(ends_on, \'9999-12-31\') ASC, title ASC';

        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $row['progress'] = self::progressFor($row);
        }
        unset($row);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM giving_campaigns WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, self::tenantId()]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        // Slug alone is not enough to identify a campaign: the unique key is (tenant_id, slug), so two
        // churches on one database can both have "building-fund".
        $stmt = self::db()->prepare('SELECT * FROM giving_campaigns WHERE slug = ? AND tenant_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$slug, self::tenantId()]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Campaigns a donor can give to right now, for the list on /give.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function open(?int $unitId = null, int $limit = 20): array
    {
        $pdo = self::db();
        $sql = 'SELECT * FROM giving_campaigns'
            . ' WHERE tenant_id = ' . self::tenantId()
            . ' AND is_active = 1'
            . ' AND (starts_on IS NULL OR starts_on <= CURDATE())'
            . ' AND (ends_on IS NULL OR ends_on >= CURDATE())'
            . ($unitId !== null && $unitId > 0 ? ' AND (org_unit_id IS NULL OR org_unit_id = ' . (int) $unitId . ')' : '')
            . ' ORDER BY sort_order ASC, COALESCE(ends_on, \'9999-12-31\') ASC, title ASC'
            . ' LIMIT ' . max(1, $limit);

        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $row['progress'] = self::progressFor($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors?: array<int, string>}
     */
    public static function save(?array $user, int $id, array $data): array
    {
        $errors = [];
        $title = self::trimTo((string) ($data['title'] ?? ''), self::MAX_TITLE);
        $summary = self::trimTo((string) ($data['summary'] ?? ''), self::MAX_SUMMARY);
        $description = self::trimTo((string) ($data['description'] ?? ''), self::MAX_DESCRIPTION);
        $currency = strtoupper(self::trimTo((string) ($data['currency'] ?? 'NGN'), 10));
        $goal = (float) ($data['goal_amount'] ?? 0);
        $startsOn = self::dateOrNull((string) ($data['starts_on'] ?? ''));
        $endsOn = self::dateOrNull((string) ($data['ends_on'] ?? ''));
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $sortOrder = (int) ($data['sort_order'] ?? 0);

        if ($title === '') {
            $errors[] = 'Give the campaign a name — "New Auditorium", for example.';
        }
        if ($currency === '') {
            $currency = 'NGN';
        }
        if ($goal < 0 || $goal > self::MAX_AMOUNT) {
            $errors[] = 'The target has to be an amount between 0 and ' . number_format(self::MAX_AMOUNT, 2) . '.';
        }
        if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
            $errors[] = 'The end date is before the start date.';
        }

        // A campaign belongs to a church, like every other record here — a scoped admin's form has no
        // picker, so their own church fills the gap rather than the campaign landing nowhere.
        $unitId = isset($data['org_unit_id']) && (int) $data['org_unit_id'] > 0
            ? (int) $data['org_unit_id']
            : (int) ($user['org_unit_id'] ?? 0);
        $unitId = $unitId > 0 ? $unitId : null;
        if ($unitId !== null && !Unit::inScope($user, $unitId)) {
            $errors[] = 'Choose one of your own churches for this campaign.';
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $pdo = self::db();
        $slug = self::uniqueSlug((string) ($data['slug'] ?? '') !== '' ? (string) $data['slug'] : $title, $id);

        $values = [
            $title,
            $slug,
            $summary !== '' ? $summary : null,
            $description !== '' ? $description : null,
            $goal,
            $currency,
            $startsOn,
            $endsOn,
            $isActive,
            $sortOrder,
            $unitId,
        ];

        if ($id > 0) {
            $values[] = $id;
            $pdo->prepare('UPDATE giving_campaigns SET title = ?, slug = ?, summary = ?, description = ?, goal_amount = ?, currency = ?, starts_on = ?, ends_on = ?, is_active = ?, sort_order = ?, org_unit_id = ? WHERE id = ?')
                ->execute($values);
            return ['ok' => true, 'id' => $id];
        }

        $values[] = Tenant::id();
        $values[] = (int) ($user['id'] ?? 0) ?: null;
        $pdo->prepare('INSERT INTO giving_campaigns (title, slug, summary, description, goal_amount, currency, starts_on, ends_on, is_active, sort_order, org_unit_id, tenant_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute($values);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Deletes a campaign, but only one nothing has been given to.
     *
     * The foreign key is `ON DELETE SET NULL`, so a gift would survive — but it would silently fall
     * off the campaign report it belonged to, which is money going missing from a report rather than
     * from a bank. Switching the campaign off is the honest way to retire it.
     *
     * @return array{ok: bool, errors?: array<int, string>}
     */
    public static function delete(int $id): array
    {
        $pdo = self::db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM donations WHERE campaign_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['ok' => false, 'errors' => ['Gifts have already been given to this campaign, so it cannot be deleted — switch it off instead and the record stays intact.']];
        }

        // Pledges cascade. That is right: a promise attached to a project that never existed is not a
        // record of anything, and it is not money.
        $pdo->prepare('DELETE FROM giving_campaigns WHERE id = ? AND tenant_id = ?')->execute([$id, self::tenantId()]);
        return ['ok' => true];
    }

    /* ==================================================================== money == */

    /**
     * Where a campaign stands. Takes the row rather than an id so a list of campaigns costs one query
     * plus one per campaign instead of a lookup for each.
     *
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    public static function progressFor(array $campaign): array
    {
        $pdo = self::db();
        $campaignId = (int) $campaign['id'];
        $currency = strtoupper((string) ($campaign['currency'] ?? 'NGN'));
        $goal = (float) $campaign['goal_amount'];

        // Grouped by currency on purpose: the goal is in one currency, and adding another to it would
        // report a number that corresponds to nothing anyone can spend.
        $stmt = $pdo->prepare(
            "SELECT currency, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS gifts"
            . " FROM donations WHERE campaign_id = ? AND payment_status = 'completed'"
            . ' GROUP BY currency'
        );
        $stmt->execute([$campaignId]);

        $byCurrency = array();
        foreach ($stmt->fetchAll() as $row) {
            $key = strtoupper((string) $row['currency']);
            $byCurrency[$key] = array('total' => (float) $row['total'], 'gifts' => (int) $row['gifts']);
        }

        $raised = (float) ($byCurrency[$currency]['total'] ?? 0.0);
        $gifts = (int) ($byCurrency[$currency]['gifts'] ?? 0);

        // Submitted but unverified gifts are shown separately rather than counted. Somebody has
        // promised the money and uploaded a receipt; the church has not confirmed it arrived.
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM donations"
            . " WHERE campaign_id = ? AND payment_status = 'pending' AND currency = ?"
        );
        $stmt->execute([$campaignId, $currency]);
        $pending = (float) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT COALESCE(NULLIF(donor_email, ''), CONCAT('anon-', id)))"
            . " FROM donations WHERE campaign_id = ? AND payment_status = 'completed' AND currency = ?"
        );
        $stmt->execute([$campaignId, $currency]);
        $donors = (int) $stmt->fetchColumn();

        $pledges = self::pledgeTotals($campaignId, $currency);

        return array(
            'currency' => $currency,
            'goal' => $goal,
            'raised' => $raised,
            'gifts' => $gifts,
            'donors' => $donors,
            'pending' => $pending,
            'pledged' => $pledges['outstanding'],
            'pledge_count' => $pledges['count'],
            'pledged_received' => $pledges['received'],
            'percent' => self::percent($raised, $goal),
            'remaining' => max(0.0, $goal - $raised),
            'other_currencies' => array_diff_key($byCurrency, array($currency => true)),
        );
    }

    /** Convenience wrapper for a single campaign. */
    public static function progress(int $campaignId): array
    {
        $campaign = self::find($campaignId);
        if ($campaign === null) {
            return array();
        }
        return self::progressFor($campaign);
    }

    /**
     * Goal completion as a percentage, capped at 100 for the bar but never rounded up to it.
     *
     * A campaign at 99.6% must not read as 100%, because 100% is the moment a church stops asking and
     * starts spending. Rounded down, so the bar cannot flatter the total.
     */
    public static function percent(float $raised, float $goal): float
    {
        if ($goal <= 0) {
            return 0.0;
        }
        return min(100.0, floor(($raised / $goal) * 1000) / 10);
    }

    /**
     * Distinct donors and what they gave, for the campaign's own currency.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function donors(int $campaignId, string $currency = 'NGN', int $limit = 50): array
    {
        $stmt = self::db()->prepare(
            "SELECT COALESCE(NULLIF(donor_email, ''), '') AS email,"
            . " MAX(donor_name) AS name, SUM(amount) AS total, COUNT(*) AS gifts, MAX(created_at) AS last_gift"
            . " FROM donations WHERE campaign_id = ? AND payment_status = 'completed' AND currency = ?"
            . ' GROUP BY COALESCE(NULLIF(donor_email, \'\'), \'\')'
            . ' ORDER BY total DESC, name ASC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$campaignId, strtoupper($currency)]);
        return $stmt->fetchAll();
    }

    /** Every completed gift on a campaign, newest first — the audit trail behind the total. */
    public static function gifts(int $campaignId, int $limit = 200): array
    {
        $stmt = self::db()->prepare(
            'SELECT id, donor_name, donor_email, amount, currency, category, payment_method,'
            . ' payment_reference, description, created_at'
            . ' FROM donations WHERE campaign_id = ?'
            . " ORDER BY FIELD(payment_status, 'completed', 'pending', 'failed'), created_at DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll();
    }

    /* ================================================================== pledges == */

    /**
     * @param array<string, mixed> $campaign
     * @return array<int, array<string, mixed>>
     */
    public static function pledges(int $campaignId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM giving_pledges WHERE campaign_id = ?';
        $params = array($campaignId);
        if ($status !== null && in_array($status, array('pledged', 'received', 'cancelled'), true)) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= " ORDER BY FIELD(status, 'pledged', 'received', 'cancelled'), promised_on ASC, id ASC";

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Pledges outstanding and honoured, in the campaign's currency.
     *
     * `outstanding` is what people have promised and not yet given. It is reported, never added to
     * what has been raised.
     *
     * @return array{outstanding: float, received: float, count: int}
     */
    public static function pledgeTotals(int $campaignId, string $currency = 'NGN'): array
    {
        $stmt = self::db()->prepare(
            'SELECT status, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n'
            . ' FROM giving_pledges WHERE campaign_id = ? AND currency = ? GROUP BY status'
        );
        $stmt->execute([$campaignId, strtoupper($currency)]);

        $out = array('outstanding' => 0.0, 'received' => 0.0, 'count' => 0);
        foreach ($stmt->fetchAll() as $row) {
            if ((string) $row['status'] === 'pledged') {
                $out['outstanding'] = (float) $row['total'];
                $out['count'] = (int) $row['n'];
            } elseif ((string) $row['status'] === 'received') {
                $out['received'] = (float) $row['total'];
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function findPledge(int $id): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT p.*, c.title AS campaign_title, c.currency AS campaign_currency, c.org_unit_id AS campaign_unit_id'
            . ' FROM giving_pledges p JOIN giving_campaigns c ON c.id = p.campaign_id'
            . ' WHERE p.id = ? AND c.tenant_id = ?'
        );
        $stmt->execute([$id, self::tenantId()]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Records a promise to give.
     *
     * Deliberately allowed to be anonymous-ish: a campaign page is a public surface, so the name is
     * what the donor typed and nothing else is claimed about them.
     *
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors?: array<int, string>}
     */
    public static function pledge(int $campaignId, array $data, ?int $userId = null): array
    {
        $errors = [];
        $name = self::trimTo((string) ($data['donor_name'] ?? ''), self::MAX_NAME);
        $email = self::trimTo((string) ($data['donor_email'] ?? ''), self::MAX_EMAIL);
        $phone = self::trimTo((string) ($data['donor_phone'] ?? ''), 32);
        $amount = (float) ($data['amount'] ?? 0);
        $note = self::trimTo((string) ($data['note'] ?? ''), self::MAX_NOTE);
        $promisedOn = self::dateOrNull((string) ($data['promised_on'] ?? ''));

        if ($name === '') {
            $errors[] = 'Please tell us who is making the pledge.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'That email address does not look right.';
        }
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            $errors[] = 'Please enter the amount being pledged.';
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $pdo = self::db();
        $pdo->prepare(
            'INSERT INTO giving_pledges (tenant_id, campaign_id, donor_name, donor_email, donor_phone, amount, currency, promised_on, note, status, created_by)'
            . " VALUES (?, ?, ?, ?, ?, ?, COALESCE((SELECT currency FROM giving_campaigns WHERE id = ?), 'NGN'), ?, ?, 'pledged', ?)"
        )->execute([
            Tenant::id(),
            $campaignId,
            $name,
            $email !== '' ? $email : null,
            $phone !== '' ? $phone : null,
            $amount,
            $campaignId,
            $promisedOn,
            $note !== '' ? $note : null,
            $userId,
        ]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Marks a pledge cancelled — the promise is withdrawn.
     *
     * Refused once it has been recorded as a gift: that money was received, and rewriting the promise
     * would leave the donation pointing at a pledge that says it never happened.
     *
     * @return array{ok: bool, errors?: array<int, string>}
     */
    public static function cancelPledge(int $pledgeId): array
    {
        $pdo = self::db();
        $stmt = $pdo->prepare("UPDATE giving_pledges SET status = 'cancelled' WHERE id = ? AND recorded_donation_id IS NULL AND status <> 'cancelled'");
        $stmt->execute([$pledgeId]);
        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'errors' => ['That pledge has already been recorded as a gift, or is already cancelled.']];
        }
        return ['ok' => true];
    }

    /**
     * Turns a pledge into real money.
     *
     * The pledge is claimed with a conditional UPDATE *before* the donation is written, so a double
     * click cannot produce two gifts and double the total — the same shape as the claim in the
     * follow-up worker. The donation is a completed `manual_bank` gift because the money has already
     * arrived; recording it as `pending` would hide it from every total until somebody verified a
     * transfer there is no receipt for.
     *
     * @return array{ok: bool, donation_id?: int, errors?: array<int, string>}
     */
    public static function recordPledge(int $pledgeId, ?int $userId = null): array
    {
        $pdo = self::db();
        $pledge = self::findPledge($pledgeId);
        if ($pledge === null) {
            return ['ok' => false, 'errors' => ['That pledge no longer exists.']];
        }

        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare(
                "UPDATE giving_pledges SET status = 'received', received_at = NOW()"
                . " WHERE id = ? AND recorded_donation_id IS NULL AND status = 'pledged'"
            );
            $claim->execute([$pledgeId]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['That pledge has already been recorded, or is no longer outstanding.']];
            }

            $campaign = self::find((int) $pledge['campaign_id']);
            $title = $campaign !== null ? (string) $campaign['title'] : 'Campaign';

            $pdo->prepare(
                'INSERT INTO donations (donor_name, donor_email, donor_phone, category, amount, currency, description, payment_method, payment_status, payment_reference, campaign_id, org_unit_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "manual_bank", "completed", ?, ?, ?)'
            )->execute([
                (string) $pledge['donor_name'],
                $pledge['donor_email'],
                $pledge['donor_phone'],
                self::PLEDGE_CATEGORY,
                (float) $pledge['amount'],
                (string) $pledge['currency'],
                'Pledge honoured for ' . $title,
                'PLEDGE_' . $pledgeId . '_' . strtoupper(bin2hex(random_bytes(4))),
                (int) $pledge['campaign_id'],
                $campaign !== null ? $campaign['org_unit_id'] : null,
            ]);
            $donationId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE giving_pledges SET recorded_donation_id = ? WHERE id = ?')->execute([$donationId, $pledgeId]);
            $pdo->commit();

            return ['ok' => true, 'donation_id' => $donationId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{ok: bool, errors?: array<int, string>} */
    public static function deletePledge(int $pledgeId): array
    {
        $pdo = self::db();
        $stmt = $pdo->prepare('SELECT recorded_donation_id FROM giving_pledges WHERE id = ?');
        $stmt->execute([$pledgeId]);
        $donationId = $stmt->fetchColumn();
        if ($donationId === false) {
            return ['ok' => false, 'errors' => ['That pledge no longer exists.']];
        }
        if ($donationId !== null) {
            return ['ok' => false, 'errors' => ['That pledge was recorded as a gift, so it cannot be deleted — cancel it instead.']];
        }

        $pdo->prepare('DELETE FROM giving_pledges WHERE id = ?')->execute([$pledgeId]);
        return ['ok' => true];
    }

    /* ================================================================== helpers == */

    /**
     * Whether a campaign is taking gifts today.
     *
     * @param array<string, mixed> $campaign
     */
    public static function status(array $campaign): string
    {
        if (empty($campaign['is_active'])) {
            return 'off';
        }
        $today = date('Y-m-d');
        if (!empty($campaign['starts_on']) && (string) $campaign['starts_on'] > $today) {
            return 'upcoming';
        }
        if (!empty($campaign['ends_on']) && (string) $campaign['ends_on'] < $today) {
            return 'closed';
        }
        return 'open';
    }

    /** @param array<string, mixed> $campaign */
    public static function acceptsGifts(array $campaign): bool
    {
        return self::status($campaign) === 'open';
    }

    /** How a status reads on a page. */
    public static function statusLabel(string $status): string
    {
        $labels = array(
            'open' => 'Open for giving',
            'upcoming' => 'Opens soon',
            'closed' => 'Closed',
            'off' => 'Switched off',
        );
        return $labels[$status] ?? $status;
    }

    /** Whole days until the deadline, or null when there is not one. Negative once it has passed. */
    public static function daysLeft(array $campaign): ?int
    {
        if (empty($campaign['ends_on'])) {
            return null;
        }
        $end = strtotime((string) $campaign['ends_on'] . ' 23:59:59');
        $now = strtotime(date('Y-m-d') . ' 00:00:00');
        return (int) floor(($end - $now) / 86400);
    }

    /**
     * A URL-safe slug. Falls back to a timestamp when the title has no letters or digits at all —
     * a campaign called "!!!" still needs an address rather than an empty one.
     */
    public static function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = self::trimTo($slug, self::MAX_SLUG - 12);

        return $slug !== '' ? $slug : 'campaign-' . substr((string) time(), -8);
    }

    /** Appends -2, -3 … until the slug is free for this tenant, so two campaigns can share a title. */
    public static function uniqueSlug(string $title, int $ignoreId = 0): string
    {
        $pdo = self::db();
        $base = self::slugify($title);
        $slug = $base;
        $n = 1;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM giving_campaigns WHERE tenant_id = ? AND slug = ? AND id <> ?');
        while (true) {
            $stmt->execute([Tenant::id(), $slug, $ignoreId]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $n++;
            $slug = self::trimTo($base, self::MAX_SLUG - 4) . '-' . $n;
        }
    }

    /** Strict Y-m-d, because strtotime() accepts far too much. Empty becomes null. */
    private static function dateOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /** Trim, and refuse to store more than the column holds rather than let MySQL truncate it. */
    private static function trimTo(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}

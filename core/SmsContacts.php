<?php
declare(strict_types=1);

/**
 * The SMS address book: contacts, groups, segments, imports and the sync from the
 * church data that already exists elsewhere in the system.
 *
 * Two rules shape this class:
 *
 *  - **A number is stored one way.** Everything goes through `Sms::normaliseMsisdn()`
 *    before it is saved, so `0803…`, `+234803…` and `234803…` are one contact rather
 *    than three, and the same person cannot be texted twice because they were typed
 *    differently on two days.
 *  - **Nothing is silently dropped.** Every import and sync reports what it added, what
 *    it updated, and what it could not use and why — a church that imports 300 rows and
 *    sees 260 contacts needs to know which 40 were skipped and that it was a bad length,
 *    not guess.
 *
 * Unit scope is applied on top of tenancy: a scoped admin sees only their own subtree.
 */
final class SmsContacts
{
    /** Where a contact came from. Kept in sync with the column enum. */
    public const SOURCES = ['manual', 'newcomer', 'subscriber', 'team', 'testimony', 'registration', 'rsvp', 'form', 'app', 'import', 'member', 'group'];

    /**
     * Rule types a dynamic segment may use.
     *
     * Deliberately a closed list rather than a query builder: the audience decides who
     * gets billed, so a segment must be something this class can explain in one sentence
     * and the screens can show before the send button is pressed.
     */
    public const RULE_TYPES = ['all', 'source', 'tag', 'unit', 'created_within_days', 'never_in_group'];

    public const RULE_LABELS = [
        'all' => 'Everyone in the address book',
        'source' => 'People from one source',
        'tag' => 'People carrying a tag',
        'unit' => 'Everyone in a church or unit',
        'created_within_days' => 'People added recently',
        'never_in_group' => 'People not already in another group',
    ];

    private static function db(): PDO
    {
        return Database::getInstance()->getConnection();
    }

    private static function tenantId(): ?int
    {
        return class_exists('Tenant') ? Tenant::id() : null;
    }

    /* ------------------------------------------------------------- criteria */

    /**
     * Builds the WHERE clause every read goes through.
     *
     * @param array<string, mixed> $criteria
     * @param array<int, mixed>    $params  appended to in place
     */
    private static function buildWhere(array $criteria, array &$params): string
    {
        $clauses = [];

        $tenantId = self::tenantId();
        if ($tenantId === null) {
            $clauses[] = 'tenant_id IS NULL';
        } else {
            $clauses[] = 'tenant_id = ?';
            $params[] = $tenantId;
        }

        // The admin's own permitted units. An empty list means "no unit restriction",
        // which is what a super admin gets.
        $scope = $criteria['scope_unit_ids'] ?? [];
        if (is_array($scope) && $scope !== []) {
            $ids = array_values(array_filter(array_map('intval', $scope), static fn(int $id): bool => $id > 0));
            if ($ids !== []) {
                $clauses[] = '(org_unit_id IS NULL OR org_unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        }

        if (!empty($criteria['search'])) {
            $term = '%' . trim((string) $criteria['search']) . '%';
            $clauses[] = '(name LIKE ? OR msisdn LIKE ? OR email LIKE ? OR tags LIKE ?)';
            array_push($params, $term, $term, $term, $term);
        }

        if (!empty($criteria['source']) && in_array((string) $criteria['source'], self::SOURCES, true)) {
            $clauses[] = 'source = ?';
            $params[] = (string) $criteria['source'];
        }

        if (!empty($criteria['tag'])) {
            $clauses[] = 'tags LIKE ?';
            $params[] = '%' . trim((string) $criteria['tag']) . '%';
        }

        if (!empty($criteria['unit_id'])) {
            $unitId = (int) $criteria['unit_id'];
            $ids = !empty($criteria['include_subtree']) ? Unit::subtreeIds($unitId) : [$unitId];
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
            if ($ids !== []) {
                $clauses[] = 'org_unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        }

        // Opt-out is three-state: null means "either", and the default for a send is
        // "not opted out" — hence the explicit handling rather than a truthy check.
        if (array_key_exists('opted_out', $criteria) && $criteria['opted_out'] !== null) {
            $clauses[] = 'is_opted_out = ?';
            $params[] = !empty($criteria['opted_out']) ? 1 : 0;
        }

        if (!empty($criteria['created_within_days'])) {
            $clauses[] = 'created_at >= (NOW() - INTERVAL ? DAY)';
            $params[] = max(1, (int) $criteria['created_within_days']);
        }

        if (!empty($criteria['exclude_group_id'])) {
            $clauses[] = 'id NOT IN (SELECT contact_id FROM sms_group_members WHERE group_id = ?)';
            $params[] = (int) $criteria['exclude_group_id'];
        }

        if (!empty($criteria['ids']) && is_array($criteria['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $criteria['ids']), static fn(int $id): bool => $id > 0));
            if ($ids === []) {
                return implode(' AND ', $clauses) . ' AND 1 = 0';
            }
            $clauses[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }

        return implode(' AND ', $clauses);
    }

    /**
     * @param  array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public static function filter(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $params = [];
        $where = self::buildWhere($criteria, $params);
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        try {
            $stmt = self::db()->prepare(
                "SELECT * FROM sms_contacts WHERE {$where} ORDER BY name IS NULL, name ASC, id ASC LIMIT {$limit} OFFSET {$offset}"
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('SmsContacts filter failed: ' . $e->getMessage());
            return [];
        }
    }

    /** @param array<string, mixed> $criteria */
    public static function count(array $criteria = []): int
    {
        $params = [];
        $where = self::buildWhere($criteria, $params);
        try {
            $stmt = self::db()->prepare("SELECT COUNT(*) FROM sms_contacts WHERE {$where}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = self::db()->prepare('SELECT * FROM sms_contacts WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Every distinct tag in use, for the filter dropdowns. */
    public static function allTags(): array
    {
        try {
            $tenantId = self::tenantId();
            $sql = 'SELECT tags FROM sms_contacts WHERE tags IS NOT NULL AND tags != \'\''
                . ($tenantId !== null ? ' AND tenant_id = ?' : ' AND tenant_id IS NULL');
            $stmt = self::db()->prepare($sql);
            $stmt->execute($tenantId !== null ? [$tenantId] : []);

            $tags = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $blob) {
                foreach (explode(',', (string) $blob) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') {
                        $tags[$tag] = true;
                    }
                }
            }
            $out = array_keys($tags);
            sort($out);
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Contact counts per source, for the address book's summary strip. */
    public static function countsBySource(array $scopeUnitIds = []): array
    {
        $params = [];
        $where = self::buildWhere(['scope_unit_ids' => $scopeUnitIds], $params);
        try {
            $stmt = self::db()->prepare("SELECT source, COUNT(*) AS n FROM sms_contacts WHERE {$where} GROUP BY source ORDER BY n DESC");
            $stmt->execute($params);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['source']] = (int) $row['n'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /* ---------------------------------------------------------------- writes */

    /**
     * Creates or updates one contact.
     *
     * Updating by number rather than by row id is deliberate: this is what the sync and
     * the CSV import use, and it is what stops the same person being added twice because
     * their name was spelled differently.
     *
     * @param  array<string, mixed> $extra  name, email, tags, notes, org_unit_id, source
     * @return array{ok:bool,id?:int,action?:string,error?:string}
     */
    public static function save(int $id, string $rawNumber, array $extra = []): array
    {
        $country = (string) ($extra['country_code'] ?? Sms::defaultCountry());
        $msisdn = Sms::normaliseMsisdn($rawNumber, $country);
        if ($msisdn === null) {
            return ['ok' => false, 'error' => 'That number is not valid: ' . Sms::whyInvalid($rawNumber, $country)];
        }

        $split = Sms::splitCountry($msisdn);
        $source = in_array((string) ($extra['source'] ?? 'manual'), self::SOURCES, true) ? (string) ($extra['source'] ?? 'manual') : 'manual';

        $fields = [
            'tenant_id' => self::tenantId(),
            'msisdn' => $msisdn,
            'country_code' => $split['country'] !== '' ? $split['country'] : $country,
            'name' => self::nullIfBlank($extra['name'] ?? null),
            'email' => self::nullIfBlank($extra['email'] ?? null),
            'tags' => self::cleanTags((string) ($extra['tags'] ?? '')),
            'notes' => self::nullIfBlank($extra['notes'] ?? null),
            'source' => $source,
            'org_unit_id' => !empty($extra['org_unit_id']) ? (int) $extra['org_unit_id'] : null,
        ];

        try {
            $pdo = self::db();

            if ($id > 0) {
                $set = implode(', ', array_map(static fn(string $c): string => '`' . $c . '` = ?', array_keys($fields)));
                $stmt = $pdo->prepare("UPDATE sms_contacts SET {$set} WHERE id = ?");
                $stmt->execute(array_merge(array_values($fields), [$id]));
                return ['ok' => true, 'id' => $id, 'action' => 'updated'];
            }

            // Same number, same church: update rather than duplicate.
            $existing = $pdo->prepare('SELECT id FROM sms_contacts WHERE msisdn = ? AND tenant_id <=> ? LIMIT 1');
            $existing->execute([$msisdn, self::tenantId()]);
            $found = $existing->fetchColumn();
            if ($found) {
                $set = implode(', ', array_map(static fn(string $c): string => '`' . $c . '` = ?', array_keys($fields)));
                $stmt = $pdo->prepare("UPDATE sms_contacts SET {$set} WHERE id = ?");
                $stmt->execute(array_merge(array_values($fields), [(int) $found]));
                return ['ok' => true, 'id' => (int) $found, 'action' => 'updated'];
            }

            $columns = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', array_keys($fields)));
            $marks = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO sms_contacts ({$columns}) VALUES ({$marks})")->execute(array_values($fields));
            return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'action' => 'created'];
        } catch (Throwable $e) {
            error_log('SmsContacts save failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'That contact could not be saved.'];
        }
    }

    /** @param array<int, mixed> $ids */
    public static function setOptOut(array $ids, bool $optedOut): int
    {
        $ids = self::cleanIds($ids);
        if ($ids === []) {
            return 0;
        }
        try {
            $stmt = self::db()->prepare(
                'UPDATE sms_contacts SET is_opted_out = ?, opt_out_at = ' . ($optedOut ? 'NOW()' : 'NULL')
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $stmt->execute(array_merge([$optedOut ? 1 : 0], $ids));
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('SmsContacts setOptOut failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Adds tags without losing the ones already there. */
    public static function addTags(array $ids, string $tags): int
    {
        $ids = self::cleanIds($ids);
        $new = self::cleanTags($tags);
        if ($ids === [] || $new === '') {
            return 0;
        }

        $changed = 0;
        try {
            $select = self::db()->prepare('SELECT id, tags FROM sms_contacts WHERE id = ?');
            $update = self::db()->prepare('UPDATE sms_contacts SET tags = ? WHERE id = ?');
            foreach ($ids as $id) {
                $select->execute([$id]);
                $row = $select->fetch();
                if (!$row) {
                    continue;
                }
                $merged = self::cleanTags((string) $row['tags'] . ',' . $new);
                if ($merged !== (string) $row['tags']) {
                    $update->execute([$merged, $id]);
                    $changed++;
                }
            }
        } catch (Throwable $e) {
            error_log('SmsContacts addTags failed: ' . $e->getMessage());
        }
        return $changed;
    }

    /** @param array<int, mixed> $ids */
    public static function deleteMany(array $ids): int
    {
        $ids = self::cleanIds($ids);
        if ($ids === []) {
            return 0;
        }
        try {
            $stmt = self::db()->prepare('DELETE FROM sms_contacts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* ------------------------------------------------------------------ csv */

    /**
     * Reads a CSV into rows.
     *
     * Handles the three things that actually break an import in the field: a UTF-8 BOM
     * from Excel (which otherwise corrupts the first header name), semicolon or tab
     * delimiters from a European locale, and blank trailing lines.
     *
     * @return array{header:array<int,string>,rows:array<int,array<int,string>>,delimiter:string}
     */
    public static function parseCsv(string $content): array
    {
        // Strip a BOM, or the first header reads as "\uFEFFname".
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = str_replace("\r\n", "\n", str_replace("\r", "\n", $content));

        $delimiter = ',';
        $firstLine = strtok($content, "\n");
        if (is_string($firstLine)) {
            $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);
            if (($counts[$delimiter] ?? 0) === 0) {
                $delimiter = ',';
            }
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['header' => [], 'rows' => [], 'delimiter' => $delimiter];
        }
        fwrite($handle, $content);
        rewind($handle);

        $header = [];
        $rows = [];
        $first = true;
        while (($fields = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($fields === [null]) {
                continue;
            }
            $fields = array_map(static fn($v): string => trim((string) $v), $fields);
            if ($first) {
                $header = $fields;
                $first = false;
                continue;
            }
            // Skip a line with nothing in it at all.
            if (implode('', $fields) === '') {
                continue;
            }
            $rows[] = $fields;
        }
        fclose($handle);

        return ['header' => $header, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    /**
     * Imports parsed rows.
     *
     * `$mapping` maps a column index to a field name (number, name, email, tags, notes).
     * In dry-run mode nothing is written and the same report is produced, which is what
     * lets the screen show the 40 skipped rows *before* anything is committed.
     *
     * @param  array<int, array<int, string>> $rows
     * @param  array<string, int>             $mapping  field => column index
     * @return array{created:int,updated:int,invalid:int,duplicates:int,skipped:array<int,array{row:int,value:string,reason:string}>}
     */
    public static function importRows(array $rows, array $mapping, string $defaultCountry, bool $dryRun = true): array
    {
        $report = ['created' => 0, 'updated' => 0, 'invalid' => 0, 'duplicates' => 0, 'skipped' => []];
        $numberColumn = $mapping['number'] ?? null;
        if ($numberColumn === null) {
            return $report;
        }

        $seen = [];
        $rowNumber = 1; // header is line 1
        foreach ($rows as $row) {
            $rowNumber++;
            $raw = (string) ($row[$numberColumn] ?? '');
            if (trim($raw) === '') {
                continue;
            }

            $msisdn = Sms::normaliseMsisdn($raw, $defaultCountry);
            if ($msisdn === null) {
                $report['invalid']++;
                if (count($report['skipped']) < 50) {
                    $report['skipped'][] = ['row' => $rowNumber, 'value' => $raw, 'reason' => Sms::whyInvalid($raw, $defaultCountry)];
                }
                continue;
            }

            if (isset($seen[$msisdn])) {
                $report['duplicates']++;
                if (count($report['skipped']) < 50) {
                    $report['skipped'][] = ['row' => $rowNumber, 'value' => $raw, 'reason' => 'Already appeared earlier in this file.'];
                }
                continue;
            }
            $seen[$msisdn] = true;

            if ($dryRun) {
                // Report what *would* happen without touching the database.
                $exists = self::findByNumber($msisdn) !== null;
                $exists ? $report['updated']++ : $report['created']++;
                continue;
            }

            $result = self::save(0, $msisdn, [
                'name' => $row[$mapping['name'] ?? -1] ?? null,
                'email' => $row[$mapping['email'] ?? -1] ?? null,
                'tags' => $row[$mapping['tags'] ?? -1] ?? '',
                'notes' => $row[$mapping['notes'] ?? -1] ?? null,
                'source' => 'import',
            ]);
            if (!$result['ok']) {
                $report['invalid']++;
                if (count($report['skipped']) < 50) {
                    $report['skipped'][] = ['row' => $rowNumber, 'value' => $raw, 'reason' => (string) ($result['error'] ?? 'Could not be saved.')];
                }
                continue;
            }
            $result['action'] === 'created' ? $report['created']++ : $report['updated']++;
        }

        return $report;
    }

    public static function findByNumber(string $msisdn): ?array
    {
        try {
            $stmt = self::db()->prepare('SELECT * FROM sms_contacts WHERE msisdn = ? AND tenant_id <=> ? LIMIT 1');
            $stmt->execute([$msisdn, self::tenantId()]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /* ----------------------------------------------------------------- sync */

    /**
     * Pulls phone numbers out of the data the church has already collected.
     *
     * Consent matters here. A newcomer who gave a WhatsApp number, or a testimony giver,
     * did not automatically agree to marketing texts — so `subscriber` rows are only
     * taken when `sms_consent` was ticked, and the pastoral sources are tagged so a
     * church can see at a glance where a number came from and leave it out if it should
     * not be texted.
     *
     * @return array<string, array{found:int,added:int,updated:int,skipped:int}>
     */
    public static function syncFromChurchData(array $scopeUnitIds = []): array
    {
        $report = [];
        $pdo = self::db();

        /** @var array<int, array{label:string,sql:string,tag:string}> $sources */
        $sources = [
            [
                // Consent gate. A number on a staff profile is a contact detail; permission
                // to text it is a separate thing, and 2026_19 keeps them apart so that no
                // source can opt someone into bulk messaging just by holding their number.
                'label' => 'Church team who agreed to be texted',
                'sql' => 'SELECT id, name, phone, email, org_unit_id FROM users WHERE phone IS NOT NULL AND phone != \'\' AND sms_consent = 1',
                'tag' => 'team',
                'source' => 'team',
            ],
            [
                'label' => 'Newcomers',
                'sql' => 'SELECT id, name, whatsapp_phone AS phone, NULL AS email, org_unit_id FROM newcomers WHERE whatsapp_phone IS NOT NULL AND whatsapp_phone != \'\'',
                'tag' => 'newcomer',
                'source' => 'newcomer',
            ],
            [
                'label' => 'Newsletter subscribers who consented',
                'sql' => 'SELECT id, NULL AS name, phone, email, org_unit_id FROM newsletter_subscribers WHERE phone IS NOT NULL AND phone != \'\' AND sms_consent = 1 AND is_active = 1',
                'tag' => 'subscriber',
                'source' => 'subscriber',
            ],
            [
                'label' => 'Testimony givers',
                'sql' => 'SELECT id, name, phone, email, unit_id AS org_unit_id FROM testimonies WHERE phone IS NOT NULL AND phone != \'\'',
                'tag' => 'testimony',
                'source' => 'testimony',
            ],
            [
                'label' => 'Event RSVPs',
                'sql' => 'SELECT r.id, r.name, r.phone, r.email, e.org_unit_id FROM event_rsvps r LEFT JOIN events e ON e.id = r.event_id WHERE r.phone IS NOT NULL AND r.phone != \'\'',
                'tag' => 'event',
                'source' => 'rsvp',
            ],
            [
                'label' => 'Church registrations',
                'sql' => 'SELECT id, name, phone, email, NULL AS org_unit_id FROM pending_registrations WHERE phone IS NOT NULL AND phone != \'\'',
                'tag' => 'registration',
                'source' => 'registration',
            ],
            [
                'label' => 'App users who shared a number',
                'sql' => 'SELECT id, NULL AS name, phone, NULL AS email, org_unit_id FROM device_tokens WHERE phone IS NOT NULL AND phone != \'\'',
                'tag' => 'app',
                'source' => 'app',
            ],
        ];

        foreach ($sources as $definition) {
            $key = $definition['source'];
            $report[$key] = ['label' => $definition['label'], 'found' => 0, 'added' => 0, 'updated' => 0, 'skipped' => 0];

            try {
                $rows = $pdo->query($definition['sql'])->fetchAll();
            } catch (Throwable $e) {
                // A source table that is not present yet is not a failure.
                continue;
            }

            foreach ($rows as $row) {
                $report[$key]['found']++;

                // Honour the admin's unit scope: a scoped admin syncs their own subtree.
                $unitId = !empty($row['org_unit_id']) ? (int) $row['org_unit_id'] : null;
                if ($scopeUnitIds !== [] && $unitId !== null && !in_array($unitId, array_map('intval', $scopeUnitIds), true)) {
                    $report[$key]['skipped']++;
                    continue;
                }

                $result = self::save(0, (string) $row['phone'], [
                    'name' => $row['name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'tags' => $definition['tag'],
                    // Unit only when the source actually knows it, so a sync never
                    // invents an assignment the data does not support.
                    'org_unit_id' => $unitId,
                    'source' => $definition['source'],
                ]);

                if (!$result['ok']) {
                    $report[$key]['skipped']++;
                    continue;
                }
                $result['action'] === 'created' ? $report[$key]['added']++ : $report[$key]['updated']++;
            }
        }

        // Form submissions carry phone answers inside a JSON blob, so they need a
        // different pass: find the phone-type fields, then read those keys out.
        $report['form'] = ['label' => 'Form submissions', 'found' => 0, 'added' => 0, 'updated' => 0, 'skipped' => 0];
        try {
            $phoneFields = $pdo->query("SELECT id, form_id FROM form_fields WHERE field_type = 'phone'")->fetchAll();
            if ($phoneFields) {
                $byForm = [];
                foreach ($phoneFields as $field) {
                    $byForm[(int) $field['form_id']][] = (int) $field['id'];
                }
                foreach ($pdo->query('SELECT id, form_id, data FROM form_submissions ORDER BY id ASC')->fetchAll() as $submission) {
                    $data = json_decode((string) $submission['data'], true);
                    if (!is_array($data)) {
                        continue;
                    }
                    foreach ($byForm[(int) $submission['form_id']] ?? [] as $fieldId) {
                        $value = $data[$fieldId] ?? $data[(string) $fieldId] ?? null;
                        if (!is_scalar($value) || trim((string) $value) === '') {
                            continue;
                        }
                        $report['form']['found']++;
                        $result = self::save(0, (string) $value, ['tags' => 'form', 'source' => 'form']);
                        if (!$result['ok']) {
                            $report['form']['skipped']++;
                            continue;
                        }
                        $result['action'] === 'created' ? $report['form']['added']++ : $report['form']['updated']++;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('SmsContacts form sync failed: ' . $e->getMessage());
        }

        return $report;
    }

    /* --------------------------------------------------------------- groups */

    public static function findGroup(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = self::db()->prepare('SELECT * FROM sms_groups WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Every group the admin can see, with its member count.
     *
     * Groups have none of the contact columns, so this builds its own tenant and unit
     * clause rather than going through buildWhere().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function groups(array $scopeUnitIds = []): array
    {
        $params = [];
        $clauses = [];

        $tenantId = self::tenantId();
        if ($tenantId === null) {
            $clauses[] = 'g.tenant_id IS NULL';
        } else {
            $clauses[] = 'g.tenant_id = ?';
            $params[] = $tenantId;
        }

        if ($scopeUnitIds !== []) {
            $ids = array_values(array_filter(array_map('intval', $scopeUnitIds), static fn(int $id): bool => $id > 0));
            if ($ids !== []) {
                // A group with no unit is shared, so it stays visible either way.
                $clauses[] = '(g.org_unit_id IS NULL OR g.org_unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        }

        try {
            $sql = 'SELECT g.*, (SELECT COUNT(*) FROM sms_group_members m WHERE m.group_id = g.id) AS member_count'
                . ' FROM sms_groups g WHERE ' . implode(' AND ', $clauses)
                . ' ORDER BY g.kind ASC, g.name ASC';
            $stmt = self::db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Creates or updates a group.
     *
     * A slug is derived from the name and made unique within the church, because two
     * groups called "Choir" are a data-entry accident, not a plan.
     *
     * @param  array<string, mixed> $extra  name, kind, rule (array|string|null), org_unit_id
     * @return array{ok:bool,id?:int,action?:string,error?:string}
     */
    public static function saveGroup(int $id, string $name, array $extra = []): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return ['ok' => false, 'error' => 'Give the group a name.'];
        }
        $name = mb_substr($name, 0, 150);

        $kind = (string) ($extra['kind'] ?? 'static');
        if (!in_array($kind, ['static', 'dynamic'], true)) {
            $kind = 'static';
        }

        $rule = null;
        if ($kind === 'dynamic') {
            $raw = $extra['rule'] ?? [];
            $ruleArray = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            if (!in_array((string) ($ruleArray['type'] ?? 'all'), self::RULE_TYPES, true)) {
                return ['ok' => false, 'error' => 'That is not a rule this system understands.'];
            }
            $rule = json_encode($ruleArray);
        }

        $tenantId = self::tenantId();
        $unitId = !empty($extra['org_unit_id']) ? (int) $extra['org_unit_id'] : null;

        try {
            $pdo = self::db();

            $base = self::slugify($name);
            $slug = $base;
            $suffix = 2;
            while (true) {
                $check = $pdo->prepare('SELECT id FROM sms_groups WHERE slug = ? AND tenant_id <=> ? AND id <> ? LIMIT 1');
                $check->execute([$slug, $tenantId, $id]);
                if (!$check->fetchColumn()) {
                    break;
                }
                $slug = $base . '-' . $suffix;
                $suffix++;
                if ($suffix > 200) {
                    $slug = $base . '-' . bin2hex(random_bytes(3));
                    break;
                }
            }

            if ($id > 0) {
                $pdo->prepare('UPDATE sms_groups SET name = ?, slug = ?, kind = ?, rule = ?, org_unit_id = ? WHERE id = ?')
                    ->execute([$name, $slug, $kind, $rule, $unitId, $id]);
                // Switching to dynamic makes the stored members meaningless, so they go.
                if ($kind === 'dynamic') {
                    $pdo->prepare('DELETE FROM sms_group_members WHERE group_id = ?')->execute([$id]);
                }
                return ['ok' => true, 'id' => $id, 'action' => 'updated'];
            }

            $pdo->prepare('INSERT INTO sms_groups (tenant_id, name, slug, kind, rule, org_unit_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tenantId, $name, $slug, $kind, $rule, $unitId, !empty($extra['created_by']) ? (int) $extra['created_by'] : null]);

            return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'action' => 'created'];
        } catch (Throwable $e) {
            error_log('SmsContacts saveGroup failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'That group could not be saved.'];
        }
    }

    public static function deleteGroup(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            // Members cascade. A campaign that used the group keeps its own recipient
            // rows, because those are a record of what was sent, not a pointer.
            self::db()->prepare('DELETE FROM sms_groups WHERE id = ?')->execute([$id]);
            return true;
        } catch (Throwable $e) {
            error_log('SmsContacts deleteGroup failed: ' . $e->getMessage());
            return false;
        }
    }

    /** A URL-safe slug for a group name. */
    private static function slugify(string $value): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '');
        $slug = trim($slug, '-');
        return $slug === '' ? 'group-' . bin2hex(random_bytes(3)) : mb_substr($slug, 0, 160);
    }

    /** The contact ids in a group, following its rule when it is dynamic. */
    public static function groupAudience(array $group, array $scopeUnitIds = []): array
    {
        if ((string) $group['kind'] === 'dynamic') {
            $rule = is_string($group['rule']) ? (json_decode($group['rule'], true) ?: []) : (array) ($group['rule'] ?? []);
            return self::resolveRule($rule, $scopeUnitIds, (int) $group['id']);
        }

        try {
            $stmt = self::db()->prepare('SELECT contact_id FROM sms_group_members WHERE group_id = ?');
            $stmt->execute([(int) $group['id']]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @param array<int, mixed> $contactIds */
    public static function setGroupMembers(int $groupId, array $contactIds): int
    {
        $contactIds = self::cleanIds($contactIds);
        try {
            $pdo = self::db();
            $pdo->beginTransaction();
            // Replace rather than merge: the screen shows the current membership, so what
            // the admin saves is what the group should contain.
            $pdo->prepare('DELETE FROM sms_group_members WHERE group_id = ?')->execute([$groupId]);
            $insert = $pdo->prepare('INSERT IGNORE INTO sms_group_members (group_id, contact_id) VALUES (?, ?)');
            $added = 0;
            foreach ($contactIds as $contactId) {
                $insert->execute([$groupId, $contactId]);
                $added += $insert->rowCount();
            }
            $pdo->commit();
            return $added;
        } catch (Throwable $e) {
            if (self::db()->inTransaction()) {
                self::db()->rollBack();
            }
            error_log('SmsContacts setGroupMembers failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Turns a rule into a list of contact ids.
     *
     * @param  array<string, mixed> $rule
     * @return array<int, int>
     */
    public static function resolveRule(array $rule, array $scopeUnitIds = [], ?int $excludeGroupId = null): array
    {
        $type = (string) ($rule['type'] ?? 'all');
        if (!in_array($type, self::RULE_TYPES, true)) {
            return [];
        }

        $criteria = ['scope_unit_ids' => $scopeUnitIds, 'opted_out' => false];

        switch ($type) {
            case 'source':
                $criteria['source'] = (string) ($rule['source'] ?? '');
                break;
            case 'tag':
                $criteria['tag'] = (string) ($rule['tag'] ?? '');
                break;
            case 'unit':
                $criteria['unit_id'] = (int) ($rule['unit_id'] ?? 0);
                $criteria['include_subtree'] = !empty($rule['include_subtree']);
                break;
            case 'created_within_days':
                $criteria['created_within_days'] = max(1, (int) ($rule['days'] ?? 30));
                break;
            case 'never_in_group':
                if ($excludeGroupId !== null) {
                    $criteria['exclude_group_id'] = $excludeGroupId;
                }
                break;
            case 'all':
            default:
                break;
        }

        $rows = self::filter($criteria, 5000);
        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /** One sentence describing a rule, shown before anything is sent. */
    public static function ruleSummary(array $rule): string
    {
        $type = (string) ($rule['type'] ?? 'all');
        switch ($type) {
            case 'source':
                $source = (string) ($rule['source'] ?? '');
                return 'Everyone whose source is ' . self::sourceLabel($source) . '.';
            case 'tag':
                return 'Everyone tagged “' . (string) ($rule['tag'] ?? '') . '”.';
            case 'unit':
                $unit = (int) ($rule['unit_id'] ?? 0);
                return 'Everyone in ' . (Unit::label($unit) ?: 'unit ' . $unit)
                    . (!empty($rule['include_subtree']) ? ' and every unit beneath it' : ' only (not its sub-units)') . '.';
            case 'created_within_days':
                return 'Everyone added in the last ' . max(1, (int) ($rule['days'] ?? 30)) . ' day(s).';
            case 'never_in_group':
                return 'Everyone not already a member of this group.';
            case 'all':
            default:
                return 'Everyone in the address book.';
        }
    }

    public static function sourceLabel(string $source): string
    {
        $labels = [
            'manual' => 'Added by hand',
            'import' => 'CSV import',
            'group' => 'WhatsApp group import',
            'newcomer' => 'Newcomer form',
            'subscriber' => 'Newsletter (consented)',
            'team' => 'Church team',
            'testimony' => 'Testimony',
            'registration' => 'Church registration',
            'rsvp' => 'Event RSVP',
            'form' => 'Form submission',
            'app' => 'App user',
            'member' => 'Member record',
        ];
        return $labels[$source] ?? ucfirst($source);
    }

    /* ------------------------------------------------------------ audiences */

    /**
     * Everyone in a unit, optionally including everything beneath it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function audienceFromUnit(int $unitId, bool $includeSubtree = true, array $scopeUnitIds = []): array
    {
        if ($unitId <= 0) {
            return [];
        }
        return self::filter([
            'unit_id' => $unitId,
            'include_subtree' => $includeSubtree,
            'opted_out' => false,
            'scope_unit_ids' => $scopeUnitIds,
        ], 5000);
    }

    /**
     * Turns a pasted list into contact rows, whether the numbers are already known or not.
     *
     * Unknown numbers become temporary contacts in the campaign queue only — they are not
     * added to the address book, because a one-off paste is not consent to be kept.
     *
     * @return array{known:array<int, array<string, mixed>>,unknown:array<int, string>,invalid:array<int, array{value:string,reason:string}>}
     */
    public static function audienceFromNumbers(string $pasted, ?string $country = null): array
    {
        $out = ['known' => [], 'unknown' => [], 'invalid' => []];
        $seen = [];

        foreach (preg_split('/[\s,;]+/', $pasted) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $msisdn = Sms::normaliseMsisdn($chunk, $country);
            if ($msisdn === null) {
                $out['invalid'][] = ['value' => $chunk, 'reason' => Sms::whyInvalid($chunk, $country)];
                continue;
            }
            if (isset($seen[$msisdn])) {
                continue;
            }
            $seen[$msisdn] = true;

            $existing = self::findByNumber($msisdn);
            if ($existing !== null) {
                if ((int) $existing['is_opted_out'] === 1) {
                    $out['invalid'][] = ['value' => $chunk, 'reason' => 'This number has opted out.'];
                    continue;
                }
                $out['known'][] = $existing;
            } else {
                $out['unknown'][] = $msisdn;
            }
        }

        return $out;
    }

    /* ----------------------------------------------------------- utilities */

    /** @param array<int, mixed> $ids @return array<int, int> */
    private static function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /** Normalises a comma-separated tag list, de-duplicated and trimmed. */
    public static function cleanTags(string $tags): ?string
    {
        $out = [];
        foreach (explode(',', $tags) as $tag) {
            $tag = trim(preg_replace('/\s+/', ' ', $tag) ?? '');
            if ($tag !== '') {
                $out[mb_strtolower($tag)] = mb_substr($tag, 0, 40);
            }
        }
        return $out === [] ? null : implode(', ', array_values($out));
    }

    private static function nullIfBlank($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, 190);
    }
}

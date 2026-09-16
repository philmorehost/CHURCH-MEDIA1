<?php
declare(strict_types=1);

/**
 * The books of the Bible, as the bundled offline KJV has them.
 *
 * This exists because a reading plan stores a passage as a book name plus chapter numbers, and the
 * app has to find that book in its own copy of the KJV to open it offline. If the two disagree by
 * even a spelling — the file says "Psalms", a person types "Psalm" — the reader lands on nothing.
 * So the names here are the file's names, and `cli/reference-sweep.php` asserts that they still
 * match `RCCGLP63/assets/bible/kjv.json`.
 *
 * Chapter counts come along for the ride, because they are the only way to refuse "Genesis 51" when
 * someone is writing a plan, rather than letting a member discover it.
 */
final class BibleBooks
{
    /**
     * name:chapters, in canonical order.
     *
     * One string rather than sixty-six array entries because this is data, not code, and this is the
     * shape that is easiest to compare against the app's own copy by eye and by script.
     */
    private const DATA = 'Genesis:50|Exodus:40|Leviticus:27|Numbers:36|Deuteronomy:34|Joshua:24|Judges:21|Ruth:4|1 Samuel:31|2 Samuel:24|1 Kings:22|2 Kings:25|1 Chronicles:29|2 Chronicles:36|Ezra:10|Nehemiah:13|Esther:10|Job:42|Psalms:150|Proverbs:31|Ecclesiastes:12|Song of Solomon:8|Isaiah:66|Jeremiah:52|Lamentations:5|Ezekiel:48|Daniel:12|Hosea:14|Joel:3|Amos:9|Obadiah:1|Jonah:4|Micah:7|Nahum:3|Habakkuk:3|Zephaniah:3|Haggai:2|Zechariah:14|Malachi:4|Matthew:28|Mark:16|Luke:24|John:21|Acts:28|Romans:16|1 Corinthians:16|2 Corinthians:13|Galatians:6|Ephesians:6|Philippians:4|Colossians:4|1 Thessalonians:5|2 Thessalonians:3|1 Timothy:6|2 Timothy:4|Titus:3|Philemon:1|Hebrews:13|James:5|1 Peter:5|2 Peter:3|1 John:5|2 John:1|3 John:1|Jude:1|Revelation:22';

    /**
     * Forms people actually write that are not a prefix of the canonical name, plus the two
     * ambiguous ones. "Phil" is Philippines to nobody, but it is Philippians to everybody who is
     * not thinking about Philemon, so it is pinned rather than left to the prefix rule.
     */
    private const ALIASES = array(
        'psalm' => 'Psalms',
        'psalms of david' => 'Psalms',
        'song of songs' => 'Song of Solomon',
        'song' => 'Song of Solomon',
        'canticles' => 'Song of Solomon',
        'revelations' => 'Revelation',
        'apocalypse' => 'Revelation',
        'phil' => 'Philippians',
        'philem' => 'Philemon',
        'judg' => 'Judges',
        'josh' => 'Joshua',
        'eccles' => 'Ecclesiastes',
        'eccl' => 'Ecclesiastes',
        'thess' => '1 Thessalonians',
        'cor' => '1 Corinthians',
        'chron' => '1 Chronicles',
    );

    /** @var array<string,int>|null */
    private static $cache = null;

    /** @return array<string,int> Canonical name => number of chapters. */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $books = array();
        foreach (explode('|', self::DATA) as $pair) {
            $parts = explode(':', $pair);
            if (count($parts) === 2) {
                $books[$parts[0]] = (int) $parts[1];
            }
        }
        return self::$cache = $books;
    }

    /** @return array<int,string> The canonical names, in Bible order. */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::all());
    }

    public static function chapters(string $name): int
    {
        $books = self::all();
        return $books[$name] ?? 0;
    }

    /** 1-based Bible order, for sorting things the way a reader expects. */
    public static function position(string $name): int
    {
        $index = array_search($name, self::names(), true);
        return $index === false ? 0 : $index + 1;
    }

    /**
     * Turns what someone typed into a canonical book name, or null.
     *
     * Exact name first, then the alias list, then a unique prefix so "Gen" and "Deut" work.
     * **Ambiguity is refused rather than guessed**: "Jud" could be Judges or Jude, and quietly
     * picking one would send a reader to the wrong book, so the caller gets null and the person
     * writing the plan gets told. `BibleBooks::suggestions()` says what they probably meant.
     */
    public static function normalise(string $input): ?string
    {
        $key = (string) preg_replace('/\s+/', ' ', trim($input));
        $key = rtrim(strtolower($key), '.');
        if ($key === '') {
            return null;
        }

        foreach (self::names() as $name) {
            if (strtolower($name) === $key) {
                return $name;
            }
        }

        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        $matches = self::startingWith($key);
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<int,string> Canonical names beginning with $prefix, lower-cased comparison. */
    public static function startingWith(string $prefix): array
    {
        $prefix = strtolower(trim($prefix));
        if ($prefix === '') {
            return array();
        }

        $matches = array();
        foreach (self::names() as $name) {
            if (strpos(strtolower($name), $prefix) === 0) {
                $matches[] = $name;
            }
        }
        return $matches;
    }

    /**
     * What an unreadable book name probably meant.
     *
     * Returns the candidates when the input was a prefix of more than one book, and the handful of
     * names it slightly resembles otherwise, so a typo comes back with something to act on rather
     * than a bare refusal.
     *
     * @return array<int,string>
     */
    public static function suggestions(string $input): array
    {
        $key = strtolower((string) preg_replace('/\s+/', ' ', trim($input)));
        if ($key === '') {
            return array();
        }

        $prefixMatches = self::startingWith($key);
        if (count($prefixMatches) > 1) {
            return $prefixMatches;
        }

        // Similar by a shared beginning — enough to catch "Genessis" without pretending to be a
        // spell checker.
        $stem = substr($key, 0, 4);
        $similar = array();
        foreach (self::names() as $name) {
            if ($stem !== '' && strpos(strtolower($name), $stem) === 0) {
                $similar[] = $name;
            }
        }
        if ($similar !== array()) {
            return array_slice($similar, 0, 6);
        }

        // Nothing shares a beginning, so fall back to edit distance. This is the only thing that
        // catches a transposition like "Genisis", which no prefix rule ever will.
        $scored = array();
        foreach (self::names() as $name) {
            $distance = levenshtein($key, strtolower($name));
            if ($distance <= 3) {
                $scored[$name] = $distance;
            }
        }
        asort($scored);
        return array_slice(array_keys($scored), 0, 6);
    }
}

<?php

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Security\Permission;

/**
 * Logs one 404 request
 */
class SearchLog
    extends DataObject
{
    private static $singular_name = 'Search Log';
    
    private static $default_sort = 'LastEdited DESC';

    /**
     * Name of the GET parameter whose value is logged, or a list of names: the first one that
     * holds a non-empty string is logged (one query per request at most).
     *
     * @config
     * @var string|string[]
     */
    private static $search_query_param = 'Search';

    /**
     * Whether queries that look like noise (see below) are dropped before any database query.
     *
     * @config
     * @var bool
     */
    private static $ignore_noise = true;

    /**
     * Queries shorter than this many characters (after trimming) are noise. Not applied to a
     * query in a script written without spaces (Chinese, Japanese, Korean), where one character
     * can be a whole word.
     *
     * @config
     * @var int
     */
    private static $min_query_length = 2;

    /**
     * Queries in which fewer than this share of the characters are letters, digits or spaces are
     * noise: meant for strings that are almost all symbols ('%%%', '+-+-'), so the default is low
     * enough to keep 'c++', '100%', 'f-16' and '€ 50'. 0 switches the check off.
     *
     * @config
     * @var float
     */
    private static $min_alnum_ratio = 0.3;

    /**
     * Patterns that mark a query as noise: name => full PCRE pattern, matched against the
     * trimmed, lower-cased query. Set a name to null or '' in YAML to switch it off.
     *
     * @config
     * @var array<string, string|null>
     */
    private static $noise_patterns = array(
        # SQL injection probes. Keywords only count in an injection shape, so a search for
        # 'select' or 'union' alone, or 'select a course from the list', is kept.
        'sql' => '~(\bunion\s+(all\s+)?select\b|\b(sleep|benchmark)\s*\(|\bwaitfor\s+delay\b|\b(or|and)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+|--\s*$|/\*|;\s*(drop|select|insert|update|delete)\b)~i',
        # A single quote or backtick followed by a digit, a symbol or the end of the query, eg
        # 65'123, 1'='1 or amsterdam' (an injection probe's first step). Followed by a letter or a
        # space it is ordinary text: foto's, 70's, 's-hertogenbosch, kids' books, rock 'n' roll.
        'quote' => '~[\'`](?=[^\pL\s]|$)~u',
        # Markup, template and escape injection.
        'markup' => '~[<>{}\[\]\\\\]|\$\{~',
        # File names: someone, or something, pasting an asset or script path. Not .js or .css:
        # 'node.js' and 'vue.js' are things people search for.
        'filename' => '~\.(php\d?|asp|aspx|jsp|png|jpe?g|gif|webp|svg|env)$~i',
        # Only digits and separators: years, dates, phone-number fragments ('2026', '12-34-56').
        # A number with a letter or a unit ('1234 ab', 'sku 12345', '100%', '€ 50') is kept.
        'numbers_only' => '~^[\d\s.,:/-]+$~',
    );

    private static $db = array(
        'Query' => 'Varchar(255)',
        'Count' => 'Int',
    );

    private static $has_many = array(
        'Months' => SearchLogMonth::class,
    );

    private static $cascade_deletes = array(
        'Months',
    );

    /**
     * Whether logHit() also counts each query per calendar month (SearchLogMonth), which the
     * search terms report uses for "hits this year". Costs one indexed UPDATE per logged query
     * (plus an INSERT on a query's first hit in a month). Noise is never counted.
     *
     * @config
     * @var bool
     */
    private static $count_per_month = true;

    /** Recency bands of the search terms report, by the year a term was last searched for. */
    const BAND_THIS_YEAR = 'this_year';
    const BAND_LAST_YEAR = 'last_year';
    const BAND_2_3_YEARS = '2_3_years';
    const BAND_OLDER = 'older';

    private static $indexes = array(
        # logHit() looks every query up by Query; without an index each logged search scanned the
        # whole table. Not UNIQUE: tables from before 3.0 can hold rows that differ only in
        # non-ASCII case (stored before queries were lower-cased with mb_strtolower()), and a
        # UNIQUE index would make dev/build fail on them.
        'Query' => true,
    );

    private static $summary_fields = array(
        'Query' => 'Search query',
        'Count' => 'Hits',
    );

    private static $searchable_fields = array(
        'Query',
    );

    /**
     * Log one search query: count it on the row for this query, or create that row.
     *
     * @param string $query
     * @return SearchLog|null The row written, or null when the query was noise
     */
    public static function logHit($query)
    {
        # Normalise BEFORE the lookup, so the lookup matches what is stored: queries are stored
        # lower-cased, and must fit Varchar(255) - Silverstripe 6 throws on an over-long value
        # (a 500 on the search page), Silverstripe 5 truncated it silently so the lookup with the
        # full value never matched the stored row and every hit created a new row.
        # mb_strtolower rather than strtolower, which lower-cases ASCII only.
        $query = mb_strtolower(trim((string) $query));
        # Noise is judged on the full query, before truncation, and before any database query.
        if (static::isNoise($query)) {
            return null;
        }
        $size = (int) static::singleton()->dbObject('Query')->getSize();
        if ($size > 0) {
            $query = mb_substr($query, 0, $size);
        }

        // create or update log
        $existing = SearchLog::get()->filter(
                array(
                    'Query' => $query
                    ))->first();
        if ($existing) {
//            $existing->Count = $existing->Count+1;
//            $existing->write();
            # Count raised in SQL, like FourOhFourLog::countHit(): setting Count = the value read
            # + 1 lost hits under concurrency, as requests that read the same value all wrote the
            # same result back. The forced ORM write stamps LastEdited (the last search).
            $existing->write(false, false, true);
            $table = static::getSchema()->tableName(static::class);
            SQLUpdate::create(
                "\"$table\"",
                array('"Count"' => array('"Count" + ?' => array(1))),
                array('"ID"' => (int) $existing->ID)
            )->execute();
            # No re-read on this hot path: the stored Count is right through the increment; the
            # in-memory one is the value read plus this hit. Set after the write, never written.
            $existing->Count = (int) $existing->Count + 1;
            static::countMonth($existing);
            return $existing;
        } else {
            $log = SearchLog::create();
//            $log->Query = strtolower($query);
            # already normalised above
            $log->Query = $query;
            $log->Count = 1;
            $log->write();
            static::countMonth($log);
            return $log;
        }
    }

    /**
     * Count a hit on this row in the current month, when count_per_month is on.
     *
     * @param SearchLog $row
     * @return void
     */
    protected static function countMonth($row)
    {
        if (static::config()->get('count_per_month') && $row && $row->ID) {
            HitMonthCounter::increment(SearchLogMonth::class, (int) $row->ID);
        }
    }

    /**
     * The derived figures of the search terms report for one term, from its lifetime Count, its
     * first (Created) and last (LastEdited) hit. Pure: no database, the clock is passed in.
     *
     * - PerActiveYear: Count / the years between first and last hit, at least 1 year, so a term
     *   searched for 70 times in one week is not "3,600 a year".
     * - Band: the year of the last hit, as this year / last year / 2-3 years ago / older.
     * - IsNew: first searched for last year or this year, and still searched for this year.
     * - IsFaded: searched for before, but not this year.
     * - IsNoise: SearchLog::looksLikeNoise(), whatever ignore_noise says, so rows logged before
     *   the noise filter existed can be told apart.
     *
     * Calendar years, not rolling 12-month windows: early in January most terms are "faded".
     *
     * @param string $query
     * @param int $count
     * @param string|null $created DB datetime of the first hit
     * @param string|null $lastEdited DB datetime of the last hit
     * @param int $now Timestamp
     * @return array{PerActiveYear: float, Band: string, IsNew: bool, IsFaded: bool, IsNoise: bool}
     */
    public static function termStats($query, $count, $created, $lastEdited, $now)
    {
        $first = $created ? strtotime((string) $created) : false;
        $last = $lastEdited ? strtotime((string) $lastEdited) : false;
        if ($last === false) {
            $last = $first !== false ? $first : (int) $now;
        }
        if ($first === false || $first > $last) {
            $first = $last;
        }

        $years = max(1.0, ($last - $first) / (365.25 * 86400));
        $thisYear = (int) date('Y', (int) $now);
        $firstYear = (int) date('Y', $first);
        $lastYear = (int) date('Y', $last);
        $age = $thisYear - $lastYear;

        if ($age <= 0) {
            $band = self::BAND_THIS_YEAR;
        } elseif ($age === 1) {
            $band = self::BAND_LAST_YEAR;
        } elseif ($age <= 3) {
            $band = self::BAND_2_3_YEARS;
        } else {
            $band = self::BAND_OLDER;
        }

        return array(
            'PerActiveYear' => round(((int) $count) / $years, 1),
            'Band' => $band,
            'IsNew' => $firstYear >= $thisYear - 1 && $age <= 0,
            'IsFaded' => $age > 0,
            'IsNoise' => static::looksLikeNoise((string) $query),
        );
    }

    /**
     * Titles of the recency bands, for the report.
     *
     * @return array<string, string>
     */
    public static function bandTitles()
    {
        return array(
            self::BAND_THIS_YEAR => _t('FourOhFourLogger.BandThisYear', 'This year'),
            self::BAND_LAST_YEAR => _t('FourOhFourLogger.BandLastYear', 'Last year'),
            self::BAND_2_3_YEARS => _t('FourOhFourLogger.Band23Years', '2-3 years ago'),
            self::BAND_OLDER => _t('FourOhFourLogger.BandOlder', 'Older'),
        );
    }

    /**
     * Whether a query is noise and not logged: too short, mostly not letters, or matching one of
     * $noise_patterns. Always false when $ignore_noise is off.
     *
     * @param string $query
     * @return bool
     */
    public static function isNoise($query)
    {
        if (!static::config()->get('ignore_noise')) {
            return false;
        }

        return static::looksLikeNoise($query);
    }

    /**
     * The noise rules of isNoise() without the ignore_noise switch: too short, mostly not
     * letters, or matching one of $noise_patterns. Used by the search terms report's noise flag
     * and the purge task, which judge rows that may have been logged with the filter off.
     *
     * @param string $query
     * @return bool
     */
    public static function looksLikeNoise($query)
    {
        $config = static::config();
        $query = mb_strtolower(trim((string) $query));
        $length = mb_strlen($query);
        # One Han, kana or Hangul character can be a whole word, so the length rule skips them.
        $spaceless = preg_match('~[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]~u', $query);
        if (!$spaceless && $length < (int) $config->get('min_query_length')) {
            return true;
        }
        if ($length === 0) {
            return true;
        }

        $minRatio = (float) $config->get('min_alnum_ratio');
        if ($minRatio > 0) {
            # Letters and digits in any script, and spaces.
            $wordChars = preg_match_all('~[\pL\pN\s]~u', $query);
            if ($wordChars !== false && $wordChars / $length < $minRatio) {
                return true;
            }
        }

        foreach ((array) $config->get('noise_patterns') as $pattern) {
            if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }

    public function canView($member = null)
    {
        # Report data (visited URLs, referrers, search terms) is for CMS users with report
        # access only; it used to be open to everyone. logHit() writes without a member and
        # does not go through this check (DataObject::write() does not call canCreate()).
//        return true;
        return Permission::check('CMS_ACCESS_ReportAdmin', 'any', $member);
    }

    public function canCreate($member = null, $context = [])
    {
        # Report data (visited URLs, referrers, search terms) is for CMS users with report
        # access only; it used to be open to everyone. logHit() writes without a member and
        # does not go through this check (DataObject::write() does not call canCreate()).
//        return true;
        return Permission::check('CMS_ACCESS_ReportAdmin', 'any', $member);
    }

    public function canEdit($member = null)
    {
        # Report data (visited URLs, referrers, search terms) is for CMS users with report
        # access only; it used to be open to everyone. logHit() writes without a member and
        # does not go through this check (DataObject::write() does not call canCreate()).
//        return true;
        return Permission::check('CMS_ACCESS_ReportAdmin', 'any', $member);
    }

    public function canDelete($member = null)
    {
        # Report data (visited URLs, referrers, search terms) is for CMS users with report
        # access only; it used to be open to everyone. logHit() writes without a member and
        # does not go through this check (DataObject::write() does not call canCreate()).
//        return true;
        return Permission::check('CMS_ACCESS_ReportAdmin', 'any', $member);
    }
}

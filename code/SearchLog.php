<?php

use SilverStripe\ORM\DataObject;
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
            $existing->Count = $existing->Count+1;
            $existing->write();
            return $existing;
        } else {
            $log = SearchLog::create();
//            $log->Query = strtolower($query);
            # already normalised above
            $log->Query = $query;
            $log->Count = 1;
            $log->write();
            return $log;
        }
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
        $config = static::config();
        if (!$config->get('ignore_noise')) {
            return false;
        }

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

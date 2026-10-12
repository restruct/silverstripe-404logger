<?php

use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Security\Permission;

/**
 * Logs one 404 request
 */
class FourOhFourLog
    extends DataObject
{
    private static $singular_name = '404 Log';

    /** Built-in categories, see $category_patterns. Anything no pattern matches is a 'page'. */
    const CATEGORY_PROBE = 'probe';
    const CATEGORY_SCANNER = 'scanner';
    const CATEGORY_ASSET = 'asset';
    const CATEGORY_PAGE = 'page';

    private static $db = array(
        'Referrer' => 'Varchar(2048)',
        'Link' => 'Varchar(2048)',
        'Count' => 'Int',
        # Lookup key for logHit(): sha1 of the lower-cased link and referrer. Link and Referrer are
        # Varchar(2048), too long to index in full (utf8mb4: 8 KB a column, InnoDB keys max 3 KB),
        # so every 404 used to scan the whole table. NULL on rows from before 3.1 until
        # FourOhFourLogMergeTask (or a hit on that row) fills it in.
        'LinkHash' => 'Varchar(40)',
        # One of the $category_patterns keys or 'page', set on write so later reports can filter
        # on it without re-running the patterns over the whole table.
        'Category' => 'Varchar(32)',
        # Set by the report's bulk actions ('ignored', 'redirected'): the link was dealt with, so
        # the report hides it by default. Cleared again by the next hit on the row, because a
        # handled link that still 404s was not handled after all.
        'HandledAs' => 'Varchar(32)',
        'HandledAt' => 'Datetime',
    );

    private static $has_many = array(
        'Months' => FourOhFourLogMonth::class,
    );

    private static $cascade_deletes = array(
        'Months',
    );

    private static $indexes = array(
        # UNIQUE so concurrent first hits cannot create two rows for one link + referrer. Safe to
        # add on a table that already holds duplicates: the column is new, so every existing row
        # is NULL, and a UNIQUE index accepts any number of NULLs (MySQL and MariaDB alike).
        'LinkHash' => array('type' => 'unique', 'columns' => array('LinkHash')),
    );

    /**
     * Rule-based categories for a logged link, checked in this order; the first category with a
     * matching pattern wins, and a link no pattern matches is a 'page'. Each category maps a
     * name to a full PCRE pattern (delimiters and flags included), so a project can switch one
     * off by setting its name to null or '' in YAML, or add its own, without copying the rest.
     * A project can also add categories of its own (a new top-level key).
     *
     * Patterns are matched against the link WITHOUT its leading slash, URL-decoded, query string
     * included (eg 'wp-login.php?action=register').
     *
     * Order matters: probes first (so 'manifest.json' is a probe, not a credential guess), then
     * scanners (so 'assets/shell.php' is a scanner, not a missing asset), then assets.
     * The defaults were tuned on a 55,000-row production log; they are deliberately narrower
     * than "anything that looks odd", because an ignored hit is never stored.
     *
     * @config
     * @var array<string, array<string, string|null>>
     */
    private static $category_patterns = array(
        # Browsers, apps and crawlers asking for well-known files on their own initiative.
        self::CATEGORY_PROBE => array(
            # passkey-endpoints, traffic-advice, change-password, security.txt, assetlinks.json, ...
            # but not a script under it ('.well-known/x.php' is a scanner).
            'well_known' => '~^\.well-known/(?![^?]*\.php)~i',
            'icons' => '~(^|/)(apple-touch-icon[^/]*|android-chrome-[^/]*|mstile-[^/]*|favicon[^/]*)\.(png|ico|svg)(\?|$)~i',
            'site_files' => '~^(robots\.txt|humans\.txt|security\.txt|ads\.txt|app-ads\.txt|sellers\.json|crossdomain\.xml|clientaccesspolicy\.xml|browserconfig\.xml|site\.webmanifest|manifest\.(json|webmanifest)|apple-app-site-association|sitemap[^/]*\.xml(\.gz)?)(\?|$)~i',
            'autodiscover' => '~(^|/)autodiscover/~i',
        ),
        # Vulnerability scanners and other bad actors. A Silverstripe site serves no .php URL,
        # no dotfile and no backup archive, so a request for one is rarely a broken link.
        # Every pattern is anchored on a path segment or file name, never on a bare word: an
        # ignored hit is never stored, so a pattern that also matches real pages ('jenkins-smith',
        # 'wordpress-vs-silverstripe', 'downloads/brochure.zip') silently loses real broken links.
        # A scanner hit WITH a referrer is still logged by default, see ignore_only_without_referrer.
        self::CATEGORY_SCANNER => array(
            'script_ext' => '~\.(php\d?|phtml|phar|asp|aspx|ashx|jsp|jspx|cgi|py|rb)(\W|_|$)~i',
            # The dotfile name must end there ('.env', '.env.old', '.env_bak', '.git/HEAD'), so a
            # slug such as 'careers/.env-engineer' is not one.
            'dotfiles' => '~(^|/)\.(env|git|svn|hg|bzr|aws|ssh|docker|vscode|idea|ds_store|htaccess|htpasswd|npmrc|bash_history|circleci|travis)([/.?_\d]|$)~i',
            # WordPress's own paths and files only, not articles about WordPress.
            'wordpress' => '~(^|/)(wp-(admin|content|includes|login|json|config|cron)(?=[/.?]|$)|xmlrpc\.php|wlwmanifest\.xml)|^(wp|wordpress)/~i',
            # Joomla and web-shell paths.
            'cms_probes' => '~(^|/)(administrator/components|components?/com_|alfa_?data|alfacgiapi)|tmpl=component~i',
            # Secret and dump files: never a legitimate link, wherever they are (outside the asset
            # folders).
            'secrets' => '~^(?!(assets|resources|_resources)/)[^?]*\.(sql|bak|swp|pem|tfstate|kdbx|env)(\.[a-z0-9]{1,4})?(\?|$)~i',
            # Archives, logs, config and old copies: only at the site root, where a scanner guesses
            # 'backup.zip' or 'error.log'. In a folder ('downloads/brochure.zip', 'reports/2024.log')
            # they are a real missing file and fall through to 'asset' or 'page'.
            'root_backups' => '~^[^/?]*\.(old|orig|save|tar|tgz|gz|bz2|rar|7z|zip|jar|war|dat|dump|key|crt|p12|pfx|sqlite3?|db|mdb|log|ini|conf|cfg|ya?ml)(\.[a-z0-9]{1,4})?(\?|$)~i',
            # *.json at the site root: credential and config guesses ('config.json'). In a folder
            # ('api/data.json') it is left alone. manifest.json and friends are probes, checked first.
            'root_json' => '~^[^/?]*\.json(\?|$)~i',
            'credentials' => '~(^|/)(id_(rsa|dsa|ecdsa|ed25519)(\.pub)?|(credentials?|secrets?)\.(json|ya?ml|txt|xml|env|ini|php)|service-?account[^/]*\.json|firebase[^/]*\.json|(composer|package(-lock)?|yarn|auth|sftp-config|docker-compose)\.(json|lock|ya?ml)|web\.config[^/]*|phpinfo[^/]*|server-(status|info))(/|\?|$)~i',
            # Admin tools whose name is unambiguous anywhere in the path.
            'admin_tools' => '~(^|/)(phpmyadmin[\w.-]*|pma|myadmin|mysqladmin|cgi-bin|_profiler|_ignition|manager/html|boaform|hnap1|gponform)(/|\?|$)~i',
            # Names that are also ordinary words ('team/jenkins-smith', 'about/administrator'):
            # only as the first path segment, exactly.
            'admin_tools_root' => '~^(administrator|actuator|telescope|solr|jenkins|hudson|owa|ecp)(/|\?|$)~i',
            'vendor_dirs' => '~(^|/)(vendor/(composer|bin|phpunit|autoload)|node_modules/)~i',
            # Path traversal (also with a full-width slash or '?' padding), system files, NUL
            # bytes, script and SQL injection, Vite dev-server file access.
            # Plain '<' and '>' are NOT scanner signs: template code leaking into an href
            # ('a < b || ...') is a broken link on the site itself, and stays a 'page'.
            # 'union select' needs real whitespace, so a slug like 'union-select-committee' does
            # not match; a bare 'select ... from' is not used, it occurs in ordinary search links.
            'injection' => '~(\.\.([/\\\\?]|\x{FF0F})|(^|/)\.{3,}/|(^|/)(etc/(passwd|shadow|hosts)|var/log/|root/\.|proc/self|proc/version)|win\.ini|\x00|"|<\s*(script|img|svg|iframe)\b|javascript:|\bon(error|load)\s*=|\$\{|\bunion(\s|\+)+(all(\s|\+)+)?select\b|\b(sleep|benchmark)\s*\(|@fs/|base64_decode|eval\()~iu',
            # Since 3.3, from a production log (issue #14). Same rule as above: a path segment or
            # an exact path, never a bare word anywhere.
            # Remote-code-execution probes for AI, agent and serverless tools (Langflow, Flowise,
            # Spring Cloud Function, Feast, Inngest, ...). Names that could be a page's URL segment
            # only as the exact first segment; 'functionRouter' case-sensitively ((?-i:...)), since a
            # Silverstripe URL segment is lower-case. A bare 'read-document' is left out: an
            # ordinary page name.
            'rce_probes' => '~^(langflow|flowise)/|(^|/)api/v1/(validate/code(/|\?|$)|build_public_tmp/|node-load-method/)|^(api/)?(inngest|exec-py)(/|\?|$)|^feast/read-document(/|\?|$)|^api/(fs/exec|designer/v1/file-content|templates/preview)(/|\?|$)|^(api/)?(?-i:functionRouter)(/|\?|$)~i',
            # Dev-server and build-tool endpoints (Vite, webpack, Firebase hosting), and build
            # manifests in their usual output folders.
            'dev_tooling' => '~(^|/)(__vite[\w-]*|__webpack_hmr)(/|\?|$)|^__/firebase/|^(dist|static)/manifest\.json(\?|$)~i',
            # Deploy and config files: a Dockerfile or *.toml at the site root (fly, netlify,
            # wrangler, railway), supabase/config.toml, *credentials.json outside the asset
            # folders, keys under ssl/, Go's expvar page.
            'deploy_files' => '~^(dockerfile|[^/?]*\.toml|supabase/config\.toml|public/admin\.json|debug/vars)(\?|$)|^(?!(assets|resources|_resources)/)([^?]*/)?[\w.-]*credentials\.json(\?|$)|(^|/)ssl/[^/?]+\.(key|crt|pem)(\?|$)~i',
            # Container and system paths requested without '..' (proc/self is under 'injection').
            'system_paths' => '~(^|/)var/run/secrets/~i',
            # A scanner's own marker in the URL.
            'canaries' => '~(^|/)z9x8c7v6b5-debug-trigger~i',
            # SQL injection and form probing on search-like parameters: only this exact shape
            # (one of these first segments or none, then '?s=test' or '?s=<digits>\'' as the
            # whole query), so a real search link with s= is not caught.
            'search_probes' => '~^((cart|products|register|user|account|search|api(/v1)?)/?)?\?s=(test|\d*\')$~i',
            # PHP-CGI argument injection (CVE-2024-4577): 'php-cgi.exe' is not a script_ext.
            'php_cgi' => '~(^|/)php-cgi(\.exe)?(/|\?|$)~i',
        ),
        # A missing file rather than a missing page.
        self::CATEGORY_ASSET => array(
            'asset_dirs' => '~^(assets|resources|_resources)/~i',
            'file_ext' => '~^[^?]*\.(jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|heic|pdf|docx?|xlsx?|pptx?|odt|ods|odp|csv|txt|rtf|epub|mp3|m4a|wav|ogg|mp4|m4v|webm|mov|avi|zip|css|js|mjs|map|woff2?|ttf|eot|otf)(\?|$)~i',
        ),
    );

    /**
     * Categories whose hits are not logged at all: checked before any database query, so an
     * ignored hit costs no query and no write. Keyed by category so a project can switch one
     * back on (`probe: false`) or add its own; values are booleans.
     *
     * @config
     * @var array<string, bool>
     */
    private static $ignore_categories = array(
        self::CATEGORY_SCANNER => true,
        self::CATEGORY_PROBE => true,
    );

    /**
     * Extra patterns whose hits are not logged, whatever their category: name => full PCRE
     * pattern, matched like $category_patterns (link without leading slash, URL-decoded, query
     * string included). Checked before any database query.
     *
     * @config
     * @var array<string, string|null>
     */
    private static $ignore_patterns = array();

    /**
     * When true, an ignored 'scanner' hit is only dropped when it came WITHOUT a referrer.
     * Scanners rarely send a Referer; a real broken inbound link (an old WordPress, PHP or ASP
     * URL that another site still links to) usually does, so it stays visible. Applies to the
     * scanner category only: probes are dropped whatever the referrer.
     *
     * @config
     * @var bool
     */
    private static $ignore_only_without_referrer = true;

    /**
     * When true, a referrer that is the requested URL itself, or the bare site root, counts as NO
     * referrer for ignore_only_without_referrer. Scanners have started to send such a fake
     * Referer ('https://site/<the same path>' or 'https://site/') to look like a click; a real
     * broken link never has itself as referrer, and the bare site root is (almost) never the
     * referrer of a deep 404. Only a referrer on one of the site's own hosts counts (the request's
     * Host, Director::host(), SS_BASE_URL and site_hosts; with or without 'www.', port ignored).
     * The row still stores the referrer as sent; only the ignore decision changes.
     *
     * @config
     * @var bool
     */
    private static $self_referrer_is_no_referrer = true;

    /**
     * Extra host names of this site for self_referrer_is_no_referrer, eg other domains that serve
     * the same site ('example.org' when the site is example.com). The request's Host, Director::host()
     * and SS_BASE_URL are always included.
     *
     * @config
     * @var string[]
     */
    private static $site_hosts = array();

    /**
     * Whether logHit() also counts each hit per calendar month (FourOhFourLogMonth), which the
     * report uses for "hits in period". Costs one indexed UPDATE per logged hit (plus an INSERT
     * on a row's first hit in a month). Ignored hits are never counted.
     *
     * @config
     * @var bool
     */
    private static $count_per_month = true;

    private static $summary_fields = array(
        'Referrer' => 'Referrer',
        'Link' => 'Incoming link',
        'Count' => 'Hits',
    );

    private static $searchable_fields = array(
        'Referrer',
        'Link',
    );

    /**
     * Log one 404: count it on the row for this link + referrer, or create that row.
     * A hit in an ignored category or matching an ignore pattern is dropped before any query.
     *
     * @param string $link Request URL relative to the site root; a leading slash is stripped
     * @param string $ref Referrer, or 'unknown'
     * @param string|null $requestHost The request's Host header, one of the site's own hosts for
     *        self_referrer_is_no_referrer (FourOhFourLogger passes it)
     * @return FourOhFourLog|null The row written, or null when the hit was ignored
     */
    public static function logHit($link, $ref, $requestHost = null)
    {
        # Stored without a leading slash. Rows logged before the Silverstripe 4/5 era were stored
        # as '/path', later ones as 'path' (HTTPRequest::getURL()), so every old URL got a second
        # row and the old row's count stopped. FourOhFourLogMergeTask merges existing pairs.
        $link = static::normaliseLink((string) $link);
        if ($link === '') {
            return null;
        }

        # Categorise and filter BEFORE touching the database, so scanner noise costs no query.
        $category = static::categorise($link);
        if (static::isIgnored($link, $category, (string) $ref, $requestHost)) {
            return null;
        }

        # Fit both values to their column BEFORE the lookup. Silverstripe 6 validates Varchar
        # length on write and throws, which turned a long 404 URL into a 500. On Silverstripe 5
        # MySQL (ANSI mode) silently truncated the stored value instead, so the lookup with the
        # full value never matched the stored row again and every hit created a new row.
        # The untruncated link is kept for the legacy lookup, which has to rebuild '/' . $link.
        $fullLink = $link;
        $link = static::fitToField('Link', $link);
        $ref = static::fitToField('Referrer', (string) $ref);
        $category = static::fitToField('Category', $category);
        $hash = static::linkHash($link, $ref);

        // create or update log
//        $existing = FourOhFourLog::get()->filter(
//                array(
//                    'Referrer' => $ref,
//                    'Link' => $link
//                    ))->first();
        # One indexed lookup. Only when that misses AND rows from before 3.1 are still unhashed,
        # fall back to the old (unindexed) lookup on Link + Referrer, and give the row found its
        # hash, so it is found by the index from then on.
        $existing = static::get()->filter('LinkHash', $hash)->first();
        if (!$existing) {
            $existing = static::findUnhashed($fullLink, $link, $ref);
            if ($existing) {
                $existing->Link = static::fitToField('Link', static::normaliseLink((string) $existing->Link));
                $existing->LinkHash = $hash;
            }
        }
        if ($existing) {
//            $existing->Count = $existing->Count+1;
            # Re-set on every hit, so a change to the category config shows on the next hit.
            $existing->Category = $category;
            # A link marked handled that 404s again was not handled after all: show it again.
            if ($existing->HandledAs) {
                $existing->HandledAs = null;
                $existing->HandledAt = null;
            }
//            $existing->write();
            $existing = static::countHit($existing);
            static::countMonth($existing);
            return $existing;
        }

        $log = FourOhFourLog::create();
        $log->Referrer = $ref;
        $log->Link = $link;
        $log->LinkHash = $hash;
        $log->Category = $category;
        $log->Count = 1;
        try {
            $log->write();
        } catch (\Exception $e) {
            # Two concurrent first hits: the other request inserted the row between our lookup and
            # our insert, and the UNIQUE index rejected ours (a ValidationException on both majors,
            # whose class moved namespace in Silverstripe 6, hence the broad catch). Count the hit
            # on that row instead. Anything else is rethrown.
            $existing = static::get()->filter('LinkHash', $hash)->first();
            if (!$existing) {
                throw $e;
            }
//            $existing->Count = $existing->Count+1;
//            $existing->write();
            $existing = static::countHit($existing);
            static::countMonth($existing);
            return $existing;
        }

        static::countMonth($log);
        return $log;
    }

    /**
     * Count one hit on an existing row. The ORM write stamps LastEdited (the most recent hit,
     * forced since nothing else may have changed) with the row's other changes; the Count is
     * then raised in SQL ("Count" = "Count" + 1, like HitMonthCounter::bump()). Setting
     * Count = the value read + 1 lost hits under concurrency: requests that read the same value
     * all wrote the same result back.
     *
     * @param FourOhFourLog $row
     * @return FourOhFourLog The same row, its in-memory Count = the value read + 1
     */
    protected static function countHit($row)
    {
        $row->write(false, false, true);
        $table = static::getSchema()->tableName(static::class);
        SQLUpdate::create(
            "\"$table\"",
            array('"Count"' => array('"Count" + ?' => array(1))),
            array('"ID"' => (int) $row->ID)
        )->execute();

//        return static::get()->byID($row->ID) ?: $row;
        # No re-read: this runs on every repeat 404, and the stored Count is already right
        # through the SQL increment above. The in-memory value is the one read plus this hit,
        # which misses only hits counted concurrently; nothing in the module reads it back.
        # Set after the write, so it is never written from here.
        $row->Count = (int) $row->Count + 1;

        return $row;
    }

    /**
     * Count a hit on this row in the current month, when count_per_month is on. Called after
     * the row's own write, on every path through logHit() (including the lost insert race), so
     * the monthly counts add up to the hits logged since the upgrade.
     *
     * @param FourOhFourLog $row
     * @return void
     */
    protected static function countMonth($row)
    {
        if (static::config()->get('count_per_month') && $row && $row->ID) {
            HitMonthCounter::increment(FourOhFourLogMonth::class, (int) $row->ID);
        }
    }

    /**
     * Mark every row of these links handled (eg after the report's "ignore" or "redirect"
     * action), so the report hides them by default. Plain SQL, so LastEdited ("most recent hit")
     * is left alone. Links match case-insensitively, like the rows' hash.
     *
     * @param string[] $links
     * @param string $as 'ignored', 'redirected' or a project's own label
     * @return int Rows marked
     */
    public static function markHandled(array $links, $as)
    {
        $links = array_values(array_unique(array_filter(array_map('strval', $links), 'strlen')));
        if (!$links) {
            return 0;
        }

        $table = static::getSchema()->tableName(static::class);
        $lower = array_map('mb_strtolower', $links);
        $placeholders = implode(', ', array_fill(0, count($lower), '?'));
        SQLUpdate::create(
            "\"$table\"",
            array(
                '"HandledAs"' => static::fitToField('HandledAs', (string) $as),
                '"HandledAt"' => DBDatetime::now()->Rfc2822(),
            ),
            array("LOWER(\"Link\") IN ($placeholders)" => $lower)
        )->execute();

        return (int) DB::affected_rows();
    }

    /**
     * The form a link is stored and matched in: without leading slashes.
     *
     * @param string $link
     * @return string
     */
    public static function normaliseLink($link)
    {
        return ltrim((string) $link, '/');
    }

    /**
     * The LinkHash for a (normalised, column-fitted) link and referrer. Lower-cased, so rows stay
     * keyed case-insensitively, as they were by the old lookup on the case-insensitive Link and
     * Referrer columns (MySQL's default collations).
     *
     * @param string $link
     * @param string $ref
     * @return string
     */
    public static function linkHash($link, $ref)
    {
        # The link is hashed WITHOUT its last possible character. A pre-3.1 row stored as '/path'
        # was cut at the column size including that slash, so once normalised it is one character
        # shorter than the same long URL logged today (cut without a slash). Hashing one character
        # less than the column holds gives both the same hash; two URLs that differ only in their
        # 2048th character share a row, which is harmless.
        $size = (int) static::singleton()->dbObject('Link')->getSize();
        $link = (string) $link;
        if ($size > 1) {
            $link = mb_substr($link, 0, $size - 1);
        }

        return sha1(mb_strtolower($link) . '|' . mb_strtolower((string) $ref));
    }

    /**
     * The category of a link: the first $category_patterns category with a matching pattern, or
     * 'page'.
     *
     * @param string $link With or without leading slash
     * @return string
     */
    public static function categorise($link)
    {
        $subject = static::patternSubject($link);
        foreach ((array) static::config()->get('category_patterns') as $category => $patterns) {
            if (is_array($patterns) && static::matchesAny($subject, $patterns)) {
                return (string) $category;
            }
        }

        return self::CATEGORY_PAGE;
    }

    /**
     * Whether a hit on this link is dropped: its category is in $ignore_categories (for a
     * scanner hit with a referrer, only when $ignore_only_without_referrer is off), or it matches
     * one of $ignore_patterns (always, whatever the referrer).
     *
     * @param string $link With or without leading slash
     * @param string|null $category Pass it when already known, to skip categorising again
     * @param string|null $ref The referrer; null or 'unknown' means none, and so does the link
     *        itself or the bare site root when self_referrer_is_no_referrer is on
     * @param string|null $requestHost The request's Host header (see isSelfReferrer())
     * @return bool
     */
    public static function isIgnored($link, $category = null, $ref = null, $requestHost = null)
    {
        if ($category === null) {
            $category = static::categorise($link);
        }
        $ignored = (array) static::config()->get('ignore_categories');
        if (!empty($ignored[$category])) {
            $hasReferrer = is_string($ref) && trim($ref) !== '' && $ref !== 'unknown';
            # A fake referrer (the URL itself, or the site root) is no referrer: scanners send one
            # to get past ignore_only_without_referrer. No database work, like the rest of this check.
            if ($hasReferrer && static::config()->get('self_referrer_is_no_referrer')
                    && static::isSelfReferrer($link, $ref, $requestHost)) {
                $hasReferrer = false;
            }
            if (!($category === self::CATEGORY_SCANNER && $hasReferrer
                    && static::config()->get('ignore_only_without_referrer'))) {
                return true;
            }
        }

        if (static::matchesAny(static::patternSubject($link), (array) static::config()->get('ignore_patterns'))) {
            return true;
        }

        # Last, because it is the only check that may read the database (SiteConfig, usually
        # already loaded for the error page): the links editors ignored from the report.
        return static::matchesIgnoreList($link);
    }

    /**
     * Whether a link is on the CMS-editable ignore list (SiteConfig, see
     * FourOhFourIgnoreListExtension): an exact link, or a prefix when the entry ends in '*'.
     * Compared case-insensitively, without leading slash, query string included.
     *
     * @param string $link
     * @return bool
     */
    public static function matchesIgnoreList($link)
    {
        $entries = FourOhFourIgnoreListExtension::entries();
        if (!$entries) {
            return false;
        }

        $link = mb_strtolower(static::normaliseLink((string) $link));
        foreach ($entries as $entry) {
            if (substr($entry, -1) === '*') {
                $prefix = substr($entry, 0, -1);
                if ($prefix !== '' && strncmp($link, $prefix, strlen($prefix)) === 0) {
                    return true;
                }
            } elseif ($link === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a referrer is fake: on one of the site's own hosts (siteHosts()), and either the
     * requested link itself (path and query string, compared case-insensitively and URL-decoded,
     * without the base URL's folder and outer slashes) or the bare site root (no path, no query
     * string). The fragment is ignored. A referrer without a host, or on another host, is never
     * fake.
     *
     * @param string $link With or without leading slash, query string included
     * @param string $ref The referrer as logged (FourOhFourLogger HTML-encodes it)
     * @param string|null $requestHost The request's Host header, port allowed
     * @return bool
     */
    public static function isSelfReferrer($link, $ref, $requestHost = null)
    {
        # FourOhFourLogger stores the referrer HTML-encoded ('&amp;' in a query string).
        $parts = parse_url(html_entity_decode(trim((string) $ref), ENT_QUOTES, 'UTF-8'));
        if (!is_array($parts) || empty($parts['host'])
                || !in_array(static::normaliseHost($parts['host']), static::siteHosts($requestHost), true)) {
            return false;
        }

        # The referrer's path relative to the site root: a site in a subfolder ('/sub/') logs
        # links relative to that folder.
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $base = rtrim(Director::baseURL(), '/');
        if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
            $path = (string) substr($path, strlen($base));
        }
        $refKey = static::selfReferrerKey($path, isset($parts['query']) ? (string) $parts['query'] : '');

        # The bare site root: what scanners send when they do not bother to repeat the path.
        if ($refKey === '') {
            return true;
        }

        $link = static::normaliseLink((string) $link);
        $query = '';
        if (($pos = strpos($link, '?')) !== false) {
            $query = (string) substr($link, $pos + 1);
            $link = (string) substr($link, 0, $pos);
        }

        return $refKey === static::selfReferrerKey($link, $query);
    }

    /**
     * The site's own host names, lower-cased, without port and without a leading 'www.': the
     * request's Host, Director::host() (Director.alternate_base_url first), the SS_BASE_URL host
     * and site_hosts.
     *
     * @param string|null $requestHost
     * @return string[]
     */
    public static function siteHosts($requestHost = null)
    {
        $hosts = array_merge(
            array((string) $requestHost, (string) Director::host()),
            # parse_url() needs a scheme or '//' to see a host; SS_BASE_URL has one.
            array((string) parse_url((string) Environment::getEnv('SS_BASE_URL'), PHP_URL_HOST)),
            array_map('strval', array_values((array) static::config()->get('site_hosts')))
        );

        $own = array();
        foreach ($hosts as $host) {
            $host = static::normaliseHost($host);
            if ($host !== '') {
                $own[$host] = $host;
            }
        }

        return array_values($own);
    }

    /**
     * A host name in the form siteHosts() compares: lower-cased, no port, no leading 'www.', so
     * 'www.example.com:8080' and 'example.com' are the same site.
     *
     * @param string $host
     * @return string
     */
    protected static function normaliseHost($host)
    {
        $host = trim((string) $host);
        if ($host === '') {
            return '';
        }
        # A bare host or 'host:port' is read as the authority of a scheme-relative URL.
        $name = parse_url(strpos($host, '//') === false ? '//' . $host : $host, PHP_URL_HOST);
        $name = mb_strtolower(rtrim((string) $name, '.'));

        return (string) preg_replace('~^www\.~', '', $name);
    }

    /**
     * The form in which isSelfReferrer() compares a path and query string: URL-decoded,
     * lower-cased (rows are keyed case-insensitively too), path without outer slashes.
     *
     * @param string $path
     * @param string $query
     * @return string
     */
    protected static function selfReferrerKey($path, $query)
    {
        $key = trim(rawurldecode((string) $path), '/');
        if ((string) $query !== '') {
            # urldecode(), not rawurldecode(): the logged link's query string is rebuilt by
            # http_build_query(), which writes a space as '+'.
            $key .= '?' . urldecode((string) $query);
        }

        return mb_strtolower($key);
    }

    /**
     * What the patterns are matched against: the link without leading slash, URL-decoded, so
     * '%2e%2e/' is seen as '../' and '%40fs/' as '@fs/'.
     *
     * @param string $link
     * @return string
     */
    protected static function patternSubject($link)
    {
        return rawurldecode(static::normaliseLink($link));
    }

    /**
     * @param string $subject
     * @param array<string, string|null> $patterns name => PCRE pattern; empty values are skipped,
     *        which is how a project switches off a default pattern in YAML
     * @return bool
     */
    protected static function matchesAny($subject, array $patterns)
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a row from before 3.1 (LinkHash still NULL) for this link and referrer, stored with or
     * without the leading slash. Returns null at the cost of one indexed query once no unhashed
     * rows are left (after FourOhFourLogMergeTask has run), so the unindexed lookup on the
     * Varchar(2048) columns only runs on a site that still has old rows.
     *
     * @param string $fullLink Normalised link before truncation
     * @param string $link Normalised link fitted to the column
     * @param string $ref Referrer fitted to the column
     * @return FourOhFourLog|null
     */
    protected static function findUnhashed($fullLink, $link, $ref)
    {
        $unhashed = static::get()->filter('LinkHash', null);
        if (!$unhashed->exists()) {
            return null;
        }

        # Old rows were truncated WITH their slash, so rebuild the slashed form from the full link.
        $candidates = array_values(array_unique(array(
            $link,
            static::fitToField('Link', '/' . $fullLink),
        )));

        return $unhashed->filter(array(
            'Referrer' => $ref,
            'Link' => $candidates,
        ))->first();
    }

    /**
     * Truncate a value to the size of the given Varchar field on this class.
     *
     * @param string $fieldName
     * @param string $value
     * @return string
     */
    protected static function fitToField($fieldName, $value)
    {
        $size = (int) static::singleton()->dbObject($fieldName)->getSize();
        return $size > 0 ? mb_substr($value, 0, $size) : $value;
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

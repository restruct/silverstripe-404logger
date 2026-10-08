<?php

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;

/**
 * The CMS-editable list of ignored 404 links, stored on SiteConfig (Settings > "404 log").
 * Applied to SiteConfig in _config/extensions.yml only when silverstripe/siteconfig is installed.
 *
 * Why SiteConfig: it is the simplest durable store an editor can already reach and change, it
 * needs no admin section of its own, and the 404 path usually has it loaded already (the error
 * page template reads it), so checking the list costs no extra query in practice. YAML
 * (FourOhFourLog.ignore_patterns) stays the place for developers' regex rules.
 *
 * One entry per line, compared case-insensitively against the logged link (without leading
 * slash, query string included). An entry ending in '*' matches every link starting with it.
 * Empty lines and lines starting with '#' are skipped.
 *
 * Extension rather than DataExtension: DataExtension is deprecated in Silverstripe 5.3 and gone
 * in 6, and an Extension carries $db on both.
 */
class FourOhFourIgnoreListExtension extends Extension
{
    /** SiteConfig class, as a string: silverstripe/siteconfig is not a requirement. */
    const SITECONFIG_CLASS = 'SilverStripe\\SiteConfig\\SiteConfig';

    private static $db = array(
        'FourOhFourIgnoreList' => 'Text',
    );

    public function updateCMSFields(FieldList $fields)
    {
        $field = TextareaField::create(
            'FourOhFourIgnoreList',
            _t('FourOhFourLogger.IgnoreList', 'Ignored 404 links')
        );
        $field->setRows(12);
        $field->setDescription(_t(
            'FourOhFourLogger.IgnoreListHelp',
            'One link per line, without the domain (eg "old-page" or "downloads/*"). Hits on these '
            . 'links are no longer logged. A line ending in * ignores every link that starts with it. '
            . 'The "Ignore" action in the broken links report adds lines here.'
        ));
        $fields->addFieldToTab('Root.FourOhFourLog', $field);
        $tab = $fields->fieldByName('Root.FourOhFourLog');
        if ($tab) {
            $tab->setTitle(_t('FourOhFourLogger.IgnoreListTab', '404 log'));
        }
    }

    /**
     * The normalised entries of the current site config's list: lower-cased, without leading
     * slash; comments and empty lines dropped. Empty when silverstripe/siteconfig is missing or
     * the extension is not applied.
     *
     * @return string[]
     */
    public static function entries()
    {
        $config = static::currentSiteConfig();
        if (!$config) {
            return array();
        }

        return static::parse((string) $config->FourOhFourIgnoreList);
    }

    /**
     * @param string $text
     * @return string[]
     */
    public static function parse($text)
    {
        $entries = array();
        foreach (preg_split('~\R~u', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $line = mb_strtolower(FourOhFourLog::normaliseLink($line));
            if ($line !== '' && $line !== '*') {
                $entries[$line] = $line;
            }
        }

        return array_values($entries);
    }

    /**
     * Whether a logged link can go on the list as itself. A link ending in '*' cannot: on the
     * list a trailing '*' means "every link starting with this", so it would ignore far more
     * than the one link. There is no escape syntax; such a link can only be ignored by a
     * pattern (FourOhFourLog.ignore_patterns) or a deliberate prefix entry.
     *
     * @param string $link
     * @return bool
     */
    public static function canAddLink($link)
    {
        return substr(rtrim((string) $link), -1) !== '*';
    }

    /**
     * Add links to the list (skipping ones already on it, and links ending in '*', see
     * canAddLink()) and save the site config.
     *
     * @param string[] $links
     * @return int Number of links added; 0 also when the list is not available
     */
    public static function addLinks(array $links)
    {
        $config = static::currentSiteConfig();
        if (!$config) {
            return 0;
        }

        $existing = static::parse((string) $config->FourOhFourIgnoreList);
        $added = array();
        foreach ($links as $link) {
            $link = FourOhFourLog::normaliseLink(trim((string) $link));
            $key = mb_strtolower($link);
            if ($key === '' || !static::canAddLink($link) || in_array($key, $existing, true) || isset($added[$key])) {
                continue;
            }
            $added[$key] = $link;
        }
        if (!$added) {
            return 0;
        }

        $text = rtrim((string) $config->FourOhFourIgnoreList);
        $config->FourOhFourIgnoreList = ltrim($text . "\n" . implode("\n", $added), "\n");
        $config->write();

        return count($added);
    }

    /**
     * The current SiteConfig when it exists and carries this extension, else null.
     *
     * @return \SilverStripe\ORM\DataObject|null
     */
    protected static function currentSiteConfig()
    {
        $class = self::SITECONFIG_CLASS;
        if (!class_exists($class) || !$class::has_extension(static::class)) {
            return null;
        }

        return $class::current_site_config();
    }
}

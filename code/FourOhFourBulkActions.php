<?php

use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Security\Permission;

/**
 * Bulk actions for the broken links report: a checkbox per row, and buttons that act on the
 * links of the ticked rows (every referrer row of each link):
 *
 * - "Ignore": adds the links to the CMS ignore list (FourOhFourIgnoreListExtension, on
 *   SiteConfig), so further hits are not logged. Only shown when that list is available and
 *   the member may edit the site settings it is saved on.
 * - "Redirect": creates a redirect from each link to the URL typed next to the button, in
 *   silverstripe/redirectedurls. Only shown when that module is installed.
 *
 * Both mark the rows handled (FourOhFourLog::markHandled()), which the report hides by default.
 *
 * No JavaScript of its own: the checkboxes are ordinary inputs of the report form, and the CMS
 * GridField script posts every input of the form with a GridField action.
 */
class FourOhFourBulkActions implements GridField_HTMLProvider, GridField_ColumnProvider, GridField_ActionProvider
{
    use Injectable;

    /** RedirectedURL class, as a string: silverstripe/redirectedurls is not a requirement. */
    const REDIRECT_CLASS = 'SilverStripe\\RedirectedURLs\\Model\\RedirectedURL';

    const ACTION_IGNORE = 'fourohfourignore';
    const ACTION_REDIRECT = 'fourohfourredirect';

    /** Name of the row checkboxes (an array of row IDs) and of the redirect target input. */
    const FIELD_SELECT = 'FourOhFourBulk';
    const FIELD_REDIRECT_TO = 'FourOhFourRedirectTo';

    /** @var FourOhFourReport|null */
    protected $report;

    /**
     * @param FourOhFourReport|null $report To rebuild the list after an action, with the same filters
     */
    public function __construct($report = null)
    {
        $this->report = $report;
    }

    /**
     * Whether the "Ignore" action is available (silverstripe/siteconfig with the extension).
     */
    public static function canIgnore()
    {
        $class = FourOhFourIgnoreListExtension::SITECONFIG_CLASS;
        return class_exists($class) && $class::has_extension(FourOhFourIgnoreListExtension::class);
    }

    /**
     * Whether the "Redirect" action is available (silverstripe/redirectedurls).
     */
    public static function canRedirect()
    {
        # class_exists(), not ClassInfo::exists(): a plain Composer package must be autoloadable.
        return class_exists(self::REDIRECT_CLASS);
    }

    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('BulkSelect', $columns, true)) {
            array_unshift($columns, 'BulkSelect');
        }
    }

    public function getColumnsHandled($gridField)
    {
        return array('BulkSelect');
    }

    public function getColumnContent($gridField, $record, $columnName)
    {
        # no-change-track: ticking a row is not an unsaved change of the report form.
        return sprintf(
            '<input type="checkbox" class="no-change-track fourohfour-bulk__select" name="%s[]" value="%d" aria-label="%s">',
            self::FIELD_SELECT,
            (int) $record->ID,
            # The link shortened like its grid cell: a 2048-character URL in every row's label would
            # bloat the page, and the cell's title attribute already carries the full value.
            Convert::raw2att(_t('FourOhFourLogger.SelectRow', 'Select {link}', array(
                'link' => mb_substr((string) $record->Link, 0, (int) FourOhFourReport::config()->get('link_display_length')),
            )))
        );
    }

    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return array('class' => 'col-BulkSelect');
    }

    public function getColumnMetadata($gridField, $columnName)
    {
        return array('title' => '');
    }

    public function getHTMLFragments($gridField)
    {
        $parts = array();
        # Offered only to who may change the list it writes to (the site settings).
        if (static::canIgnore() && static::canEditIgnoreList()) {
            $parts[] = GridField_FormAction::create(
                $gridField,
                'FourOhFourIgnore',
                _t('FourOhFourLogger.IgnoreSelected', 'Ignore selected links'),
                self::ACTION_IGNORE,
                null
            )->addExtraClass('btn btn-outline-secondary font-icon-cancel fourohfour-bulk__ignore')->Field();
        }
        if (static::canRedirect()) {
            $parts[] = sprintf(
                '<input type="text" class="text no-change-track fourohfour-bulk__to" name="%s" placeholder="%s" aria-label="%s">',
                self::FIELD_REDIRECT_TO,
                Convert::raw2att(_t('FourOhFourLogger.RedirectToPlaceholder', '/new-page or https://...')),
                Convert::raw2att(_t('FourOhFourLogger.RedirectTo', 'Redirect the selected links to'))
            );
            $parts[] = GridField_FormAction::create(
                $gridField,
                'FourOhFourRedirect',
                _t('FourOhFourLogger.RedirectSelected', 'Redirect selected links'),
                self::ACTION_REDIRECT,
                null
            )->addExtraClass('btn btn-outline-secondary font-icon-switch fourohfour-bulk__redirect')->Field();
        }
        if (!$parts) {
            return array();
        }

        return array(
            'buttons-before-right' => '<div class="fourohfour-bulk">' . implode(' ', $parts) . '</div>',
        );
    }

    public function getActions($gridField)
    {
        return array(self::ACTION_IGNORE, self::ACTION_REDIRECT);
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        $actionName = strtolower((string) $actionName);
        if (!in_array($actionName, $this->getActions($gridField), true)) {
            return;
        }

        $links = $this->selectedLinks(isset($data[self::FIELD_SELECT]) ? $data[self::FIELD_SELECT] : array());
        if ($actionName === self::ACTION_IGNORE) {
            $message = $this->ignore($links);
        } else {
            $to = isset($data[self::FIELD_REDIRECT_TO]) && is_string($data[self::FIELD_REDIRECT_TO])
                ? $data[self::FIELD_REDIRECT_TO] : '';
            $message = $this->redirect($links, $to);
        }
        static::setStatus($message);

        # The list was built before the action ran; rebuild it so handled rows drop out.
        if ($this->report) {
            $filters = isset($data['filters']) && is_array($data['filters']) ? $data['filters'] : null;
            $gridField->setList($this->report->sourceRecords($this->report->params($filters)));
        }
    }

    /**
     * Add the links to the ignore list and mark their rows handled.
     *
     * @param string[] $links
     * @return string Status message
     */
    public function ignore(array $links)
    {
        if (!static::canIgnore()) {
            return _t('FourOhFourLogger.IgnoreUnavailable', 'The ignore list needs silverstripe/siteconfig.');
        }
        if (!$links) {
            return _t('FourOhFourLogger.NothingSelected', 'Select one or more links first.');
        }
        if (!static::canManage()) {
            return _t('FourOhFourLogger.NotAllowed', 'You are not allowed to do this.');
        }
        # The list is saved on SiteConfig: report access alone must not be a way round the
        # right to edit the site settings.
        if (!static::canEditIgnoreList()) {
            return _t(
                'FourOhFourLogger.IgnoreNotAllowed',
                'Changing the ignore list needs permission to edit the site settings.'
            );
        }

        # A link ending in '*' would become a prefix entry on the list: skipped, and so not
        # marked handled either, since it is not ignored.
        $skipped = 0;
        foreach ($links as $i => $link) {
            if (!FourOhFourIgnoreListExtension::canAddLink($link)) {
                unset($links[$i]);
                $skipped++;
            }
        }
        $links = array_values($links);

        $added = $links ? FourOhFourIgnoreListExtension::addLinks($links) : 0;
        FourOhFourLog::markHandled($links, 'ignored');

        $message = _t(
            'FourOhFourLogger.IgnoredLinks',
            'Ignored {count} link(s); {added} added to the ignore list under Settings.',
            array('count' => count($links), 'added' => $added)
        );
        if ($skipped) {
            $message .= ' ' . _t(
                'FourOhFourLogger.IgnoreSkippedStar',
                '{skipped} skipped: a link ending in * would ignore every link starting with it.',
                array('skipped' => $skipped)
            );
        }

        return $message;
    }

    /**
     * Create a redirect from each link to $to, and mark the rows of the links that got one
     * handled. A link that already has a redirect (same path and query string), or whose path or
     * query string does not fit RedirectedURL's 255-character columns, is skipped.
     *
     * @param string[] $links
     * @param string $to Target: a path ('/new-page') or a full URL
     * @return string Status message
     */
    public function redirect(array $links, $to)
    {
        if (!static::canRedirect()) {
            return _t('FourOhFourLogger.RedirectUnavailable', 'Redirects need silverstripe/redirectedurls.');
        }
        $to = trim((string) $to);
        if (!$links) {
            return _t('FourOhFourLogger.NothingSelected', 'Select one or more links first.');
        }
        if ($to === '') {
            return _t('FourOhFourLogger.RedirectToMissing', 'Enter the URL to redirect to.');
        }
        if (!static::isValidRedirectTarget($to)) {
            return _t(
                'FourOhFourLogger.RedirectToInvalid',
                'Enter a path on this site starting with / (eg /new-page), or a full URL starting with http:// or https://.'
            );
        }
        # The scheme is accepted in any case but stored lower-cased: redirectedurls passes the
        # target through Director::absoluteURL(), which recognises only a lower-case "http(s)://"
        # and would make "HTTPS://host/x" a path on this site (http://this-site/HTTPS://host/x).
        $to = preg_replace_callback('~^https?://~i', function ($match) {
            return strtolower($match[0]);
        }, $to);
        # RedirectedURL.To is a Varchar(255): a longer target would be cut off below and
        # redirect somewhere else than typed.
        if (mb_strlen($to) > 255) {
            return _t('FourOhFourLogger.RedirectToTooLong', 'The URL to redirect to is longer than 255 characters.');
        }
        $class = self::REDIRECT_CLASS;
        $singleton = $class::singleton();
        if (!static::canManage() || !$singleton->canCreate()) {
            return _t('FourOhFourLogger.NotAllowed', 'You are not allowed to do this.');
        }

        $created = array();
        $skipped = 0;
        foreach ($links as $link) {
            $from = '/' . FourOhFourLog::normaliseLink($link);
            $parts = explode('?', $from, 2);
            if (mb_strlen($parts[0]) > 255 || (isset($parts[1]) && mb_strlen($parts[1]) > 255)
                    || $singleton->findByFrom($from)) {
                $skipped++;
                continue;
            }

            $redirect = $class::create();
            $redirect->setFrom($from);
            $redirect->To = mb_substr($to, 0, 255);
            # redirectedurls 3+ redirects to a page, file or URL; a typed target is a URL.
            if ($redirect->hasField('RedirectionType')) {
                $redirect->RedirectionType = 'External';
            }
            $redirect->write();
            $created[] = $link;
        }
        FourOhFourLog::markHandled($created, 'redirected');

        return _t(
            'FourOhFourLogger.RedirectedLinks',
            'Created {count} redirect(s) to {to}; {skipped} skipped (already redirected or too long).',
            array('count' => count($created), 'to' => $to, 'skipped' => $skipped)
        );
    }

    /**
     * Whether a typed redirect target is a path on this site or a web URL. It becomes the
     * Location of a redirect served to every visitor of the old link, so anything else is
     * refused: "//host/x" and "/\host/x" (protocol-relative: browsers treat both as another
     * host), other schemes ("javascript:", "data:"), a bare "host/x", and whitespace or control
     * characters anywhere (header injection, or a target that is not what it looks like).
     *
     * @param string $to Trimmed target
     * @return bool
     */
    public static function isValidRedirectTarget($to)
    {
        $to = (string) $to;
        if ($to === '' || preg_match('~[\x00-\x20\x7f]~', $to)) {
            return false;
        }
        # A site-relative path: one leading slash, not followed by a slash or backslash.
        if (preg_match('~^/(?![/\\\\])~', $to)) {
            return true;
        }
        # An absolute http(s) URL with a host.
        if (preg_match('~^https?://[^/\\\\?#]~i', $to)) {
            return (string) parse_url($to, PHP_URL_HOST) !== '';
        }

        return false;
    }

    /**
     * The distinct links of the selected rows. Each checkbox carries the ID of one row of its
     * link (in the grouped view: the row of its latest hit); the actions apply to the link.
     *
     * @param mixed $ids
     * @return string[]
     */
    public function selectedLinks($ids)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (!$ids) {
            return array();
        }

        $links = array();
        foreach (FourOhFourLog::get()->byIDs($ids)->column('Link') as $link) {
            $links[mb_strtolower((string) $link)] = (string) $link;
        }

        return array_values($links);
    }

    /**
     * Report access is what the log rows themselves require (FourOhFourLog::canEdit()).
     */
    protected static function canManage()
    {
        return Permission::check('CMS_ACCESS_ReportAdmin');
    }

    /**
     * Whether the current member may change the ignore list: it is a field of SiteConfig, so
     * SiteConfig's own canEdit() (EDIT_SITECONFIG by default) decides.
     *
     * @return bool
     */
    protected static function canEditIgnoreList()
    {
        $class = FourOhFourIgnoreListExtension::SITECONFIG_CLASS;
        if (!class_exists($class)) {
            return false;
        }

        return (bool) $class::current_site_config()->canEdit();
    }

    /**
     * Show a message in the CMS: LeftAndMain displays the X-Status header of an ajax response.
     *
     * @param string $message
     */
    protected static function setStatus($message)
    {
        # has_curr() exists on Silverstripe 5 only (where curr() warns without a controller);
        # on 6, curr() returns null instead.
        if (method_exists(Controller::class, 'has_curr')) {
            $controller = Controller::has_curr() ? Controller::curr() : null;
        } else {
            $controller = Controller::curr();
        }
        if ($controller && $controller->getResponse()) {
            $controller->getResponse()->addHeader('X-Status', rawurlencode($message));
        }
    }
}

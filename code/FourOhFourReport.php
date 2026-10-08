<?php

use SilverStripe\Core\Convert;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Reports\Report;

# silverstripe/reports is suggested, not required. Without this guard, a flush builds the config
# manifest, which calls class_exists() on every class (PrivateStaticTransformer) and so autoloads
# this file - and extending a missing parent fatals the whole application at that point.
if (!class_exists(Report::class)) {
    return;
}

/**
 * Report incoming broken links
 *
 * Since 3.2 one row per link by default (hits summed over all its referrers, with the latest
 * referrer and the number of referrers), filterable by recency, category and status, with bulk
 * actions (FourOhFourBulkActions) and a CSV export of the filtered list. The "per referrer" view
 * is the list as it was before: one row per link + referrer.
 */

class FourOhFourReport extends Report
{
    /** Values of the View filter. */
    const VIEW_LINK = 'link';
    const VIEW_REFERRER = 'referrer';

    /** Values of the Status filter. */
    const STATUS_OPEN = 'open';
    const STATUS_HANDLED = 'handled';
    const STATUS_ALL = 'all';

    /**
     * Maximum number of characters of the Link shown in the report grid.
     *
     * @config
     * @var int
     */
    private static $link_display_length = 120;

    /**
     * The choices of the "Last hit" filter, in days.
     *
     * @config
     * @var int[]
     */
    private static $recency_days = array(30, 90, 365);

    /**
     * The window of the "Hits in period" column when no recency filter is set, in days.
     *
     * @config
     * @var int
     */
    private static $default_period_days = 365;

    public function title()
    {
        return _t('FourOhFourLogger.FOUROHFOURREPORT', "(External) broken links report");
    }

    public function parameterFields()
    {
        $params = $this->params();

        $recency = array();
        foreach ((array) static::config()->get('recency_days') as $days) {
            $recency[(int) $days] = _t('FourOhFourLogger.SeenInLastDays', 'In the last {days} days', array('days' => (int) $days));
        }

        $categories = array();
        foreach (array_keys((array) FourOhFourLog::config()->get('category_patterns')) as $category) {
            $categories[$category] = $category;
        }
        $categories[FourOhFourLog::CATEGORY_PAGE] = FourOhFourLog::CATEGORY_PAGE;

        return FieldList::create(
            DropdownField::create('View', _t('FourOhFourLogger.View', 'Show'), array(
                self::VIEW_LINK => _t('FourOhFourLogger.ViewLink', 'One row per link'),
                self::VIEW_REFERRER => _t('FourOhFourLogger.ViewReferrer', 'One row per link and referrer'),
            ), $params['View']),
            DropdownField::create('Recency', _t('FourOhFourLogger.Recency', 'Last hit'), $recency, $params['Recency'] ?: null)
                ->setEmptyString(_t('FourOhFourLogger.AnyTime', 'Any time')),
            DropdownField::create('Category', _t('FourOhFourLogger.Category', 'Category'), $categories, $params['Category'] ?: null)
                ->setEmptyString(_t('FourOhFourLogger.AnyCategory', 'Any category')),
            DropdownField::create('Status', _t('FourOhFourLogger.Status', 'Status'), array(
                self::STATUS_OPEN => _t('FourOhFourLogger.StatusOpen', 'Not handled'),
                self::STATUS_HANDLED => _t('FourOhFourLogger.StatusHandled', 'Handled (ignored or redirected)'),
                self::STATUS_ALL => _t('FourOhFourLogger.StatusAll', 'All'),
            ), $params['Status'])
        );
    }

    /**
     * The report's filter values, from $params or else the request, with defaults filled in.
     *
     * @param array|null $params
     * @return array{View: string, Recency: int, Category: string, Status: string}
     */
    public function params($params = null)
    {
        if (!is_array($params) || !$params) {
            $params = $this->getSourceParams();
        }
        $params = is_array($params) ? $params : array();

        $view = isset($params['View']) ? (string) $params['View'] : '';
        $status = isset($params['Status']) ? (string) $params['Status'] : '';

        return array(
            'View' => $view === self::VIEW_REFERRER ? self::VIEW_REFERRER : self::VIEW_LINK,
            'Recency' => isset($params['Recency']) ? max(0, (int) $params['Recency']) : 0,
            'Category' => isset($params['Category']) ? trim((string) $params['Category']) : '',
            'Status' => in_array($status, array(self::STATUS_HANDLED, self::STATUS_ALL), true) ? $status : self::STATUS_OPEN,
        );
    }

    public function sourceRecords($params = array(), $sort = null, $limit = null)
    {
        $params = $this->params($params);
        if ($params['View'] === self::VIEW_REFERRER) {
            return $this->filteredRows($params);
        }

        return $this->linkRows($params);
    }

    /**
     * Per-referrer rows (the list before 3.2), filtered.
     *
     * @param array $params From params()
     * @return \SilverStripe\ORM\DataList
     */
    public function filteredRows(array $params)
    {
        $list = FourOhFourLog::get();
        if ($params['Category'] !== '') {
            $list = $list->filter('Category', $params['Category']);
        }
        if ($params['Recency'] > 0) {
            $list = $list->filter('LastEdited:GreaterThanOrEqual', $this->cutoff($params['Recency']));
        }
        foreach ($this->statusWhere($params) as $where) {
            $list = $list->where($where);
        }

        return $list;
    }

    /**
     * One row per link (case-insensitive): Count summed over its referrers, Referrers counted,
     * Referrer = the referrer of the most recent hit, Created = first seen, LastEdited = last
     * seen. The recency filter applies to the LINK's last hit, and the totals stay lifetime totals.
     * "RecentCount" is the hits in the recency window (or default_period_days) from the monthly
     * counts, by whole months, so it only covers the time since the upgrade to 3.2.
     *
     * Aggregated in PHP rather than in SQL, to stay portable (no GROUP_CONCAT for "latest
     * referrer"): one pass over the selected columns, newest first, keeping one small array per
     * link. Items are plain arrays, which ArrayList turns into ArrayData only when iterated, so a
     * page of the grid builds 50 objects, not one per link.
     *
     * @param array $params From params()
     * @return \SilverStripe\ORM\ArrayList|\SilverStripe\Model\List\ArrayList
     */
    public function linkRows(array $params)
    {
        $table = DataObject::getSchema()->tableName(FourOhFourLog::class);
        $where = $this->statusWhere($params);
        if ($params['Category'] !== '') {
            $where['"Category" = ?'] = $params['Category'];
        }

        $days = $params['Recency'] > 0 ? $params['Recency'] : (int) static::config()->get('default_period_days');
        $recent = HitMonthCounter::totalsSince(
            FourOhFourLogMonth::class,
            HitMonthCounter::yearMonth(strtotime($this->cutoff(max(1, $days))))
        );

        $rows = SQLSelect::create(
            array('"ID"', '"Link"', '"Referrer"', '"Count"', '"Created"', '"LastEdited"', '"Category"', '"HandledAs"'),
            "\"$table\"",
            $where,
            array('"LastEdited"' => 'DESC', '"ID"' => 'DESC')
        )->execute();

        $groups = array();
        foreach ($rows as $row) {
            $key = mb_strtolower((string) $row['Link']);
            $id = (int) $row['ID'];
            if (!isset($groups[$key])) {
                # Newest first, so the first row of a link carries its latest hit.
                $groups[$key] = array(
                    'ID' => $id,
                    'Link' => (string) $row['Link'],
                    'Referrer' => (string) $row['Referrer'],
                    'Referrers' => 0,
                    'Count' => 0,
                    'RecentCount' => 0,
                    'Category' => (string) $row['Category'],
                    'Created' => $row['Created'],
                    'LastEdited' => $row['LastEdited'],
                    'HandledAs' => (string) $row['HandledAs'],
                );
            }
            $group = &$groups[$key];
            $group['Referrers']++;
            $group['Count'] += (int) $row['Count'];
            $group['RecentCount'] += isset($recent[$id]) ? $recent[$id] : 0;
            if ($row['Created'] && (!$group['Created'] || strcmp($row['Created'], $group['Created']) < 0)) {
                $group['Created'] = $row['Created'];
            }
            unset($group);
        }

        if ($params['Recency'] > 0) {
            $cutoff = $this->cutoff($params['Recency']);
            $groups = array_filter($groups, function ($group) use ($cutoff) {
                return strcmp((string) $group['LastEdited'], $cutoff) >= 0;
            });
        }

        return static::arrayList(array_values($groups));
    }

    /**
     * Count shown on the Reports overview. Report::getCount() would build the whole list; for
     * the default view this is one COUNT query (distinct links).
     */
    public function getCount($params = array(), $limit = null)
    {
        $params = $this->params(is_array($params) ? $params : array());
        if ($params['View'] === self::VIEW_REFERRER || $params['Recency'] > 0) {
            return parent::getCount($params, $limit);
        }

        $table = DataObject::getSchema()->tableName(FourOhFourLog::class);
        $where = $this->statusWhere($params);
        if ($params['Category'] !== '') {
            $where['"Category" = ?'] = $params['Category'];
        }

        return (int) SQLSelect::create('COUNT(DISTINCT LOWER("Link"))', "\"$table\"", $where)->execute()->value();
    }

    public function columns()
    {
        $params = $this->params();
        $byLink = $params['View'] === self::VIEW_LINK;

        $fields = array(
            "Count" => array(
                "title" => _t('FourOhFourLogger.HitCount', 'Amount of hits')
            ),
        );
        if ($byLink) {
            $fields['RecentCount'] = array(
                'title' => _t('FourOhFourLogger.HitsInPeriod', 'Hits in period'),
            );
        }
        $fields['Link'] = array(
            'title' => _t('FourOhFourLogger.Link', 'URL'),
            # Link is a Varchar(2048) and was shown unwrapped, so one long URL pushed the other
            # columns off-screen. Show a shortened value; the full URL stays in the title attribute.
            'formatting' => function ($value, $item) {
                return static::shortenedLink($item);
            },
        );
        if ($byLink) {
            $fields['Referrers'] = array(
                'title' => _t('FourOhFourLogger.Referrers', 'Referrers'),
            );
        }
        $fields["Referrer"] = array(
            "title" => $byLink
                ? _t('FourOhFourLogger.LatestReferrer', 'Latest referrer')
                : _t('FourOhFourLogger.Referrer', "Referrer"),
            # Referrer is a Varchar(2048) too, so it is shortened the same way as Link
            'formatting' => function ($value, $item) {
                return static::shortenedField($item, 'Referrer');
            },
        );
        $fields['Category'] = array(
            'title' => _t('FourOhFourLogger.Category', 'Category'),
        );
        $fields['Created'] = array(
            'title' => _t('FourOhFourLogger.FirstHit', 'First hit'),
            'casting' => 'Datetime->Date',
        );
        $fields["LastEdited"] = array(
            "title" => _t('FourOhFourLogger.LastHit', 'Most recent hit'),
            'casting' => 'Datetime->Full'
        );

        return $fields;
    }

    public function getReportField()
    {
        $field = parent::getReportField();
        $config = $field->getConfig();

        $config->addComponent(FourOhFourBulkActions::create($this));

        # The CSV gets the full values: by default the export takes the grid's formatted cells,
        # which are the shortened link and referrer. Raw values come from data fields of their own
        # (CsvLink, CsvReferrer), which no column formatting applies to.
        $field->addDataFields(array(
            'CsvLink' => function ($record) {
                return (string) $record->Link;
            },
            'CsvReferrer' => function ($record) {
                return (string) $record->Referrer;
            },
        ));
        $export = $config->getComponentByType(GridFieldExportButton::class);
        if ($export) {
            $columns = array();
            foreach ($this->columns() as $source => $info) {
                $title = is_array($info) && isset($info['title']) ? $info['title'] : $source;
                if ($source === 'Link' || $source === 'Referrer') {
                    $source = 'Csv' . $source;
                }
                $columns[$source] = $title;
            }
            $export->setExportColumns($columns);
        }

        return $field;
    }

    /**
     * WHERE clauses for the Status filter.
     *
     * @param array $params From params()
     * @return string[]
     */
    protected function statusWhere(array $params)
    {
        if ($params['Status'] === self::STATUS_OPEN) {
            return array('("HandledAs" IS NULL OR "HandledAs" = \'\')');
        }
        if ($params['Status'] === self::STATUS_HANDLED) {
            return array('("HandledAs" IS NOT NULL AND "HandledAs" <> \'\')');
        }

        return array();
    }

    /**
     * The start of a recency window, as a DB datetime.
     *
     * @param int $days
     * @return string
     */
    protected function cutoff($days)
    {
        return date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - ((int) $days * 86400));
    }

    /**
     * An ArrayList on either major: SilverStripe\ORM\ArrayList on 5, SilverStripe\Model\List\ArrayList on 6.
     *
     * @param array $items
     * @return \SilverStripe\ORM\ArrayList|\SilverStripe\Model\List\ArrayList
     */
    public static function arrayList(array $items)
    {
        $class = class_exists('SilverStripe\\Model\\List\\ArrayList')
            ? 'SilverStripe\\Model\\List\\ArrayList'
            : 'SilverStripe\\ORM\\ArrayList';

        return new $class($items);
    }

    /**
     * The record's Link, limited to link_display_length characters for display in the grid, wrapped
     * in a span whose title attribute carries the full value. Both parts are escaped here, since a
     * 'formatting' callback's return value is output as HTML.
     *
     * @param FourOhFourLog $item
     * @return string
     */
    public static function shortenedLink($item)
    {
        return static::shortenedField($item, 'Link');
    }

    /**
     * Any of the record's long text fields, limited to link_display_length characters for display in
     * the grid, wrapped in a span whose title attribute carries the full value. Both parts are escaped.
     *
     * @param FourOhFourLog|\SilverStripe\View\ArrayData|\SilverStripe\Model\ArrayData $item
     * @param string $field
     * @return string
     */
    public static function shortenedField($item, $field)
    {
        $full = (string) $item->$field;
        # LimitCharacters() works on the plain value and appends an ellipsis only when it truncates.
//        $short = $item->dbObject($field)->LimitCharacters((int) static::config()->get('link_display_length'));
        # A Varchar field object built from the value rather than $item->dbObject(), which only a
        # DataObject has: the grouped view's rows are ArrayData.
        $short = DBField::create_field('Varchar', $full)->LimitCharacters((int) static::config()->get('link_display_length'));

        return sprintf('<span title="%s">%s</span>', Convert::raw2att($full), Convert::raw2xml($short));
    }
}

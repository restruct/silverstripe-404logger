<?php

use SilverStripe\Core\Convert;
use SilverStripe\Reports\Report;

# silverstripe/reports is suggested, not required. Without this guard, a flush builds the config
# manifest, which calls class_exists() on every class (PrivateStaticTransformer) and so autoloads
# this file - and extending a missing parent fatals the whole application at that point.
if (!class_exists(Report::class)) {
    return;
}

/**
 * Report incoming broken links
 */

class FourOhFourReport extends Report
{
    /**
     * Maximum number of characters of the Link shown in the report grid.
     *
     * @config
     * @var int
     */
    private static $link_display_length = 120;

    public function title()
    {
        return _t('FourOhFourLogger.FOUROHFOURREPORT', "(External) broken links report");
    }

    public function parameterFields()
    {
        return false;
    }

    public function sourceRecords($params, $sort, $limit)
    {
        return FourOhFourLog::get();
    }

    public function columns()
    {
        $fields = array(
            "Count" => array(
                "title" => _t('FourOhFourLogger.HitCount', 'Amount of hits')
            ),
            'Link' => array(
                'title' => _t('FourOhFourLogger.Link', 'URL'),
                # Link is a Varchar(2048) and was shown unwrapped, so one long URL pushed the other
                # columns off-screen. Show a shortened value; the full URL stays in the title attribute.
                'formatting' => function ($value, $item) {
                    return static::shortenedLink($item);
                },
            ),
            "Referrer" => array(
                "title" => _t('FourOhFourLogger.Referrer', "Referrer")
            ),
            "LastEdited" => array(
                "title" => _t('FourOhFourLogger.LastHit', 'Most recent hit'),
                'casting' => 'Datetime->Full'
            )
        );

        return $fields;
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
        $full = (string) $item->Link;
        # LimitCharacters() works on the plain value and appends an ellipsis only when it truncates.
        $short = $item->dbObject('Link')->LimitCharacters((int) static::config()->get('link_display_length'));

        return sprintf('<span title="%s">%s</span>', Convert::raw2att($full), Convert::raw2xml($short));
    }
}

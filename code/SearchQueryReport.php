<?php

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Reports\Report;

# silverstripe/reports is suggested, not required. Without this guard, a flush builds the config
# manifest, which calls class_exists() on every class (PrivateStaticTransformer) and so autoloads
# this file - and extending a missing parent fatals the whole application at that point.
if (!class_exists(Report::class)) {
    return;
}

/**
 * Report search queries
 *
 * Since 3.2 a search terms report: per term the lifetime hits, hits per active year, hits this
 * year (from the monthly counts, so only since the upgrade to 3.2), a recency band, and the
 * "new" / "faded" / "noise" flags (SearchLog::termStats()). Filterable on band, trend and noise;
 * every column sorts; the CSV export follows the filters.
 */

class SearchQueryReport
    extends Report
{
    /** Values of the Trend filter. */
    const TREND_NEW = 'new';
    const TREND_FADED = 'faded';

    /** Values of the Noise filter. */
    const NOISE_HIDE = 'hide';
    const NOISE_SHOW = 'show';
    const NOISE_ONLY = 'only';

    public function title()
    {
        return _t('FourOhFourLogger.SEARCHQUERYREPORT', "Search words report");
    }

    public function parameterFields()
    {
        $params = $this->params();

        return FieldList::create(
            DropdownField::create('Band', _t('FourOhFourLogger.LastSearched', 'Last searched'), SearchLog::bandTitles(), $params['Band'] ?: null)
                ->setEmptyString(_t('FourOhFourLogger.AnyTime', 'Any time')),
            DropdownField::create('Trend', _t('FourOhFourLogger.Trend', 'Trend'), array(
                self::TREND_NEW => _t('FourOhFourLogger.TrendNew', 'New since last year'),
                self::TREND_FADED => _t('FourOhFourLogger.TrendFaded', 'Faded (not searched this year)'),
            ), $params['Trend'] ?: null)
                ->setEmptyString(_t('FourOhFourLogger.AnyTrend', 'Any')),
            DropdownField::create('Noise', _t('FourOhFourLogger.Noise', 'Noise'), array(
                self::NOISE_HIDE => _t('FourOhFourLogger.NoiseHide', 'Hide noise'),
                self::NOISE_SHOW => _t('FourOhFourLogger.NoiseShow', 'Show noise too'),
                self::NOISE_ONLY => _t('FourOhFourLogger.NoiseOnly', 'Only noise'),
            ), $params['Noise'])
        );
    }

    /**
     * The report's filter values, from $params or else the request, with defaults filled in.
     *
     * @param array|null $params
     * @return array{Band: string, Trend: string, Noise: string}
     */
    public function params($params = null)
    {
        if (!is_array($params) || !$params) {
            $params = $this->getSourceParams();
        }
        $params = is_array($params) ? $params : array();

        $band = isset($params['Band']) ? (string) $params['Band'] : '';
        $trend = isset($params['Trend']) ? (string) $params['Trend'] : '';
        $noise = isset($params['Noise']) ? (string) $params['Noise'] : '';

        return array(
            'Band' => array_key_exists($band, SearchLog::bandTitles()) ? $band : '',
            'Trend' => in_array($trend, array(self::TREND_NEW, self::TREND_FADED), true) ? $trend : '',
            'Noise' => in_array($noise, array(self::NOISE_SHOW, self::NOISE_ONLY), true) ? $noise : self::NOISE_HIDE,
        );
    }

    /**
     * One row per term, as plain arrays in an ArrayList (turned into ArrayData only when
     * iterated, so a grid page builds 50 objects however many terms there are).
     *
     * Computed in PHP over one pass of the four columns it needs, because the derived figures
     * (per active year, band, flags, noise) are not portable SQL. Fine for tens of thousands of
     * terms; the purge task keeps the table from growing without bound.
     */
    public function sourceRecords($params = array(), $sort = null, $limit = null)
    {
        $params = $this->params($params);
        $now = DBDatetime::now()->getTimestamp();
        $table = DataObject::getSchema()->tableName(SearchLog::class);
        $thisYear = HitMonthCounter::totalsSince(SearchLogMonth::class, (int) (date('Y', $now) . '01'));

        $rows = SQLSelect::create(
            array('"ID"', '"Query"', '"Count"', '"Created"', '"LastEdited"'),
            "\"$table\"",
            array(),
            array('"LastEdited"' => 'DESC', '"ID"' => 'DESC')
        )->execute();

        $bands = SearchLog::bandTitles();
        $items = array();
        foreach ($rows as $row) {
            $stats = SearchLog::termStats($row['Query'], (int) $row['Count'], $row['Created'], $row['LastEdited'], $now);
            if ($params['Band'] !== '' && $stats['Band'] !== $params['Band']) {
                continue;
            }
            if ($params['Trend'] === self::TREND_NEW && !$stats['IsNew']) {
                continue;
            }
            if ($params['Trend'] === self::TREND_FADED && !$stats['IsFaded']) {
                continue;
            }
            if ($params['Noise'] === self::NOISE_HIDE && $stats['IsNoise']) {
                continue;
            }
            if ($params['Noise'] === self::NOISE_ONLY && !$stats['IsNoise']) {
                continue;
            }

            $id = (int) $row['ID'];
            $items[] = array(
                'ID' => $id,
                'Query' => (string) $row['Query'],
                'Count' => (int) $row['Count'],
                'PerActiveYear' => $stats['PerActiveYear'],
                'ThisYear' => isset($thisYear[$id]) ? $thisYear[$id] : 0,
                'Band' => $bands[$stats['Band']],
                # The band titles do not sort in time order, so the Band column sorts on this
                # (0 = this year ... 3 = older), see getReportField().
                'BandOrder' => (int) array_search($stats['Band'], array_keys($bands), true),
                'Flags' => trim(($stats['IsNew'] ? _t('FourOhFourLogger.FlagNew', 'new') : '')
                    . ' ' . ($stats['IsFaded'] ? _t('FourOhFourLogger.FlagFaded', 'faded') : '')),
                'Noise' => $stats['IsNoise'] ? _t('FourOhFourLogger.Yes', 'yes') : '',
                'Created' => $row['Created'],
                'LastEdited' => $row['LastEdited'],
            );
        }

        return FourOhFourReport::arrayList($items);
    }

    /**
     * Count shown on the Reports overview: one COUNT query over all logged terms, rather than
     * building the whole report (Report::getCount()). Includes noise rows.
     */
    public function getCount($params = array(), $limit = null)
    {
        if (is_array($params) && array_filter($params)) {
            return parent::getCount($params, $limit);
        }

        return SearchLog::get()->count();
    }

    public function columns()
    {
        $fields = array(
            "Count" => array(
                "title" => _t('FourOhFourLogger.HitCount', 'Amount of hits')
            ),
            "Query" => array(
                "title" => _t('FourOhFourLogger.SearchTerm', "Search term")
            ),
            'PerActiveYear' => array(
                'title' => _t('FourOhFourLogger.PerActiveYear', 'Hits per active year'),
            ),
            'ThisYear' => array(
                'title' => _t('FourOhFourLogger.ThisYear', 'Hits this year'),
            ),
            'Band' => array(
                'title' => _t('FourOhFourLogger.LastSearched', 'Last searched'),
            ),
            'Flags' => array(
                'title' => _t('FourOhFourLogger.Trend', 'Trend'),
            ),
            'Noise' => array(
                'title' => _t('FourOhFourLogger.Noise', 'Noise'),
            ),
            'Created' => array(
                'title' => _t('FourOhFourLogger.FirstSearched', 'First searched'),
                'casting' => 'Datetime->Date',
            ),
            "LastEdited" => array(
                "title" => _t('FourOhFourLogger.LastHit', 'Most recent hit'),
                'casting' => 'Datetime->Full'
            )
        );

        return $fields;
    }

    public function getReportField()
    {
        $field = parent::getReportField();

        $sortable = $field->getConfig()->getComponentByType(GridFieldSortableHeader::class);
        if ($sortable) {
            $sortable->setFieldSorting(array('Band' => 'BandOrder'));
        }

        return $field;
    }
}

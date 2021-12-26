<?php

use SilverStripe\Reports\Report;

/**
 * Report search queries
 */

class SearchQueryReport
    extends Report
{

    public function title()
    {
        return _t('FourOhFourLogger.SEARCHQUERYREPORT', "Search words report");
    }

    public function parameterFields()
    {
        return false;
    }

    public function sourceRecords($params, $sort, $limit)
    {
        return SearchLog::get();
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
            "LastEdited" => array(
                "title" => _t('FourOhFourLogger.LastHit', 'Most recent hit'),
                'casting' => 'Datetime->Full'
            )
        );

        return $fields;
    }
}

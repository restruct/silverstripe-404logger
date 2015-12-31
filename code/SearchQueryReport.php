<?php

/**
 * Report incoming broken links
 */

class SearchQueryReport extends SS_Report
{

    public function title()
    {
        return _t('FourOhFourLogger.SEARCHQUERYREPORT', "Search usage report");
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
                'casting' => 'SS_Datetime->Full'
            )
        );
        
        return $fields;
    }
}

<?php

use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;

/**
 * Logs Searches to the database
 */
class SearchQueryLogger
    extends Extension
{
    public function contentcontrollerInit()
    {
        $searchQueryParam = Config::inst()->get(SearchLog::class, 'search_query_param');
        if($searchQuery = Controller::curr()->getRequest()->getVar($searchQueryParam)){
            SearchLog::logHit($searchQuery);
        }
    }
}

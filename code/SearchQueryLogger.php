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
        if($searchQuery = Controller::curr()->getRequest()->getVar('Search')){
            SearchLog::logHit($searchQuery);
        }
    }
}

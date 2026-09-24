<?php

use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;

/**
 * Logs Searches to the database
 */
class SearchQueryLogger
    extends Extension
{
    /**
     * Fired by ContentController::init(), which passes itself as the argument.
     *
     * @param Controller|null $controller
     */
    public function contentcontrollerInit($controller = null)
    {
        # The controller is passed by ContentController::init(); fall back to the current one for
        # callers that fire the hook without it.
        if (!$controller instanceof Controller) {
            $controller = Controller::curr();
        }
        if (!$controller) {
            return;
        }

        $searchQueryParam = Config::inst()->get(SearchLog::class, 'search_query_param');
        $searchQuery = $controller->getRequest()->getVar($searchQueryParam);

        # Only a scalar string is a search query. `?Search[]=x` arrives as an array, which would
        # otherwise reach strtolower() in SearchLog::logHit() and fatal the page for any visitor.
        if (!is_string($searchQuery) || trim($searchQuery) === '') {
            return;
        }

        SearchLog::logHit($searchQuery);
    }
}

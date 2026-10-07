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

//        $searchQueryParam = Config::inst()->get(SearchLog::class, 'search_query_param');
//        $searchQuery = $controller->getRequest()->getVar($searchQueryParam);
        # One parameter name (a string, as before 3.1) or a list of them; the first one holding a
        # value is logged, so a request is never counted twice.
        $searchQueryParams = (array) Config::inst()->get(SearchLog::class, 'search_query_param');
        $request = $controller->getRequest();

        foreach ($searchQueryParams as $searchQueryParam) {
            if (!is_string($searchQueryParam) || $searchQueryParam === '') {
                continue;
            }
            $searchQuery = $request->getVar($searchQueryParam);

            # Only a scalar string is a search query. `?Search[]=x` arrives as an array, which would
            # otherwise reach strtolower() in SearchLog::logHit() and fatal the page for any visitor.
            if (!is_string($searchQuery) || trim($searchQuery) === '') {
                continue;
            }

            SearchLog::logHit($searchQuery);
            return;
        }
    }
}

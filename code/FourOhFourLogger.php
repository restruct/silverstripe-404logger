<?php
/**
 * Logs 404s to the database. Apply this to your controller using
 *
 * <code>
 * Controller::add_extension("Controller", "FourOhFourLogger");
 * </code>
 */
class FourOhFourLogger extends Extension {

	/**
	 * @throws SS_HTTPResponse_Exception
	 */
	public function onBeforeHTTPError404($request) {

		$getVars = $request->getVars();
		if(!array_key_exists('url',$getVars)) return; // no use logging...
		$link = $getVars['url'];

		// get referrer
		if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
            $ref = $_SERVER['HTTP_REFERER'];
            $ref = htmlentities(trim($ref), ENT_QUOTES, 'UTF-8');
            // only log external referrers, internal links will be reported in another report
            $parts = parse_url($ref);
            if (isset($parts['host'])
                    && mb_strpos($parts['host'], $_SERVER['HTTP_HOST']) !== false) {
                return;
            }
        } else {
            $ref = 'unknown';
        }

        // log or count 404
        FourOhFourLog::logHit($link, $ref);
    }

    /**
     * @throws SS_HTTPResponse_Exception
     */
    public function logSearchAction()
    {
        $getVars = $this->owner->request->getVars();
        $query = $getVars['Search'];

        // log or count 404
        SearchLog::logHit($query);
    }
}

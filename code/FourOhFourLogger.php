<?php

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\Extension;

/**
 * Logs 404s to the database
 */
class FourOhFourLogger
    extends Extension
{
	public function onBeforeHTTPError($errorCode, HTTPRequest $request, $message)
    {
        if($errorCode!==404){
            return;
        }
		if(!$URL = $request->getURL(true)){
            return; // no use logging...
        }

		// get referrer
		if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
            $ref = $_SERVER['HTTP_REFERER'];
            $ref = htmlentities(trim($ref), ENT_QUOTES, 'UTF-8');
            // only log EXTERNAL referrers, internal links will be reported in another report
            $parts = parse_url($ref);
            if (isset($parts['host'])
                    && mb_strpos($parts['host'], $_SERVER['HTTP_HOST']) !== false) {
                return;
            }
        } else {
            $ref = 'unknown';
        }

        // log or count 404
        FourOhFourLog::logHit($URL, $ref);
    }
}

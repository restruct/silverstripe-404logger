<?php

use SilverStripe\Control\Director;
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
        # Read from the request rather than $_SERVER: identical for a real request (the request is
        # built from $_SERVER), and correct for a request constructed in code or by Director::test().
        $ref = $request->getHeader('Referer');
		if (is_string($ref) && trim($ref) !== '') {
            $ref = htmlentities(trim($ref), ENT_QUOTES, 'UTF-8');
            // only log EXTERNAL referrers, internal links will be reported in another report
            $parts = parse_url($ref);
            # Own host without any port: a Host header of 'example.com:8080' never matched the
            # referrer's port-less host, so internal referrers were logged as external. An empty
            # own host is skipped explicitly, because mb_strpos() with an empty needle returns 0
            # and would classify every referrer as internal.
            $ownHost = parse_url('//' . (string) Director::host($request), PHP_URL_HOST);
            if (isset($parts['host']) && $ownHost
                    && mb_strpos($parts['host'], $ownHost) !== false) {
                return;
            }
        } else {
            $ref = 'unknown';
        }

        // log or count 404
        FourOhFourLog::logHit($URL, $ref);
    }
}

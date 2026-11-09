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
            # and would classify every referrer as internal. isSameSiteHost() needs it as much: with
            # an empty own host it would match any host ending in a dot.
            # The request's OWN Host header, deliberately not Director::host(): that returns
            # Director.alternate_base_url's host first when it is set, so a site reached on another
            # hostname (a staging or secondary domain) would log its own internal links as
            # external. 2.x compared against $_SERVER['HTTP_HOST'], which is this same value.
            $ownHost = parse_url('//' . (string) $request->getHost(), PHP_URL_HOST);
//            if (isset($parts['host']) && $ownHost
//                    && mb_strpos($parts['host'], $ownHost) !== false) {
            if (isset($parts['host']) && $ownHost
                    && static::isSameSiteHost($parts['host'], $ownHost)) {
                return;
            }
        } else {
            $ref = 'unknown';
        }

        // log or count 404
        # The request's Host goes along: a referrer on it that is the URL itself or the site root
        # is a scanner's fake referrer (FourOhFourLog.self_referrer_is_no_referrer).
        FourOhFourLog::logHit($URL, $ref, (string) $request->getHost());
    }

    /**
     * Whether a referrer's host is the site's own host or a subdomain of it (#5): equal, or ending
     * in '.' plus the own host, case-insensitively. So for 'example.com', 'example.com' and
     * 'www.example.com' are internal; 'example.com.elsewhere.org', 'notexample.com' and
     * 'example.community' are not. Before 3.4 a substring match counted every host that merely
     * contained the own host as internal, so real broken inbound links from such hosts went unlogged.
     *
     * Protected rather than public: an extension's public methods are added to every
     * RequestHandler it is applied to.
     *
     * @param string $refHost Host of the referrer, without port
     * @param string $ownHost Host the request was made on, without port; not empty
     * @return bool
     */
    protected static function isSameSiteHost($refHost, $ownHost)
    {
        $refHost = strtolower((string) $refHost);
        $ownHost = strtolower((string) $ownHost);

        return $refHost === $ownHost || str_ends_with($refHost, '.' . $ownHost);
    }
}

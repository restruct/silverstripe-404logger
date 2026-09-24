<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourLogger;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ErrorPage\ErrorPage;

/**
 * FourOhFourLogger: which 404s are logged, through a real request cycle.
 */
class FourOhFourLoggerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    /**
     * Extension is not Injectable on Silverstripe 5 (no ::create()), so go through the Injector.
     */
    private function logger(): FourOhFourLogger
    {
        return Injector::inst()->create(FourOhFourLogger::class);
    }

    public function testExtensionIsAppliedToRequestHandler()
    {
        $this->assertTrue(RequestHandler::has_extension(FourOhFourLogger::class));
    }

    public function testExternalReferrerIsLoggedAndResponseStays404()
    {
        $response = $this->get('no-such-page-404logger', null, [
            'Referer' => 'https://elsewhere.example/links',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(1, FourOhFourLog::get());
        $log = FourOhFourLog::get()->first();
        $this->assertSame('no-such-page-404logger', $log->Link);
        $this->assertSame('https://elsewhere.example/links', $log->Referrer);
        $this->assertSame(1, (int) $log->Count);
    }

    /**
     * Pins the CURRENT stored form: the referrer is trimmed and stored HTML-entity encoded
     * (htmlentities, ENT_QUOTES, UTF-8), so existing rows and anything reading them stay
     * consistent. Changing that is a deliberate decision, not a side effect of a refactor.
     */
    public function testReferrerIsStoredHtmlEntityEncoded()
    {
        $this->get('no-such-page-404logger', null, [
            'Referer' => " https://elsewhere.example/café?a=1&b=<x>&c='q'\"r\" ",
        ]);

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame(
            'https://elsewhere.example/caf&eacute;?a=1&amp;b=&lt;x&gt;&amp;c=&#039;q&#039;&quot;r&quot;',
            FourOhFourLog::get()->first()->Referrer
        );
    }

    public function testQueryStringIsPartOfTheLoggedLink()
    {
        $this->get('no-such-page-404logger?utm_source=x', null, ['Referer' => 'https://elsewhere.example/']);

        $this->assertSame('no-such-page-404logger?utm_source=x', FourOhFourLog::get()->first()->Link);
    }

    public function testMissingReferrerIsLoggedAsUnknown()
    {
        $this->get('no-such-page-404logger');

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame('unknown', FourOhFourLog::get()->first()->Referrer);
    }

    public function testRepeatRequestIsCounted()
    {
        $headers = ['Referer' => 'https://elsewhere.example/links'];
        $this->get('no-such-page-404logger', null, $headers);
        $this->get('no-such-page-404logger', null, $headers);

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame(2, (int) FourOhFourLog::get()->first()->Count);
    }

    public function testInternalReferrerIsNotLogged()
    {
        $this->get('no-such-page-404logger', null, [
            'Host' => 'mysite.example',
            'Referer' => 'https://mysite.example/some/page',
        ]);

        $this->assertCount(0, FourOhFourLog::get());
    }

    /**
     * Regression: the Host header carries the port, the referrer's parsed host does not, so on a
     * non-standard port every internal referrer was logged as external.
     */
    public function testInternalReferrerOnNonStandardPortIsNotLogged()
    {
        $this->get('no-such-page-404logger', null, [
            'Host' => 'mysite.example:8080',
            'Referer' => 'https://mysite.example:8080/some/page',
        ]);

        $this->assertCount(0, FourOhFourLog::get());
    }

    /**
     * Regression: "internal" means the host the request was made on, as in 2.x. Director::host()
     * returns Director.alternate_base_url's host first, so with it set, a site reached on another
     * hostname logged its own links as external and the canonical host's links as internal.
     */
    public function testInternalMeansTheRequestHostEvenWithAlternateBaseUrl()
    {
        Director::config()->set('alternate_base_url', 'https://canonical.example/');

        $this->get('no-such-page-404logger', null, [
            'Host' => 'mysite.example',
            'Referer' => 'https://mysite.example/some/page',
        ]);
        $this->assertCount(0, FourOhFourLog::get(), 'a referrer on the request host is internal');

        $this->get('no-such-page-404logger', null, [
            'Host' => 'mysite.example',
            'Referer' => 'https://canonical.example/some/page',
        ]);
        $this->assertCount(1, FourOhFourLog::get(), 'a referrer on another host is external');
        $this->assertSame('https://canonical.example/some/page', FourOhFourLog::get()->first()->Referrer);
    }

    public function testOtherErrorCodesAreNotLogged()
    {
        $request = new HTTPRequest('GET', 'forbidden/thing');
        $this->logger()->onBeforeHTTPError(403, $request, 'Forbidden');
        $this->logger()->onBeforeHTTPError(500, $request, 'Error');

        $this->assertCount(0, FourOhFourLog::get());
    }

    public function testEmptyUrlIsNotLogged()
    {
        $this->logger()->onBeforeHTTPError(404, new HTTPRequest('GET', ''), 'Not found');

        $this->assertCount(0, FourOhFourLog::get());
    }

    /**
     * A site with a published 404 ErrorPage: silverstripe/errorpage hooks the same
     * onBeforeHTTPError event and throws its own response. The 404 must still be logged.
     */
    public function testIsLoggedWhenA404ErrorPageExists()
    {
        if (!class_exists(ErrorPage::class)) {
            $this->markTestSkipped('silverstripe/errorpage is not installed');
        }
        $errorPage = ErrorPage::create(['Title' => 'Not found', 'ErrorCode' => 404, 'URLSegment' => 'page-not-found']);
        $errorPage->write();
        $errorPage->publishRecursive();

        $response = $this->get('no-such-page-404logger', null, ['Referer' => 'https://elsewhere.example/']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Not found', (string) $response->getBody(), 'the ErrorPage rendered');
        $this->assertCount(1, FourOhFourLog::get());
    }
}

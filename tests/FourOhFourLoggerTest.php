<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use FourOhFourLogger;
use FourOhFourReport;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Reports\Report;
use SilverStripe\Reports\ReportAdmin;

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
     * Pins the stored form: the referrer is trimmed and stored as sent, NOT HTML-entity encoded
     * (#6, 3.4). Until 3.3 it was stored htmlentities()-encoded, which the report then escaped a
     * second time. Changing the stored form is a deliberate decision, not a side effect of a
     * refactor: existing rows need FourOhFourLogReferrerDecodeTask.
     */
//    public function testReferrerIsStoredHtmlEntityEncoded()
//    {
//        $this->get('no-such-page-404logger', null, [
//            'Referer' => " https://elsewhere.example/café?a=1&b=<x>&c='q'\"r\" ",
//        ]);
//
//        $this->assertCount(1, FourOhFourLog::get());
//        $this->assertSame(
//            'https://elsewhere.example/caf&eacute;?a=1&amp;b=&lt;x&gt;&amp;c=&#039;q&#039;&quot;r&quot;',
//            FourOhFourLog::get()->first()->Referrer
//        );
//    }
    public function testReferrerIsStoredTrimmedAndUnencoded()
    {
        $this->get('no-such-page-404logger', null, [
            'Referer' => " https://elsewhere.example/café?a=1&b=<x>&c='q'\"r\" ",
        ]);

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame(
            "https://elsewhere.example/café?a=1&b=<x>&c='q'\"r\"",
            FourOhFourLog::get()->first()->Referrer
        );
    }

    /**
     * #6, what an editor saw: a referrer with '&' was shown as '&amp;amp;' in the report, since it
     * was stored encoded and the report's cell escapes it again. Now it is escaped once.
     */
    public function testReferrerIsShownEscapedOnceInTheReport()
    {
        if (!class_exists(Report::class)) {
            $this->markTestSkipped('silverstripe/reports is not installed');
        }
        $this->get('no-such-page-404logger', null, [
            'Referer' => 'https://elsewhere.example/links?a=1&b=2',
        ]);

        $this->logInWithPermission('ADMIN');
        $field = FourOhFourReport::create()->getReportField();
        # Inside a form, as ReportAdmin renders it: the grid's buttons need one for their links
        Form::create(ReportAdmin::singleton(), 'EditForm', FieldList::create($field), FieldList::create());
        $html = (string) $field->forTemplate();

        $this->assertStringContainsString('<span title="https://elsewhere.example/links?a=1&amp;b=2">', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
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
     * #5: a referrer host that merely CONTAINS the site's host is another site, so its broken
     * links are logged. Before the fix a substring match counted all of these as internal.
     */
    public function testLookalikeReferrerHostIsLoggedAsExternal()
    {
        $lookalikes = [
            'https://mysite.example.elsewhere.org/links',
            'https://notmysite.example/links',
            'https://mysite.examples/links',
        ];
        foreach ($lookalikes as $i => $referrer) {
            $this->get('no-such-page-404logger', null, [
                'Host' => 'mysite.example',
                'Referer' => $referrer,
            ]);
            $this->assertCount($i + 1, FourOhFourLog::get(), "$referrer is not the site's host");
        }
        $this->assertSame($lookalikes, FourOhFourLog::get()->sort('ID')->column('Referrer'));
    }

    /**
     * #5: the site's own host, any subdomain of it ('www.') and any letter case are internal.
     */
    public function testSubdomainAndCaseOfTheSiteHostAreInternal()
    {
        foreach (['https://www.mysite.example/page', 'https://a.b.mysite.example/page', 'https://MySite.Example/page'] as $referrer) {
            $this->get('no-such-page-404logger', null, [
                'Host' => 'mysite.example',
                'Referer' => $referrer,
            ]);
            $this->assertCount(0, FourOhFourLog::get(), "$referrer is on the site's host");
        }
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

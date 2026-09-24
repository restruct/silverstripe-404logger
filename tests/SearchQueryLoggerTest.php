<?php

namespace Restruct\FourOhFourLogger\Tests;

use SearchLog;
use SearchQueryLogger;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\FunctionalTest;

/**
 * SearchQueryLogger: search queries on front-end pages are logged, through a real request cycle.
 */
class SearchQueryLoggerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(SiteTree::class)) {
            $this->markTestSkipped('silverstripe/cms is not installed');
        }
        # RootURLController serves the page with URLSegment 'home' at '/'.
        $home = SiteTree::create(['Title' => 'Home', 'URLSegment' => 'home']);
        $home->write();
        $home->publishRecursive();
    }

    public function testExtensionIsAppliedToSiteTree()
    {
        $this->assertTrue(SiteTree::has_extension(SearchQueryLogger::class));
    }

    /**
     * Regression: SearchQueryLogger called Config::inst() without importing Config, so every
     * front-end page fataled with 'Class "Config" not found', search parameter or not.
     */
    public function testPageRendersAndSearchIsLogged()
    {
        $response = $this->get('/?Search=Opening+Hours');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, SearchLog::get());
        $this->assertSame('opening hours', SearchLog::get()->first()->Query);
    }

    public function testPageWithoutSearchParamLogsNothing()
    {
        $response = $this->get('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, SearchLog::get());
    }

    public function testEmptySearchParamLogsNothing()
    {
        $this->get('/?Search=+');

        $this->assertCount(0, SearchLog::get());
    }

    /**
     * Regression: `?Search[]=x` arrives as an array and reached strtolower(), a TypeError that
     * turned any front-end page into a 500 for anyone adding the parameter.
     */
    public function testArraySearchParamIsIgnoredAndPageStillRenders()
    {
        $response = $this->get('/?Search[]=x');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, SearchLog::get());
    }

    public function testSearchQueryParamConfigChangesWhichParamIsLogged()
    {
        SearchLog::config()->set('search_query_param', 'q');

        $this->get('/?q=configured');
        $this->get('/?Search=default');

        $this->assertSame(['configured'], SearchLog::get()->column('Query'));
    }
}

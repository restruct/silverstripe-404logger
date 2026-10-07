<?php

namespace Restruct\FourOhFourLogger\Tests;

use SearchLog;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\FunctionalTest;

/**
 * SearchLog.search_query_param accepts a list of parameter names as well as a single one.
 */
class SearchQueryParamListTest extends FunctionalTest
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

    /**
     * Regression: a site whose search form uses another parameter (eg ?s=) logged nothing, and
     * only one name could be configured.
     */
    public function testEveryListedParamIsLogged()
    {
        SearchLog::config()->set('search_query_param', ['Search', 's']);

        $this->get('/?s=first');
        $this->get('/?Search=second');
        $this->get('/?q=unlisted');

        $this->assertSame(['first', 'second'], SearchLog::get()->sort('ID')->column('Query'));
    }

    /**
     * One query per request: the first listed parameter holding a value wins.
     */
    public function testFirstListedParamWithAValueWins()
    {
        SearchLog::config()->set('search_query_param', ['Search', 's']);

        $this->get('/?s=second&Search=first');
        $this->get('/?Search=+&s=fallback');

        $this->assertSame(['first', 'fallback'], SearchLog::get()->sort('ID')->column('Query'));
    }

    public function testArrayValueInAListedParamIsSkipped()
    {
        SearchLog::config()->set('search_query_param', ['Search', 's']);

        $response = $this->get('/?Search[]=x&s=kept');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['kept'], SearchLog::get()->column('Query'));
    }
}

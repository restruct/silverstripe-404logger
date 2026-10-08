<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourBulkActions;
use FourOhFourIgnoreListExtension;
use FourOhFourLog;
use FourOhFourReport;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Reports\Report;
use SilverStripe\Reports\ReportAdmin;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * 3.2 bulk actions on the broken links report, the CMS ignore list they write to, and the
 * "handled" marking.
 */
class FourOhFourBulkActionsTest extends SapphireTest
{
    protected $usesDatabase = true;

    const REDIRECT_CLASS = 'SilverStripe\\RedirectedURLs\\Model\\RedirectedURL';

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Report::class) || !class_exists(SiteConfig::class)) {
            $this->markTestSkipped('needs silverstripe/reports and silverstripe/siteconfig');
        }
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function ids(array $logs): array
    {
        return array_map(function ($log) {
            return (int) $log->ID;
        }, $logs);
    }

    private function rawRow(int $id): array
    {
        return SQLSelect::create('*', '"FourOhFourLog"', ['"ID"' => $id])->execute()->record();
    }

    public function testIgnoreListMatchesExactLinksAndPrefixes()
    {
        $config = SiteConfig::current_site_config();
        $config->FourOhFourIgnoreList = "# comment\n/Old-Page\n\ndownloads/*\n  spaced  \n*\n";
        $config->write();

        $this->assertSame(['old-page', 'downloads/*', 'spaced'], FourOhFourIgnoreListExtension::entries());
        $this->assertTrue(FourOhFourLog::matchesIgnoreList('old-page'));
        $this->assertTrue(FourOhFourLog::matchesIgnoreList('/OLD-PAGE'), 'leading slash and case do not matter');
        $this->assertFalse(FourOhFourLog::matchesIgnoreList('old-page/child'), 'an entry without * is exact');
        $this->assertFalse(FourOhFourLog::matchesIgnoreList('old-page?x=1'), 'exact includes the query string');
        $this->assertTrue(FourOhFourLog::matchesIgnoreList('downloads/brochure.pdf'));
        $this->assertTrue(FourOhFourLog::matchesIgnoreList('spaced'));
        $this->assertFalse(FourOhFourLog::matchesIgnoreList('anything-else'), 'a lone * is skipped, not "ignore all"');

        # And logHit() honours it, before any lookup.
        $this->assertNull(FourOhFourLog::logHit('downloads/old.pdf', 'https://elsewhere.example/'));
        $this->assertNotNull(FourOhFourLog::logHit('other/page', 'https://elsewhere.example/'));
        $this->assertSame(1, FourOhFourLog::get()->count());
    }

    public function testIgnoreActionAddsLinksToTheListAndMarksThemHandled()
    {
        DBDatetime::set_mock_now('2026-10-01 10:00:00');
        $a = FourOhFourLog::logHit('old/one', 'https://a.example/');
        $a2 = FourOhFourLog::logHit('Old/One', 'https://b.example/');
        $b = FourOhFourLog::logHit('old/two', 'unknown');
        $keep = FourOhFourLog::logHit('old/three', 'unknown');
        DBDatetime::set_mock_now('2026-10-08 12:00:00');

        $this->logInWithPermission('ADMIN');
        $bulk = FourOhFourBulkActions::create();
        $message = $bulk->ignore($bulk->selectedLinks($this->ids([$a, $b])));
        $this->assertStringContainsString('2', $message);

        $this->assertSame(['old/one', 'old/two'], FourOhFourIgnoreListExtension::entries());
        foreach ([$a, $a2, $b] as $log) {
            $row = $this->rawRow((int) $log->ID);
            $this->assertSame('ignored', $row['HandledAs'], 'every referrer row of the link');
            $this->assertSame('2026-10-08 12:00:00', $row['HandledAt']);
            $this->assertSame('2026-10-01 10:00:00', $row['LastEdited'], 'the last hit date is kept');
        }
        $this->assertNull($this->rawRow((int) $keep->ID)['HandledAs']);

        # Further hits are no longer logged; the same links again do not duplicate the list.
        $this->assertNull(FourOhFourLog::logHit('old/two', 'unknown'));
        $bulk->ignore(['OLD/ONE']);
        $this->assertSame(['old/one', 'old/two'], FourOhFourIgnoreListExtension::entries());
    }

    public function testIgnoreNeedsReportAccess()
    {
        $log = FourOhFourLog::logHit('old/no-access', 'unknown');
        $this->logInWithPermission('SOME_OTHER_PERMISSION');

        FourOhFourBulkActions::create()->ignore(['old/no-access']);

        $this->assertSame([], FourOhFourIgnoreListExtension::entries());
        $this->assertNull($this->rawRow((int) $log->ID)['HandledAs']);
    }

    /**
     * The ignore list lives on SiteConfig, so changing it needs the right to edit the site
     * settings, not only report access. Without it the button is not offered either.
     */
    public function testIgnoreNeedsSiteConfigEditPermission()
    {
        $log = FourOhFourLog::logHit('old/report-only', 'unknown');
        $this->logInWithPermission('CMS_ACCESS_ReportAdmin');

        $message = FourOhFourBulkActions::create()->ignore(['old/report-only']);

        $this->assertStringContainsString('settings', $message);
        $this->assertSame([], FourOhFourIgnoreListExtension::entries(), 'SiteConfig is not written');
        $this->assertNull($this->rawRow((int) $log->ID)['HandledAs']);
        $field = FourOhFourReport::create()->getReportField();
        Form::create(ReportAdmin::singleton(), 'EditForm', FieldList::create($field), FieldList::create());
        $this->assertStringNotContainsString('Ignore selected links', (string) $field->forTemplate());

        # With the settings right as well, it works.
        $this->logInWithPermission(['CMS_ACCESS_ReportAdmin', 'EDIT_SITECONFIG']);
        FourOhFourBulkActions::create()->ignore(['old/report-only']);
        $this->assertSame(['old/report-only'], FourOhFourIgnoreListExtension::entries());
        $this->assertSame('ignored', $this->rawRow((int) $log->ID)['HandledAs']);
    }

    /**
     * A trailing '*' on the ignore list means "every link starting with this", so a logged link
     * that happens to end in '*' must not be added verbatim: it would silently ignore far more
     * than the one link. It is skipped, said so, and not marked handled.
     */
    public function testALinkEndingInAStarIsNotAddedAsAPrefix()
    {
        $star = FourOhFourLog::logHit('old/page*', 'unknown');
        $plain = FourOhFourLog::logHit('old/plain', 'unknown');
        $this->assertNotNull($star);
        $this->logInWithPermission('ADMIN');
        $bulk = FourOhFourBulkActions::create();

        $message = $bulk->ignore($bulk->selectedLinks($this->ids([$star, $plain])));

        $this->assertSame(['old/plain'], FourOhFourIgnoreListExtension::entries());
        $this->assertFalse(FourOhFourLog::matchesIgnoreList('old/page-two'), 'no prefix entry was created');
        $this->assertStringContainsString('1 skipped', $message);
        $this->assertNull($this->rawRow((int) $star->ID)['HandledAs'], 'not on the list, so not handled');
        $this->assertSame('ignored', $this->rawRow((int) $plain->ID)['HandledAs']);
        $this->assertSame(0, FourOhFourIgnoreListExtension::addLinks(['other/*']), 'addLinks() itself refuses it');
    }

    public function testAHandledLinkThatIsHitAgainShowsAgain()
    {
        $log = FourOhFourLog::logHit('old/redirected', 'unknown');
        FourOhFourLog::markHandled(['old/redirected'], 'redirected');
        $this->assertSame('redirected', $this->rawRow((int) $log->ID)['HandledAs']);

        # The redirect does not work (or was removed): the next 404 must surface it again.
        FourOhFourLog::logHit('old/redirected', 'unknown');
        $row = $this->rawRow((int) $log->ID);
        $this->assertNull($row['HandledAs']);
        $this->assertNull($row['HandledAt']);
    }

    public function testRedirectActionCreatesRedirectsAndMarksHandled()
    {
        if (!class_exists(self::REDIRECT_CLASS)) {
            $this->markTestSkipped('silverstripe/redirectedurls is not installed');
        }
        $class = self::REDIRECT_CLASS;
        $a = FourOhFourLog::logHit('old/news?id=5', 'https://a.example/');
        $b = FourOhFourLog::logHit('old/about', 'unknown');
        $existing = $class::create();
        $existing->setFrom('/old/about');
        $existing->To = '/elsewhere';
        $existing->write();

        $this->logInWithPermission('ADMIN');
        $bulk = FourOhFourBulkActions::create();
        $message = $bulk->redirect($bulk->selectedLinks($this->ids([$a, $b])), ' /news ');

        $this->assertStringContainsString('1 redirect', $message);
        $created = $class::get()->filter('FromBase', '/old/news')->first();
        $this->assertNotNull($created);
        $this->assertSame('id=5', $created->FromQuerystring);
        $this->assertSame('/news', $created->To);
        if ($created->hasField('RedirectionType')) {
            $this->assertSame('External', $created->RedirectionType);
        }
        $this->assertSame(2, $class::get()->count(), 'the existing redirect is not duplicated');
        $this->assertSame('redirected', $this->rawRow((int) $a->ID)['HandledAs']);
        $this->assertNull($this->rawRow((int) $b->ID)['HandledAs'], 'a skipped link is not marked handled');
    }

    public function testRedirectNeedsATarget()
    {
        if (!class_exists(self::REDIRECT_CLASS)) {
            $this->markTestSkipped('silverstripe/redirectedurls is not installed');
        }
        $class = self::REDIRECT_CLASS;
        $log = FourOhFourLog::logHit('old/no-target', 'unknown');
        $this->logInWithPermission('ADMIN');

        FourOhFourBulkActions::create()->redirect(['old/no-target'], '   ');

        $this->assertSame(0, $class::get()->count());
        $this->assertNull($this->rawRow((int) $log->ID)['HandledAs']);
    }

    /**
     * Report access alone does not allow creating redirects: that is redirectedurls' own
     * REDIRECTEDURLS_CREATE permission (RedirectedURL::canCreate()).
     */
    public function testRedirectNeedsRedirectedUrlsCreatePermission()
    {
        if (!class_exists(self::REDIRECT_CLASS)) {
            $this->markTestSkipped('silverstripe/redirectedurls is not installed');
        }
        $class = self::REDIRECT_CLASS;
        $log = FourOhFourLog::logHit('old/no-redirect-right', 'unknown');
        $this->logInWithPermission('CMS_ACCESS_ReportAdmin');

        $message = FourOhFourBulkActions::create()->redirect(['old/no-redirect-right'], '/news');

        $this->assertStringContainsString('not allowed', $message);
        $this->assertSame(0, $class::get()->count());
        $this->assertNull($this->rawRow((int) $log->ID)['HandledAs']);

        $this->logInWithPermission(['CMS_ACCESS_ReportAdmin', 'REDIRECTEDURLS_CREATE']);
        FourOhFourBulkActions::create()->redirect(['old/no-redirect-right'], '/news');
        $this->assertSame(1, $class::get()->count());
        $this->assertSame('redirected', $this->rawRow((int) $log->ID)['HandledAs']);
    }

    /**
     * The typed target becomes the Location of a redirect served to every visitor, so only a
     * site-relative path ("/x", not the protocol-relative "//host/x" or "/\host/x") or an
     * http(s) URL is accepted.
     */
    public function testRedirectRefusesTargetsThatAreNotASitePathOrAWebUrl()
    {
        if (!class_exists(self::REDIRECT_CLASS)) {
            $this->markTestSkipped('silverstripe/redirectedurls is not installed');
        }
        $class = self::REDIRECT_CLASS;
        $log = FourOhFourLog::logHit('old/unsafe-target', 'unknown');
        $this->logInWithPermission('ADMIN');
        $bulk = FourOhFourBulkActions::create();

        $refused = [
            '//evil.example/x',
            '/\\evil.example/x',
            'javascript:alert(1)',
            'JavaScript://evil.example/%0Aalert(1)',
            'data:text/html,x',
            'evil.example/x',
            'ftp://files.example/x',
            'https://',
            "/news\r\nX-Injected: 1",
        ];
        foreach ($refused as $to) {
            $message = $bulk->redirect(['old/unsafe-target'], $to);
            $this->assertStringContainsString('starting with /', $message, $to);
        }
        $this->assertStringContainsString('255', $bulk->redirect(['old/unsafe-target'], '/' . str_repeat('a', 255)));
        $this->assertSame(0, $class::get()->count());
        $this->assertNull($this->rawRow((int) $log->ID)['HandledAs']);

        $bulk->redirect(['old/unsafe-target'], 'HTTPS://www.example/new-home');
        $this->assertSame('HTTPS://www.example/new-home', $class::get()->first()->To);
    }

    /**
     * The GridField action path: checkboxes and target arrive in $data, and the list is rebuilt
     * with the posted filters so the handled rows drop out of the response.
     */
    public function testHandleActionReadsThePostedSelectionAndRefreshesTheList()
    {
        $a = FourOhFourLog::logHit('old/posted', 'unknown');
        FourOhFourLog::logHit('old/stays', 'unknown');
        $this->logInWithPermission('ADMIN');

        $report = FourOhFourReport::create();
        $field = $report->getReportField();
        Form::create(ReportAdmin::singleton(), 'EditForm', FieldList::create($field), FieldList::create());
        $this->assertCount(2, $field->getList());

        $bulk = $field->getConfig()->getComponentByType(FourOhFourBulkActions::class);
        $this->assertNotNull($bulk, 'the report grid carries the bulk actions');
        $this->assertContains(FourOhFourBulkActions::ACTION_IGNORE, $bulk->getActions($field));
        $bulk->handleAction($field, FourOhFourBulkActions::ACTION_IGNORE, [], [
            FourOhFourBulkActions::FIELD_SELECT => [(string) $a->ID, 'not-a-number'],
            'filters' => ['Status' => 'open'],
        ]);

        $this->assertSame(['old/posted'], FourOhFourIgnoreListExtension::entries());
        $links = [];
        foreach ($field->getList() as $item) {
            $links[] = $item->Link;
        }
        $this->assertSame(['old/stays'], $links);
    }

    public function testRedirectButtonOnlyWithRedirectedUrls()
    {
        $this->logInWithPermission('ADMIN');
        FourOhFourLog::logHit('old/buttons', 'unknown');
        $field = FourOhFourReport::create()->getReportField();
        Form::create(ReportAdmin::singleton(), 'EditForm', FieldList::create($field), FieldList::create());
        $html = (string) $field->forTemplate();

        $this->assertStringContainsString('Ignore selected links', $html);
        $this->assertSame(
            class_exists(self::REDIRECT_CLASS),
            str_contains($html, 'Redirect selected links'),
            'the redirect action is offered exactly when silverstripe/redirectedurls is installed'
        );
    }
}

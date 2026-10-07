<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;

/**
 * FourOhFourLog 3.1: rule-based categories, and the ignore list that drops scanner and probe
 * hits before any database work.
 */
class FourOhFourCategoryTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * Generic examples of what reaches PHP on a typical site, by expected category.
     */
    public static function provideLinks(): array
    {
        return [
            // device and browser probes
            ['.well-known/passkey-endpoints', 'probe'],
            ['.well-known/traffic-advice', 'probe'],
            ['/.well-known/change-password', 'probe'],
            ['apple-touch-icon-precomposed.png', 'probe'],
            ['some/page/apple-touch-icon.png', 'probe'],
            ['favicon.ico', 'probe'],
            ['manifest.json', 'probe'],
            ['robots.txt', 'probe'],
            ['sitemap.xml', 'probe'],
            ['autodiscover/autodiscover.xml', 'probe'],
            // scanners and bad actors
            ['wp-login.php', 'scanner'],
            ['wp-content/plugins/x/readme.txt', 'scanner'],
            ['xmlrpc.php', 'scanner'],
            ['shell.php?cmd=id', 'scanner'],
            ['assets/upload.php', 'scanner'],
            ['.well-known/x.php', 'scanner'],
            ['configuration.php_old', 'scanner'],
            ['.env', 'scanner'],
            ['.env.production', 'scanner'],
            ['api/.env.old', 'scanner'],
            ['.git/config', 'scanner'],
            ['some/page/.git/HEAD', 'scanner'],
            ['.aws/credentials', 'scanner'],
            ['backup.sql', 'scanner'],
            ['site.tar.gz', 'scanner'],
            ['old.sql.gz', 'scanner'],
            ['backup.zip', 'scanner'],
            ['config.json', 'scanner'],
            ['firebase-service-account.json', 'scanner'],
            ['docker-compose.yml', 'scanner'],
            ['phpmyadmin/', 'scanner'],
            ['actuator/env', 'scanner'],
            ['vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', 'scanner'],
            ['@fs/etc/passwd?raw', 'scanner'],
            ['%40fs/etc/passwd', 'scanner'],
            ['userfiles?path=../../etc', 'scanner'],
            ['..%2f..%2fetc/passwd', 'scanner'],
            ['page?id=1+union+select+1,2', 'scanner'],
            ['search?q=<script>alert(1)</script>', 'scanner'],
            // missing files
            ['assets/Uploads/report.pdf', 'asset'],
            ['assets/archive.zip', 'asset'],
            ['images/photo.JPG', 'asset'],
            ['downloads/brochure.pdf?v=2', 'asset'],
            ['_resources/themes/site/dist/app.css', 'asset'],
            // pages, including ones that merely contain a scanner-ish word
            ['about-us/old-page', 'page'],
            ['news/2019/some-article', 'page'],
            ['courses?category=welding', 'page'],
            ['login', 'page'],
            ['the-secrets-of-good-welding', 'page'],
            ['select-a-course-from-the-list', 'page'],
            ['european-union-select-committee', 'page'],
            ['docker-workshop', 'page'],
            ['admin-assistant-vacancy', 'page'],
            // template code leaking into a link is the site's own broken link, not an attack
            ['item.cost < item.total || x', 'page'],
            // Real inbound links that an earlier, broader version of the scanner patterns dropped:
            // a scanner-ish word in a slug or folder, or an archive/log/json file in a folder.
            ['team/jenkins-smith', 'page'],
            ['events/solr-workshop', 'page'],
            ['telescope-hire', 'page'],
            ['hudson-bay-trip', 'page'],
            ['actuator-repair', 'page'],
            ['ecp-programme', 'page'],
            ['about/administrator', 'page'],
            ['xmlrpc-explained', 'page'],
            ['news/wordpress-vs-silverstripe', 'page'],
            ['nieuws/wp-plugin-review', 'page'],
            ['wiki/WP-Rating', 'page'],
            ['vendor/acme-bakery', 'page'],
            ['careers/.env-engineer', 'page'],
            ['files/prijslijst-2020.zip', 'asset'],
            ['downloads/brochure.zip', 'asset'],
            ['producten/model-x.old', 'page'],
            ['reports/2024.log', 'page'],
            ['portfolio/project.json', 'page'],
            ['api/data.json', 'page'],
            // ... while the real probes for those names still are scanners.
            ['jenkins/script', 'scanner'],
            ['solr/admin/cores', 'scanner'],
            ['telescope/requests', 'scanner'],
            ['administrator/index.php', 'scanner'],
            ['administrator/', 'scanner'],
            ['wp-admin/', 'scanner'],
            ['wp-json/wp/v2/users', 'scanner'],
            ['wp-config.php.bak', 'scanner'],
            ['wordpress/wp-login.php', 'scanner'],
            ['wp/wp-includes/', 'scanner'],
            ['some/blog/wlwmanifest.xml', 'scanner'],
            ['vendor/composer/installed.json', 'scanner'],
            ['node_modules/.bin/x', 'scanner'],
            ['.env.old', 'scanner'],
            ['.env_backup', 'scanner'],
            ['error.log', 'scanner'],
            ['core/config/main.bak', 'scanner'],
            ['dump.sql.gz', 'scanner'],
            ['phpMyAdmin-5.2/', 'scanner'],
            ['phpinfo', 'scanner'],
            ['web.config.original', 'scanner'],
        ];
    }

    /**
     * @dataProvider provideLinks
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideLinks')]
    public function testCategorise(string $link, string $expected)
    {
        $this->assertSame($expected, FourOhFourLog::categorise($link), $link);
    }

    /**
     * Scanners rarely send a Referer; a real broken inbound link to an old .php URL usually
     * does. By default (ignore_only_without_referrer) a scanner hit WITH a referrer is logged.
     */
    public function testScannerHitWithReferrerIsLoggedByDefault()
    {
        $log = FourOhFourLog::logHit('index.php?page=contact', 'https://partner.example/links');

        $this->assertInstanceOf(FourOhFourLog::class, $log);
        $this->assertSame('scanner', $log->Category);
        $this->assertNull(FourOhFourLog::logHit('index.php?page=contact', 'unknown'), 'no referrer: dropped');
        $this->assertNull(FourOhFourLog::logHit('index.php?page=contact', ''), 'empty referrer: dropped');
        $this->assertCount(1, FourOhFourLog::get());
    }

    public function testScannerHitWithReferrerIsDroppedWhenTheOptionIsOff()
    {
        FourOhFourLog::config()->set('ignore_only_without_referrer', false);

        $this->assertNull(FourOhFourLog::logHit('index.php?page=contact', 'https://partner.example/links'));
        $this->assertCount(0, FourOhFourLog::get());
    }

    /**
     * The referrer exception is for scanners only: browsers and devices send probes with a
     * referrer too (the page they were on).
     */
    public function testProbeWithReferrerIsStillDropped()
    {
        $this->assertNull(FourOhFourLog::logHit('apple-touch-icon.png', 'https://partner.example/'));
        $this->assertCount(0, FourOhFourLog::get());
    }

    /**
     * ignore_patterns are the project's explicit choice and apply whatever the referrer.
     */
    public function testIgnorePatternsApplyWithAReferrerToo()
    {
        FourOhFourLog::config()->merge('ignore_patterns', ['legacy' => '~^old-site/~']);

        $this->assertNull(FourOhFourLog::logHit('old-site/page', 'https://partner.example/'));
        $this->assertCount(0, FourOhFourLog::get());
    }

    public function testScannerAndProbeHitsAreNotLoggedByDefault()
    {
        $this->assertNull(FourOhFourLog::logHit('wp-login.php', 'unknown'));
        $this->assertNull(FourOhFourLog::logHit('.well-known/passkey-endpoints', 'unknown'));

        $this->assertCount(0, FourOhFourLog::get());
    }

    public function testPageAndAssetHitsAreLoggedWithTheirCategory()
    {
        FourOhFourLog::logHit('about-us/old-page', 'unknown');
        FourOhFourLog::logHit('assets/Uploads/report.pdf', 'unknown');

        $this->assertSame(
            ['about-us/old-page' => 'page', 'assets/Uploads/report.pdf' => 'asset'],
            FourOhFourLog::get()->sort('Link')->map('Link', 'Category')->toArray()
        );
    }

    /**
     * The ignore list is keyed by category, so a project can switch a default off.
     */
    public function testIgnoreCategoriesConfigSwitchesCategoryBackOn()
    {
        FourOhFourLog::config()->merge('ignore_categories', ['probe' => false]);

        FourOhFourLog::logHit('.well-known/passkey-endpoints', 'unknown');
        FourOhFourLog::logHit('wp-login.php', 'unknown');

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame('probe', FourOhFourLog::get()->first()->Category);
    }

    public function testIgnoreCategoriesConfigCanIgnoreAssets()
    {
        FourOhFourLog::config()->merge('ignore_categories', ['asset' => true]);

        FourOhFourLog::logHit('assets/Uploads/report.pdf', 'unknown');

        $this->assertCount(0, FourOhFourLog::get());
    }

    public function testIgnorePatternsDropMatchingHits()
    {
        FourOhFourLog::config()->merge('ignore_patterns', ['legacy' => '~^old-site/~']);

        $this->assertTrue(FourOhFourLog::isIgnored('/old-site/whatever'));
        FourOhFourLog::logHit('old-site/whatever', 'unknown');
        FourOhFourLog::logHit('new-site/whatever', 'unknown');

        $this->assertSame(['new-site/whatever'], FourOhFourLog::get()->column('Link'));
    }

    /**
     * A default pattern is switched off by setting its name to null (or '') in YAML; the other
     * patterns of that category keep working.
     */
    public function testDefaultPatternCanBeSwitchedOff()
    {
        FourOhFourLog::config()->merge('category_patterns', ['scanner' => ['root_json' => null]]);

        $this->assertSame('page', FourOhFourLog::categorise('config.json'));
        $this->assertSame('scanner', FourOhFourLog::categorise('wp-login.php'));
    }

    /**
     * A project can add a category of its own, and ignore it.
     */
    public function testProjectCanAddACategory()
    {
        FourOhFourLog::config()->merge('category_patterns', ['forum' => ['old_forum' => '~^forum/~']]);

        $this->assertSame('forum', FourOhFourLog::categorise('forum/thread/1'));
        FourOhFourLog::logHit('forum/thread/1', 'unknown');
        $this->assertSame('forum', FourOhFourLog::get()->first()->Category);

        FourOhFourLog::config()->merge('ignore_categories', ['forum' => true]);
        $this->assertTrue(FourOhFourLog::isIgnored('forum/thread/2'));
    }

    /**
     * Ignoring happens before any database work: an ignored hit is dropped even when the
     * table cannot be queried at all. (The class is pointed at a table that does not exist,
     * instead of renaming the real one, which would end the test's transaction.)
     */
    public function testIgnoredHitDoesNotQueryTheDatabase()
    {
        FourOhFourLog::config()->set('table_name', 'FourOhFourLog_NoSuchTable');
        DataObject::getSchema()->reset();
        try {
            $this->assertNull(FourOhFourLog::logHit('wp-login.php', 'unknown'));

            # Control: a page hit does query, and fails on the missing table.
            $failed = false;
            try {
                FourOhFourLog::logHit('about-us/old-page', 'unknown');
            } catch (\Throwable $e) {
                $failed = true;
            }
            $this->assertTrue($failed, 'control: a logged hit queries the (missing) table');
        } finally {
            FourOhFourLog::config()->remove('table_name');
            DataObject::getSchema()->reset();
        }
    }
}

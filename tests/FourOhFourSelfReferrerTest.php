<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\FunctionalTest;

/**
 * 3.3 (issue #14): a referrer that is the requested URL itself, or the bare site root, on one of
 * the site's own hosts, counts as no referrer for ignore_only_without_referrer. Scanners send such
 * a fake Referer to look like a click.
 */
class FourOhFourSelfReferrerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    /** The site's host in these tests; the request's Host header is passed to logHit(). */
    const HOST = 'mysite.example';

    /**
     * Fake referrers that count as none, as [link, referrer, request host].
     */
    public static function provideSelfReferrers(): array
    {
        return [
            'the link itself' => ['wp-login.php', 'https://mysite.example/wp-login.php', self::HOST],
            'leading slash, case, trailing slash' => ['/WP-Login.php', 'http://mysite.example/wp-login.php/', self::HOST],
            'www alias of the request host' => ['wp-login.php', 'https://www.mysite.example/wp-login.php', self::HOST],
            'request host with www and port' => ['wp-login.php', 'https://mysite.example/wp-login.php', 'www.mysite.example:8080'],
            'with the query string, HTML-encoded as logged' => ['index.php?a=1&b=2', 'https://mysite.example/index.php?a=1&amp;b=2', self::HOST],
            'URL-encoded path' => ['.env', 'https://mysite.example/%2Eenv', self::HOST],
            'fragment ignored' => ['wp-login.php', 'https://mysite.example/wp-login.php#x', self::HOST],
            'the bare site root' => ['wp-login.php', 'https://mysite.example/', self::HOST],
            'the bare site root without slash' => ['xmlrpc.php', 'https://mysite.example', self::HOST],
        ];
    }

    /**
     * @dataProvider provideSelfReferrers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideSelfReferrers')]
    public function testFakeReferrerCountsAsNone(string $link, string $ref, string $host)
    {
        $this->assertTrue(FourOhFourLog::isSelfReferrer($link, $ref, $host));
        $this->assertNull(FourOhFourLog::logHit($link, $ref, $host), 'the scanner hit is dropped');
        $this->assertCount(0, FourOhFourLog::get());
    }

    /**
     * Real referrers, which keep a scanner-looking hit logged, as [link, referrer, request host].
     */
    public static function provideRealReferrers(): array
    {
        return [
            'another site' => ['wp-login.php', 'https://partner.example/wp-login.php', self::HOST],
            'another site, root' => ['wp-login.php', 'https://partner.example/', self::HOST],
            'a host that merely ends in the site host' => ['wp-login.php', 'https://evilmysite.example/wp-login.php', self::HOST],
            'a host that merely starts with it' => ['wp-login.php', 'https://mysite.example.evil/', self::HOST],
            'own host, another page' => ['wp-login.php', 'https://mysite.example/links', self::HOST],
            'own host, the root with a query string' => ['wp-login.php', 'https://mysite.example/?ref=1', self::HOST],
            'own host, other query string' => ['index.php?a=1', 'https://mysite.example/index.php?a=2', self::HOST],
            'no host at all' => ['wp-login.php', '/wp-login.php', self::HOST],
        ];
    }

    /**
     * @dataProvider provideRealReferrers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideRealReferrers')]
    public function testRealReferrerKeepsTheScannerHitLogged(string $link, string $ref, string $host)
    {
        $this->assertFalse(FourOhFourLog::isSelfReferrer($link, $ref, $host));
        $log = FourOhFourLog::logHit($link, $ref, $host);
        $this->assertInstanceOf(FourOhFourLog::class, $log);
        $this->assertSame('scanner', $log->Category);
    }

    public function testTheRuleCanBeSwitchedOff()
    {
        FourOhFourLog::config()->set('self_referrer_is_no_referrer', false);

        $log = FourOhFourLog::logHit('wp-login.php', 'https://mysite.example/wp-login.php', self::HOST);
        $this->assertInstanceOf(FourOhFourLog::class, $log, 'off: the fake referrer counts as a referrer, as in 3.2');
        $this->assertSame('https://mysite.example/wp-login.php', $log->Referrer);
    }

    /**
     * Only the ignore decision changes: a page or asset is logged whatever its referrer, with the
     * referrer as sent.
     */
    public function testAPageWithAFakeReferrerIsStillLogged()
    {
        $log = FourOhFourLog::logHit('about-us/old', 'https://mysite.example/about-us/old', self::HOST);

        $this->assertInstanceOf(FourOhFourLog::class, $log);
        $this->assertSame('page', $log->Category);
        $this->assertSame('https://mysite.example/about-us/old', $log->Referrer);
    }

    /**
     * The site's own hosts without a request (eg LogPurgeTask on the command line):
     * Director.alternate_base_url, SS_BASE_URL and site_hosts.
     */
    public function testOwnHostsFromConfigAndEnvironment()
    {
        $this->assertFalse(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://canonical.example/'), 'control');
        Director::config()->set('alternate_base_url', 'https://canonical.example/');
        $this->assertTrue(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://canonical.example/'));

        $this->assertFalse(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://other-domain.example/'), 'control');
        FourOhFourLog::config()->set('site_hosts', ['www.other-domain.example']);
        $this->assertTrue(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://other-domain.example/'));

        $old = Environment::getEnv('SS_BASE_URL');
        try {
            Environment::setEnv('SS_BASE_URL', 'https://env-host.example:8443/');
            $this->assertTrue(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://env-host.example/'));
        } finally {
            Environment::setEnv('SS_BASE_URL', $old);
        }
    }

    /**
     * A site in a subfolder logs links relative to that folder; the referrer's path is read the
     * same way, and the folder itself is the site root.
     */
    public function testSiteInASubfolder()
    {
        Director::config()->set('alternate_base_url', 'https://mysite.example/sub/');

        $this->assertTrue(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://mysite.example/sub/wp-login.php', self::HOST));
        $this->assertTrue(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://mysite.example/sub/', self::HOST));
        $this->assertTrue(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://mysite.example/sub', self::HOST));
        $this->assertFalse(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://mysite.example/other/wp-login.php', self::HOST), 'control');
        $this->assertFalse(FourOhFourLog::isSelfReferrer('wp-login.php', 'https://mysite.example/subway/', self::HOST), 'control: not the folder');
    }

    /**
     * Through a real request: the Host is the www alias, the scanner's Referer the bare domain.
     * FourOhFourLogger only skips a referrer whose host contains the request host, so this one
     * reaches logHit(), which must see it as fake.
     */
    public function testThroughTheLoggerWithAHostAlias()
    {
        $this->get('wp-login.php', null, [
            'Host' => 'www.mysite.example',
            'Referer' => 'https://mysite.example/wp-login.php',
        ]);
        $this->get('xmlrpc.php', null, [
            'Host' => 'www.mysite.example',
            'Referer' => 'https://mysite.example/',
        ]);
        $this->assertCount(0, FourOhFourLog::get());

        # Control: a real external referrer keeps the scanner-looking hit.
        $this->get('wp-login.php', null, [
            'Host' => 'www.mysite.example',
            'Referer' => 'https://partner.example/links',
        ]);
        $this->assertCount(1, FourOhFourLog::get());
    }
}

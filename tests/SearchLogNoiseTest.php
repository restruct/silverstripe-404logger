<?php

namespace Restruct\FourOhFourLogger\Tests;

use SearchLog;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;

/**
 * SearchLog 3.1: the index on Query, and the noise filter that drops junk queries before any
 * database work.
 */
class SearchLogNoiseTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testQueryIsIndexed()
    {
        $indexes = DataObject::getSchema()->databaseIndexes(SearchLog::class);
        $this->assertArrayHasKey('Query', $indexes);
        # Not unique: old tables can hold rows that differ only in non-ASCII case.
        $this->assertSame('index', $indexes['Query']['type']);
    }

    public static function provideNoise(): array
    {
        return [
            'single character' => ['x'],
            'year only' => ['2026'],
            'digits and signs' => ['12-34-56'],
            'quote probe' => ["65'123"],
            'trailing quote' => ["amsterdam'"],
            'sql comparison' => ['welding and 1=1'],
            'sql union' => ['x union select password'],
            'sql sleep' => ['course sleep(5)'],
            'markup' => ['<script>alert</script>'],
            'template' => ['${jndi:ldap}'],
            'brackets' => ['welding[0]'],
            'file name' => ['logo.png'],
            'script name' => ['index.php'],
        ];
    }

    /**
     * @dataProvider provideNoise
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideNoise')]
    public function testNoiseIsRecognisedAndNotLogged(string $query)
    {
        $this->assertTrue(SearchLog::isNoise($query), $query);
        $this->assertNull(SearchLog::logHit($query));
        $this->assertCount(0, SearchLog::get());
    }

    public static function provideRealQueries(): array
    {
        return [
            'two letters' => ['ab'],
            'words' => ['opening hours'],
            'apostrophe inside a word' => ["foto's"],
            'apostrophe starting a word' => ["'s-hertogenbosch"],
            'letters and digits' => ['iso 9001'],
            'non-latin script' => ['сварка'],
            'accented' => ['über été'],
            'keyword alone' => ['select'],
            'keywords in a sentence' => ['select a course from the list'],
        ];
    }

    /**
     * @dataProvider provideRealQueries
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideRealQueries')]
    public function testRealQueriesAreLogged(string $query)
    {
        $this->assertFalse(SearchLog::isNoise($query), $query);
        $this->assertInstanceOf(SearchLog::class, SearchLog::logHit($query));
        $this->assertCount(1, SearchLog::get());
    }

    public function testIgnoreNoiseOffLogsEverything()
    {
        SearchLog::config()->set('ignore_noise', false);

        SearchLog::logHit('x');
        SearchLog::logHit("65'123");

        $this->assertCount(2, SearchLog::get());
    }

    public function testMinQueryLengthIsConfigurable()
    {
        SearchLog::config()->set('min_query_length', 4);

        $this->assertTrue(SearchLog::isNoise('abc'));
        $this->assertFalse(SearchLog::isNoise('abcd'));
    }

    public function testLetterRatioCanBeSwitchedOff()
    {
        SearchLog::config()->set('min_letter_ratio', 0);

        $this->assertFalse(SearchLog::isNoise('2026'));
    }

    public function testNoisePatternCanBeSwitchedOff()
    {
        SearchLog::config()->merge('noise_patterns', ['filename' => null]);

        $this->assertFalse(SearchLog::isNoise('logo.png'));
        $this->assertTrue(SearchLog::isNoise('<b>'), 'the other patterns still apply');
    }
}

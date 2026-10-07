<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\Queries\SQLSelect;

/**
 * FourOhFourLog 3.1: indexed lookup by LinkHash, leading-slash normalisation, and the fallback
 * for rows logged before 3.1 (LinkHash NULL).
 */
class FourOhFourLogHashTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * Insert a row the way 3.0 left it: no LinkHash, no Category, timestamps as given.
     */
    public static function insertLegacyRow(string $link, string $ref, int $count, string $created = '2019-01-01 10:00:00', string $edited = '2020-01-01 10:00:00'): int
    {
        $table = DataObject::getSchema()->tableName(FourOhFourLog::class);
        SQLInsert::create("\"$table\"", [
            '"ClassName"' => FourOhFourLog::class,
            '"Created"' => $created,
            '"LastEdited"' => $edited,
            '"Link"' => $link,
            '"Referrer"' => $ref,
            '"Count"' => $count,
        ])->execute();

        return (int) DB::get_generated_id($table);
    }

    public function testSchemaHasHashAndCategoryColumns()
    {
        $columns = DB::field_list('FourOhFourLog');
        $this->assertArrayHasKey('LinkHash', $columns);
        $this->assertArrayHasKey('Category', $columns);
    }

    /**
     * The lookup column carries a UNIQUE index, so logHit() is one indexed query and two
     * concurrent first hits cannot both insert.
     */
    public function testLinkHashHasAUniqueIndex()
    {
        $indexes = DataObject::getSchema()->databaseIndexes(FourOhFourLog::class);
        $this->assertArrayHasKey('LinkHash', $indexes);
        $this->assertSame('unique', $indexes['LinkHash']['type']);
        $this->assertSame(['LinkHash'], $indexes['LinkHash']['columns']);

        # And the built table really has it, not just the declaration.
        $built = DB::get_schema()->indexList('FourOhFourLog');
        $found = false;
        foreach ($built as $spec) {
            # indexList() keys columns by their position in the index, starting at 1, and on
            # Silverstripe 6 appends the direction ('LinkHash ASC').
            $columns = array_map(function ($column) {
                return preg_replace('/ (ASC|DESC)$/', '', $column);
            }, array_values($spec['columns'] ?? []));
            if ($columns === ['LinkHash'] && ($spec['type'] ?? '') === 'unique') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'FourOhFourLog has a unique index on LinkHash in the database');
    }

    /**
     * dev/build must not fail on a site whose table holds duplicates from before 3.1: the new
     * column is NULL on every old row, and a UNIQUE index accepts any number of NULLs.
     */
    public function testUniqueIndexAcceptsManyNullHashes()
    {
        static::insertLegacyRow('/dup', 'unknown', 1);
        static::insertLegacyRow('dup', 'unknown', 1);
        static::insertLegacyRow('dup', 'unknown', 1);

        $this->assertSame(3, FourOhFourLog::get()->filter('LinkHash', null)->count());
    }

    public function testFirstHitStoresHashAndCategory()
    {
        $log = FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');

        $this->assertInstanceOf(FourOhFourLog::class, $log);
        $this->assertSame(FourOhFourLog::linkHash('missing/page', 'https://elsewhere.example/'), $log->LinkHash);
        $this->assertSame(40, strlen($log->LinkHash));
        $this->assertSame(FourOhFourLog::CATEGORY_PAGE, $log->Category);
    }

    /**
     * Regression: rows were stored as '/path' before the Silverstripe 4/5 upgrade and as 'path'
     * after, so one URL got two rows. The link is stored without leading slash now.
     */
    public function testLeadingSlashIsStrippedAndBothFormsCountOnOneRow()
    {
        FourOhFourLog::logHit('/missing/page', 'unknown');
        FourOhFourLog::logHit('missing/page', 'unknown');
        FourOhFourLog::logHit('//missing/page', 'unknown');

        $this->assertCount(1, FourOhFourLog::get());
        $log = FourOhFourLog::get()->first();
        $this->assertSame('missing/page', $log->Link);
        $this->assertSame(3, (int) $log->Count);
    }

    /**
     * The hash is case-insensitive, like the old lookup on the case-insensitive Link and Referrer
     * columns was, so switching to the hash does not split rows that used to be one.
     */
    public function testLookupStaysCaseInsensitive()
    {
        FourOhFourLog::logHit('Missing/Page', 'https://Elsewhere.example/');
        FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame(2, (int) FourOhFourLog::get()->first()->Count);
        $this->assertSame('Missing/Page', FourOhFourLog::get()->first()->Link, 'first-seen spelling kept');
    }

    public function testEmptyLinkIsNotLogged()
    {
        $this->assertNull(FourOhFourLog::logHit('/', 'unknown'));
        $this->assertNull(FourOhFourLog::logHit('', 'unknown'));
        $this->assertCount(0, FourOhFourLog::get());
    }

    /**
     * A row from before 3.1 (no hash, stored with a leading slash) is still found, counted, and
     * given its hash and normalised link, so the next hit uses the index.
     */
    public function testLegacyRowIsFoundCountedAndBackfilled()
    {
        $id = static::insertLegacyRow('/old/page', 'https://elsewhere.example/', 7);

        $log = FourOhFourLog::logHit('old/page', 'https://elsewhere.example/');

        $this->assertSame($id, (int) $log->ID, 'the legacy row is reused');
        $this->assertCount(1, FourOhFourLog::get());
        $row = FourOhFourLog::get()->byID($id);
        $this->assertSame(8, (int) $row->Count);
        $this->assertSame('old/page', $row->Link);
        $this->assertSame(FourOhFourLog::linkHash('old/page', 'https://elsewhere.example/'), $row->LinkHash);
        $this->assertSame(FourOhFourLog::CATEGORY_PAGE, $row->Category);

        # The next hit goes through the hash.
        FourOhFourLog::logHit('/old/page', 'https://elsewhere.example/');
        $this->assertSame(9, (int) FourOhFourLog::get()->byID($id)->Count);
        $this->assertCount(1, FourOhFourLog::get());
    }

    /**
     * Legacy rows stored without slash (3.0 on Silverstripe 5) are found by the fallback too.
     */
    public function testLegacyRowWithoutSlashIsFound()
    {
        $id = static::insertLegacyRow('old/page', 'unknown', 2);

        FourOhFourLog::logHit('/old/page', 'unknown');

        $this->assertCount(1, FourOhFourLog::get());
        $this->assertSame(3, (int) FourOhFourLog::get()->byID($id)->Count);
    }

    /**
     * The fallback only matches rows with the same referrer, like the old lookup.
     */
    public function testLegacyRowFromAnotherReferrerIsNotReused()
    {
        static::insertLegacyRow('/old/page', 'https://one.example/', 2);

        FourOhFourLog::logHit('old/page', 'https://two.example/');

        $this->assertCount(2, FourOhFourLog::get());
    }

    /**
     * Long links: the legacy row was truncated WITH its slash at 2048 characters; the fallback
     * rebuilds that form from the full link, so the row is still found.
     */
    public function testLongLegacyRowWithSlashIsFound()
    {
        $link = 'missing/' . str_repeat('a', 3000);
        $id = static::insertLegacyRow(mb_substr('/' . $link, 0, 2048), 'unknown', 4);

        FourOhFourLog::logHit($link, 'unknown');

        $this->assertCount(1, FourOhFourLog::get());
        $row = FourOhFourLog::get()->byID($id);
        $this->assertSame(5, (int) $row->Count);
        $this->assertSame(2047, mb_strlen($row->Link), 'normalised: the stored slash is dropped');
    }

    /**
     * A hit counted on an existing row leaves its Created alone (first seen) and moves
     * LastEdited (most recent hit).
     */
    public function testRepeatHitKeepsCreated()
    {
        $id = static::insertLegacyRow('/old/page', 'unknown', 1, '2019-05-05 05:05:05', '2019-06-06 06:06:06');

        FourOhFourLog::logHit('old/page', 'unknown');

        $row = SQLSelect::create(['"Created"', '"LastEdited"'], '"FourOhFourLog"', ['"ID"' => $id])->execute()->record();
        $this->assertSame('2019-05-05 05:05:05', $row['Created']);
        $this->assertNotSame('2019-06-06 06:06:06', $row['LastEdited']);
    }
}

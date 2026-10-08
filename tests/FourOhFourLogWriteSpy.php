<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * TEST ONLY: watches FourOhFourLog inserts, and can stage the race logHit() guards against.
 * Applied through FourOhFourLogWritePathTest::$required_extensions only.
 */
class FourOhFourLogWriteSpy extends Extension implements TestOnly
{
    /** Number of INSERTs attempted (writes of a record not yet in the database). */
    public static int $inserts = 0;

    /**
     * When set, the next insert first raw-inserts a row with the same LinkHash and this Count,
     * as a concurrent request would between logHit()'s lookup and its insert.
     */
    public static ?int $raceWithCount = null;

    /**
     * When set, the next update of an existing row first raw-adds this many hits to its Count,
     * as concurrent requests counting on the same row would between logHit()'s lookup and its
     * write.
     */
    public static ?int $concurrentHits = null;

    public static function reset(): void
    {
        static::$inserts = 0;
        static::$raceWithCount = null;
        static::$concurrentHits = null;
    }

    public function onBeforeWrite()
    {
        $owner = $this->getOwner();
        if ($owner->isInDB()) {
            if (static::$concurrentHits !== null) {
                $hits = static::$concurrentHits;
                static::$concurrentHits = null;
                $table = DataObject::getSchema()->tableName(FourOhFourLog::class);
                SQLUpdate::create("\"$table\"", ['"Count"' => ['"Count" + ?' => [$hits]]], ['"ID"' => $owner->ID])->execute();
            }
            return;
        }
        static::$inserts++;

        if (static::$raceWithCount !== null) {
            $count = static::$raceWithCount;
            static::$raceWithCount = null;
            $table = DataObject::getSchema()->tableName(FourOhFourLog::class);
            SQLInsert::create("\"$table\"", [
                '"ClassName"' => FourOhFourLog::class,
                '"Created"' => '2020-01-01 00:00:00',
                '"LastEdited"' => '2020-01-01 00:00:00',
                '"Link"' => $owner->Link,
                '"Referrer"' => $owner->Referrer,
                '"LinkHash"' => $owner->LinkHash,
                '"Count"' => $count,
            ])->execute();
        }
    }
}

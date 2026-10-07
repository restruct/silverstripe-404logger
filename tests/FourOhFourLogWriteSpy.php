<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLInsert;

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

    public static function reset(): void
    {
        static::$inserts = 0;
        static::$raceWithCount = null;
    }

    public function onBeforeWrite()
    {
        $owner = $this->getOwner();
        if ($owner->isInDB()) {
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

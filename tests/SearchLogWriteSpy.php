<?php

namespace Restruct\FourOhFourLogger\Tests;

use SearchLog;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * TEST ONLY: stages concurrent hits on a SearchLog row between logHit()'s lookup and its write.
 * Applied through SearchLogWritePathTest::$required_extensions only.
 */
class SearchLogWriteSpy extends Extension implements TestOnly
{
    /**
     * When set, the next update of an existing row first raw-adds this many hits to its Count,
     * as concurrent requests counting the same query would.
     */
    public static ?int $concurrentHits = null;

    public static function reset(): void
    {
        static::$concurrentHits = null;
    }

    public function onBeforeWrite()
    {
        $owner = $this->getOwner();
        if (!$owner->isInDB() || static::$concurrentHits === null) {
            return;
        }
        $hits = static::$concurrentHits;
        static::$concurrentHits = null;
        $table = DataObject::getSchema()->tableName(SearchLog::class);
        SQLUpdate::create("\"$table\"", ['"Count"' => ['"Count" + ?' => [$hits]]], ['"ID"' => $owner->ID])->execute();
    }
}

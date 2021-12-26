<?php

use SilverStripe\ORM\DataObject;

/**
 * Logs one 404 request
 */
class SearchLog
    extends DataObject
{
    private static $singular_name = 'Search Log';

    private static $db = array(
        'Query' => 'Varchar(255)',
        'Count' => 'Int',
    );

    private static $summary_fields = array(
        'Query' => 'Search query',
        'Count' => 'Hits',
    );

    private static $searchable_fields = array(
        'Query',
    );

    public static function logHit($query)
    {
        // create or update log
        $existing = SearchLog::get()->filter(
                array(
                    'Query' => $query
                    ))->first();
        if ($existing) {
            $existing->Count = $existing->Count+1;
            $existing->write();
        } else {
            $log = SearchLog::create();
            $log->Query = strtolower($query);
            $log->Count = 1;
            $log->write();
        }
    }

    public function canView($member = null)
    {
        return true;
    }

    public function canCreate($member = null, $context = [])
    {
        return true;
    }

    public function canEdit($member = null)
    {
        return true;
    }

    public function canDelete($member = null)
    {
        return true;
    }
}

<?php

use SilverStripe\ORM\DataObject;

/**
 * Logs one 404 request
 */
class FourOhFourLog
    extends DataObject
{
    private static $singular_name = '404 Log';

    private static $db = array(
        'Referrer' => 'Varchar(2048)',
        'Link' => 'Varchar(2048)',
        'Count' => 'Int',
    );

    private static $summary_fields = array(
        'Referrer' => 'Referrer',
        'Link' => 'Incoming link',
        'Count' => 'Hits',
    );

    private static $searchable_fields = array(
        'Referrer',
        'Link',
    );

    public static function logHit($link, $ref)
    {
        // create or update log
        $existing = FourOhFourLog::get()->filter(
                array(
                    'Referrer' => $ref,
                    'Link' => $link
                    ))->first();
        if ($existing) {
            $existing->Count = $existing->Count+1;
            $existing->write();
        } else {
            $log = FourOhFourLog::create();
            $log->Referrer = $ref;
            $log->Link = $link;
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

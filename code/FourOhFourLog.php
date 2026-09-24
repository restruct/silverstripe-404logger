<?php

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

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
        # Fit both values to their column BEFORE the lookup. Silverstripe 6 validates Varchar
        # length on write and throws, which turned a long 404 URL into a 500. On Silverstripe 5
        # MySQL (ANSI mode) silently truncated the stored value instead, so the lookup with the
        # full value never matched the stored row again and every hit created a new row.
        $link = static::fitToField('Link', (string) $link);
        $ref = static::fitToField('Referrer', (string) $ref);

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

    /**
     * Truncate a value to the size of the given Varchar field on this class.
     *
     * @param string $fieldName
     * @param string $value
     * @return string
     */
    protected static function fitToField($fieldName, $value)
    {
        $size = (int) static::singleton()->dbObject($fieldName)->getSize();
        return $size > 0 ? mb_substr($value, 0, $size) : $value;
    }

    public function canView($member = null)
    {
        # Report data (visited URLs, referrers, search terms) is for CMS users with report
        # access only; it used to be open to everyone. logHit() writes without a member and
        # does not go through this check (DataObject::write() does not call canCreate()).
//        return true;
        return Permission::check('CMS_ACCESS_ReportAdmin', 'any', $member);
    }

    public function canCreate($member = null, $context = [])
    {
        # Report data (visited URLs, referrers, search terms) is for CMS users with report
        # access only; it used to be open to everyone. logHit() writes without a member and
        # does not go through this check (DataObject::write() does not call canCreate()).
//        return true;
        return Permission::check('CMS_ACCESS_ReportAdmin', 'any', $member);
    }

    public function canEdit($member = null)
    {
        # Report data (visited URLs, referrers, search terms) is for CMS users with report
        # access only; it used to be open to everyone. logHit() writes without a member and
        # does not go through this check (DataObject::write() does not call canCreate()).
//        return true;
        return Permission::check('CMS_ACCESS_ReportAdmin', 'any', $member);
    }

    public function canDelete($member = null)
    {
        # Report data (visited URLs, referrers, search terms) is for CMS users with report
        # access only; it used to be open to everyone. logHit() writes without a member and
        # does not go through this check (DataObject::write() does not call canCreate()).
//        return true;
        return Permission::check('CMS_ACCESS_ReportAdmin', 'any', $member);
    }
}

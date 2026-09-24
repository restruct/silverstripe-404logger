<?php

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Logs one 404 request
 */
class SearchLog
    extends DataObject
{
    private static $singular_name = 'Search Log';
    
    private static $default_sort = 'LastEdited DESC';

    /** @config string name of search query param to log (default 'Search') */
    private static $search_query_param = 'Search';

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
        # Normalise BEFORE the lookup, so the lookup matches what is stored: queries are stored
        # lower-cased, and must fit Varchar(255) - Silverstripe 6 throws on an over-long value
        # (a 500 on the search page), Silverstripe 5 truncated it silently so the lookup with the
        # full value never matched the stored row and every hit created a new row.
        # mb_strtolower rather than strtolower, which lower-cases ASCII only.
        $query = mb_strtolower(trim((string) $query));
        $size = (int) static::singleton()->dbObject('Query')->getSize();
        if ($size > 0) {
            $query = mb_substr($query, 0, $size);
        }

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
//            $log->Query = strtolower($query);
            # already normalised above
            $log->Query = $query;
            $log->Count = 1;
            $log->write();
        }
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

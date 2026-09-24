<?php

namespace Restruct\FourOhFourLogger\Tests;

use FourOhFourLog;
use SearchLog;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;

/**
 * FourOhFourLog and SearchLog: viewing and managing the logs needs CMS_ACCESS_ReportAdmin,
 * while logging itself (which runs for anonymous visitors) keeps working.
 */
class PermissionsTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * Both log classes, each with a row to check the instance-level permissions against.
     *
     * @return DataObject[]
     */
    private function records(): array
    {
        FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');
        SearchLog::logHit('needle');
        return [FourOhFourLog::get()->first(), SearchLog::get()->first()];
    }

    public function testAnonymousIsDenied()
    {
        $this->logOut();
        foreach ($this->records() as $record) {
            $class = get_class($record);
            $this->assertFalse($record->canView(), "anonymous cannot view $class");
            $this->assertFalse($record->canEdit(), "anonymous cannot edit $class");
            $this->assertFalse($record->canDelete(), "anonymous cannot delete $class");
            $this->assertFalse($record->canCreate(), "anonymous cannot create $class");
        }
    }

    public function testMemberWithoutReportAccessIsDenied()
    {
        $member = $this->createMemberWithPermission('CMS_ACCESS_CMSMain');
        foreach ($this->records() as $record) {
            $class = get_class($record);
            $this->assertFalse($record->canView($member), "no report access: cannot view $class");
            $this->assertFalse($record->canEdit($member), "no report access: cannot edit $class");
            $this->assertFalse($record->canDelete($member), "no report access: cannot delete $class");
            $this->assertFalse($record->canCreate($member), "no report access: cannot create $class");
        }
    }

    public function testMemberWithReportAccessIsAllowed()
    {
        $member = $this->createMemberWithPermission('CMS_ACCESS_ReportAdmin');
        foreach ($this->records() as $record) {
            $class = get_class($record);
            $this->assertTrue($record->canView($member), "report access: can view $class");
            $this->assertTrue($record->canEdit($member), "report access: can edit $class");
            $this->assertTrue($record->canDelete($member), "report access: can delete $class");
            $this->assertTrue($record->canCreate($member), "report access: can create $class");
        }
    }

    /**
     * The current user is used when no member is passed, as in the CMS.
     */
    public function testLoggedInMemberWithReportAccessIsAllowed()
    {
        $this->logInWithPermission('CMS_ACCESS_ReportAdmin');
        foreach ($this->records() as $record) {
            $this->assertTrue($record->canView(), 'logged-in report user can view ' . get_class($record));
        }
    }

    /**
     * logHit() runs for anonymous visitors: it must not depend on canCreate()/canEdit().
     */
    public function testAnonymousHitsAreStillLoggedAndCounted()
    {
        $this->logOut();
        FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');
        FourOhFourLog::logHit('missing/page', 'https://elsewhere.example/');
        SearchLog::logHit('needle');
        SearchLog::logHit('needle');

        $this->assertSame(2, (int) FourOhFourLog::get()->first()->Count);
        $this->assertSame(2, (int) SearchLog::get()->first()->Count);
    }
}

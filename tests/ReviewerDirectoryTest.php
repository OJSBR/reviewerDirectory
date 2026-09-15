<?php

/**
 * @file plugins/generic/reviewerDirectory/tests/ReviewerDirectoryTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerDirectoryTest
 *
 * @brief Who may open the directory, the roster grouping, dates, assets and the
 *        queries of the page.
 */

namespace APP\plugins\generic\reviewerDirectory\tests;

use APP\plugins\generic\reviewerDirectory\ReviewerDirectoryHandler;
use APP\plugins\generic\reviewerDirectory\ReviewerDirectoryPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\security\Role;
use PKP\tests\PKPTestCase;

#[CoversClass(ReviewerDirectoryPlugin::class)]
#[CoversClass(ReviewerDirectoryHandler::class)]
class ReviewerDirectoryTest extends PKPTestCase
{
    public function testOnlyManagersSectionEditorsAndAdministratorsOpenThePage(): void
    {
        $this->assertSame([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_SITE_ADMIN], ReviewerDirectoryPlugin::ALLOWED_ROLES);

        $handler = new ReviewerDirectoryHandler(new ReviewerDirectoryPlugin());
        $assigned = $handler->getRoleAssignments();
        ksort($assigned);
        $expected = array_fill_keys(ReviewerDirectoryPlugin::ALLOWED_ROLES, ['index']);
        ksort($expected);
        $this->assertSame($expected, $assigned);
        $this->assertArrayNotHasKey(Role::ROLE_ID_REVIEWER, $assigned);
        $this->assertArrayNotHasKey(Role::ROLE_ID_AUTHOR, $assigned);
    }

    public function testTheRosterGroupsCompletedReviewsByReviewer(): void
    {
        $rows = [
            (object) ['reviewer_id' => '7', 'submission_id' => '30', 'date_completed' => '2026-03-10 12:00:00'],
            (object) ['reviewer_id' => '7', 'submission_id' => '12', 'date_completed' => '2026-01-05 08:00:00'],
            // A second round of the same submission counts as a review, not as a submission.
            (object) ['reviewer_id' => '7', 'submission_id' => '30', 'date_completed' => '2026-05-20 09:30:00'],
            (object) ['reviewer_id' => '9', 'submission_id' => '12', 'date_completed' => '2026-02-01 00:00:00'],
        ];

        $this->assertSame([
            7 => ['count' => 3, 'submissions' => [12, 30], 'firstDate' => '2026-01-05', 'lastDate' => '2026-05-20'],
            9 => ['count' => 1, 'submissions' => [12], 'firstDate' => '2026-02-01', 'lastDate' => '2026-02-01'],
        ], ReviewerDirectoryHandler::groupCompletedReviews($rows));
        $this->assertSame([], ReviewerDirectoryHandler::groupCompletedReviews([]));
    }

    public function testOnlyRealDatesReachTheQueries(): void
    {
        $this->assertSame('2026-02-28', ReviewerDirectoryHandler::sanitizeDate(' 2026-02-28 '));
        $this->assertSame('', ReviewerDirectoryHandler::sanitizeDate('2026-02-30'));
        $this->assertSame('', ReviewerDirectoryHandler::sanitizeDate("2026-01-01' OR 1=1"));
        $this->assertSame('', ReviewerDirectoryHandler::sanitizeDate('01/02/2026'));
        $this->assertSame('', ReviewerDirectoryHandler::sanitizeDate(null));
    }

    public function testEveryColumnHasALabelAndAKnownSort(): void
    {
        $handler = new ReviewerDirectoryHandler(new ReviewerDirectoryPlugin());
        $columns = $handler->getColumns();
        $this->assertSame(
            ['affiliation', 'country', 'orcid', 'username', 'email', 'interests', 'completed', 'active', 'declined', 'average', 'rating', 'lastAssigned', 'lastCompleted'],
            array_column($columns, 'key')
        );
        foreach ($columns as $column) {
            $this->assertContains($column['sort'], ['text', 'num']);
            $this->assertIsBool($column['default']);
        }
        // Personal data is hidden until the editor shows it.
        foreach (['email', 'username', 'orcid'] as $key) {
            $this->assertFalse($columns[array_search($key, array_column($columns, 'key'))]['default'], "{$key} is shown by default.");
        }
    }

    public function testStylesAndScriptsAreStaticFiles(): void
    {
        $root = dirname(__DIR__);
        $handler = (string) file_get_contents($root . '/ReviewerDirectoryHandler.php');
        $this->assertStringNotContainsString("'inline' => true", $handler);
        $this->assertStringNotContainsString('<<<', $handler);
        $this->assertFileExists($root . '/css/reviewerDirectory.css');
        $this->assertFileExists($root . '/js/reviewerDirectory.js');
        $this->assertStringNotContainsString('style=', (string) file_get_contents($root . '/templates/directory.tpl'));
    }

    public function testTheQueriesStartFromTheCoreCollectors(): void
    {
        $handler = (string) file_get_contents(dirname(__DIR__) . '/ReviewerDirectoryHandler.php');
        $this->assertStringNotContainsString("DB::table(", $handler);
        $this->assertStringContainsString('Repo::reviewAssignment()->getCollector()', $handler);
        $this->assertStringContainsString('->filterByContextIds([$contextId])', $handler);
    }
}

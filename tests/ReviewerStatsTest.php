<?php

/**
 * @file plugins/generic/reviewerDirectory/tests/ReviewerStatsTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerStatsTest
 *
 * @brief The directory against the database of the installation (read only): the
 *        reviewers are the core's, the numbers OJS shows when choosing a reviewer are the
 *        core's own, the numbers of the journal match independent counts, the situations
 *        add up and the roster lists who completed reviews in the period.
 */

namespace APP\plugins\generic\reviewerDirectory\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\reviewerDirectory\classes\ReviewerStats;
use APP\plugins\generic\reviewerDirectory\classes\Roster;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\context\Context;
use PKP\core\Dispatcher;
use PKP\core\PKPRequest;
use PKP\core\Registry;
use PKP\security\Role;
use PKP\submission\PKPSubmission;
use PKP\tests\PKPTestCase;

#[CoversClass(ReviewerStats::class)]
#[CoversClass(Roster::class)]
class ReviewerStatsTest extends PKPTestCase
{
    protected function getMockedRegistryKeys(): array
    {
        return [...parent::getMockedRegistryKeys(), 'request', 'user'];
    }

    /** A page request of the journal or press, as the directory runs. */
    protected function request(Context $context): PKPRequest
    {
        Registry::delete('request');
        Registry::delete('user');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PATH_INFO'] = '/' . $context->getPath() . '/reviewerdirectory';
        $application = Application::get();
        $request = $application->getRequest();
        $router = new PageRouter();
        $router->setApplication($application);
        $dispatcher = new Dispatcher();
        $dispatcher->addRouterName('\APP\core\PageRouter', Application::ROUTE_PAGE);
        $dispatcher->setApplication($application);
        $router->setDispatcher($dispatcher);
        $request->setRouter($router);
        return $request;
    }

    /** @return Context[] */
    protected function contexts(): array
    {
        $contexts = iterator_to_array(Application::getContextDAO()->getAll(true)->toIterator());
        if (!$contexts) {
            $this->markTestSkipped('No journal or press in this database.');
        }
        return $contexts;
    }

    public function testTheReviewersAndTheirOjsNumbersAreTheCores(): void
    {
        foreach ($this->contexts() as $context) {
            $stats = new ReviewerStats($context, $this->request($context));
            $reviewers = $stats->getReviewers();

            $core = [];
            foreach (Repo::user()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterByRoleIds([Role::ROLE_ID_REVIEWER])
                ->includeReviewerData()
                ->limit(300)
                ->getMany() as $user) {
                $core[(int) $user->getId()] = $user;
            }
            $this->assertSame(
                Repo::user()->getCollector()->filterByContextIds([$context->getId()])->filterByRoleIds([Role::ROLE_ID_REVIEWER])->getCount(),
                count($reviewers),
                $context->getPath()
            );
            foreach ($core as $id => $user) {
                $this->assertArrayHasKey($id, $reviewers);
                $r = $reviewers[$id];
                $this->assertSame($user->getFullName(), $r['name']);
                $this->assertSame((string) $user->getEmail(), $r['email']);
                $this->assertSame((string) $user->getLocalizedAffiliation(), $r['affiliation']);
                $this->assertSame((string) $user->getOrcid(), $r['orcid']);
                $this->assertSame((int) $user->getData('completeCount'), $r['ojsCompleted'], "{$context->getPath()}/{$id}");
                $this->assertSame((int) $user->getData('declinedCount'), $r['ojsDeclined']);
                $this->assertSame((int) $user->getData('averageTime'), (int) $r['ojsAverageDays']);
                $this->assertSame($user->getData('reviewerRating') ? (int) $user->getData('reviewerRating') : null, $r['rating']);
                $this->assertSame(substr((string) $user->getData('lastAssigned'), 0, 10), $r['lastAssigned']);
            }
        }
    }

    public function testTheNumbersOfTheJournalMatchIndependentCounts(): void
    {
        foreach ($this->contexts() as $context) {
            $reviewers = (new ReviewerStats($context, $this->request($context)))->getReviewers();
            $assignments = fn () => DB::table('review_assignments AS ra')
                ->join('submissions AS s', 's.submission_id', '=', 'ra.submission_id')
                ->where('s.context_id', $context->getId())
                ->where('ra.cancelled', 0);
            foreach (array_slice($reviewers, 0, 200, true) as $id => $r) {
                $mine = fn () => $assignments()->where('ra.reviewer_id', $id);
                $this->assertSame($mine()->count(), $r['requests'], "requests {$id}");
                $this->assertSame($mine()->where('ra.declined', 0)->whereNotNull('ra.date_completed')->count(), $r['completed'], "completed {$id}");
                $this->assertSame($mine()->where('ra.declined', 1)->count(), $r['declined'], "declined {$id}");
                // In progress: the last round of the stage the submission is in, submission still in the workflow.
                $open = fn () => $mine()->where('ra.declined', 0)->whereNotNull('ra.date_notified')->whereNull('ra.date_completed')
                    ->where('s.status', PKPSubmission::STATUS_QUEUED)
                    ->whereColumn('s.stage_id', 'ra.stage_id')
                    ->whereRaw('ra.round = (SELECT MAX(rr.round) FROM review_rounds rr WHERE rr.submission_id = ra.submission_id AND rr.stage_id = ra.stage_id)');
                $active = $open()->distinct()->pluck('ra.submission_id')->map(intval(...))->sort()->values()->all();
                $this->assertSame($active, array_column($r['activeSubmissions'], 'id'), "active {$id}");
                $this->assertSame(count($active), $r['active']);
                $tomorrow = date('Y-m-d 00:00:00', strtotime('tomorrow'));
                $this->assertSame(
                    $open()->where(fn ($q) => $q->where('ra.date_due', '<', $tomorrow)->orWhere(fn ($q) => $q->whereNull('ra.date_confirmed')->where('ra.date_response_due', '<', $tomorrow)))->count(),
                    $r['overdue'],
                    "overdue {$id}"
                );
                $this->assertSame(
                    substr((string) $mine()->where('ra.declined', 0)->max('ra.date_completed'), 0, 10),
                    $r['lastCompleted'],
                    "last completed {$id}"
                );
                $this->assertLessThanOrEqual($r['active'] ? PHP_INT_MAX : 0, $r['overdue']);
                $this->assertSame(ReviewerStats::situationOf($r), $r['situation']);
            }

            $situations = array_count_values(array_column($reviewers, 'situation'));
            $this->assertSame(count($reviewers), array_sum($situations), 'The situations add up.');
        }
    }

    public function testTheRosterListsWhoCompletedReviewsInThePeriod(): void
    {
        foreach ($this->contexts() as $context) {
            $roster = (new Roster($context))->get('2000-01-01', date('Y-m-d'), 0);
            $expected = DB::table('review_assignments AS ra')
                ->join('submissions AS s', 's.submission_id', '=', 'ra.submission_id')
                ->where('s.context_id', $context->getId())
                ->where('ra.declined', 0)
                ->whereNotNull('ra.date_completed')
                ->where('ra.date_completed', '>=', '2000-01-01 00:00:00')
                ->where('ra.date_completed', '<=', date('Y-m-d') . ' 23:59:59');
            $this->assertSame((clone $expected)->count(), $roster['reviews'], $context->getPath());
            $this->assertSame((clone $expected)->distinct()->count('ra.reviewer_id'), $roster['reviewers']);
            $this->assertSame((clone $expected)->distinct()->count('ra.submission_id'), $roster['submissions']);
            $this->assertSame($roster['reviewers'], count($roster['rows']));

            // Nothing outside the period.
            $this->assertSame(0, (new Roster($context))->get('1990-01-01', '1990-12-31', 0)['reviewers']);
        }
    }
}

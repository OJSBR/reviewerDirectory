<?php

/**
 * @file plugins/generic/reviewerDirectory/ReviewerDirectoryHandler.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerDirectoryHandler
 *
 * @brief The backend page with the reviewer directory and the reviewer roster
 *  (reviewers who completed reviews in a period or issue).
 */

namespace APP\plugins\generic\reviewerDirectory;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\template\TemplateManager;
use Illuminate\Support\Str;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class ReviewerDirectoryHandler extends Handler
{
    /** @copydoc PKPHandler::_isBackendPage */
    public $_isBackendPage = true;

    /** @var ReviewerDirectoryPlugin */
    protected $plugin;

    public function __construct(ReviewerDirectoryPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->addRoleAssignment(ReviewerDirectoryPlugin::ALLOWED_ROLES, ['index']);
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * The directory, and the roster when its form was sent.
     */
    public function index($args, $request)
    {
        $contextId = (int) $request->getContext()->getId();
        $templateMgr = TemplateManager::getManager($request);
        $this->setupTemplate($request);

        $columns = $this->getColumns();
        $reviewers = $this->getReviewers($request, $contextId);

        $dateFrom = self::sanitizeDate($request->getUserVar('rdDateFrom'));
        $dateTo = self::sanitizeDate($request->getUserVar('rdDateTo'));
        $issueId = (int) $request->getUserVar('rdIssueId');
        $nominataRequested = (bool) $request->getUserVar('rdNominata');

        $templateMgr->assign([
            'pageComponent' => 'Page',
            'pageTitle' => __('plugins.generic.reviewerDirectory.displayName'),
            'reviewers' => $reviewers,
            'reviewerCount' => count($reviewers),
            'columns' => $columns,
            'issues' => $this->getIssues($contextId),
            'directoryUrl' => $this->plugin->getDirectoryUrl($request),
            'nominataRequested' => $nominataRequested,
            'nominata' => $nominataRequested ? $this->getNominata($contextId, $dateFrom, $dateTo, $issueId) : null,
            'rdDateFrom' => $dateFrom,
            'rdDateTo' => $dateTo,
            'rdIssueId' => $issueId,
        ]);

        $assetsUrl = $request->getBaseUrl() . '/' . $this->plugin->getPluginPath();
        $templateMgr->addStyleSheet('reviewerDirectory', $assetsUrl . '/css/reviewerDirectory.css', ['contexts' => 'backend']);
        $templateMgr->addJavaScript('reviewerDirectory', $assetsUrl . '/js/reviewerDirectory.js', ['contexts' => 'backend']);

        $templateMgr->display($this->plugin->getTemplateResource('directory.tpl'));
    }

    /**
     * The columns that can be shown or hidden (the name is always shown).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getColumns(): array
    {
        $p = 'plugins.generic.reviewerDirectory.';
        return [
            ['key' => 'affiliation', 'label' => __('user.affiliation'), 'sort' => 'text', 'default' => false],
            ['key' => 'country', 'label' => __('common.country'), 'sort' => 'text', 'default' => false],
            ['key' => 'orcid', 'label' => __('user.orcid'), 'sort' => 'text', 'default' => false],
            ['key' => 'username', 'label' => __('user.username'), 'sort' => 'text', 'default' => false],
            ['key' => 'email', 'label' => __('user.email'), 'sort' => 'text', 'default' => false],
            ['key' => 'interests', 'label' => __('user.interests'), 'sort' => 'text', 'default' => true],
            ['key' => 'completed', 'label' => __($p . 'completedShort'), 'title' => __($p . 'reviewsCompleted'), 'sort' => 'num', 'default' => true],
            ['key' => 'active', 'label' => __($p . 'activeShort'), 'title' => __($p . 'reviewsActive'), 'sort' => 'num', 'default' => true],
            ['key' => 'declined', 'label' => __($p . 'declinedShort'), 'title' => __($p . 'reviewsDeclined'), 'sort' => 'num', 'default' => true],
            ['key' => 'average', 'label' => __($p . 'averageShort'), 'title' => __($p . 'averageDays'), 'sort' => 'num', 'default' => true],
            ['key' => 'rating', 'label' => __($p . 'ratingShort'), 'title' => __($p . 'rating'), 'sort' => 'num', 'default' => true],
            ['key' => 'lastAssigned', 'label' => __($p . 'lastAssigned'), 'sort' => 'text', 'default' => true],
            ['key' => 'lastCompleted', 'label' => __($p . 'lastCompleted'), 'sort' => 'text', 'default' => true],
        ];
    }

    /**
     * The reviewers of the journal with their profile, statistics and active reviews.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getReviewers($request, int $contextId): array
    {
        $collector = Repo::user()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByRoleIds([Role::ROLE_ID_REVIEWER])
            ->includeReviewerData();

        $userIds = $collector->getIds()->toArray();
        $interestsByUser = $userIds ? Repo::user()->preloadInterests($userIds) : [];
        $activeByUser = $this->getActiveSubmissionsByReviewer($request, $contextId, $userIds);
        $lastCompletedByUser = $this->getLastCompletedByReviewer($contextId, $userIds);

        $reviewers = [];
        foreach ($collector->getMany() as $user) {
            $id = (int) $user->getId();
            $interests = array_values(array_filter(array_map(
                fn ($interest) => is_array($interest) ? ($interest['interest'] ?? null) : $interest,
                $interestsByUser[$id] ?? []
            )));
            $activeSubs = $activeByUser[$id] ?? [];
            $lastAssigned = (string) $user->getData('lastAssigned');

            $reviewers[] = [
                'id' => $id,
                'fullName' => $user->getFullName(),
                'affiliation' => (string) $user->getLocalizedAffiliation(),
                'country' => (string) $user->getCountryLocalized(),
                'email' => (string) $user->getEmail(),
                'username' => (string) $user->getUsername(),
                'orcid' => (string) $user->getOrcid(),
                'orcidVerified' => (bool) $user->hasVerifiedOrcid(),
                'interests' => $interests,
                'interestsString' => implode(', ', $interests),
                'reviewsCompleted' => (int) $user->getData('completeCount'),
                'reviewsDeclined' => (int) $user->getData('declinedCount'),
                'averageDays' => (int) $user->getData('averageTime'),
                'rating' => $user->getData('reviewerRating') ? (int) $user->getData('reviewerRating') : null,
                'lastAssigned' => substr($lastAssigned, 0, 10),
                'lastCompleted' => substr((string) ($lastCompletedByUser[$id] ?? ''), 0, 10),
                'activeCount' => count($activeSubs),
                'activeSubs' => $activeSubs,
                'activeIdsString' => implode(' ', array_map(fn ($sub) => '#' . $sub['id'], $activeSubs)),
            ];
        }

        usort($reviewers, fn ($a, $b) => strcoll(Str::lower($a['fullName']), Str::lower($b['fullName'])));

        return $reviewers;
    }

    /**
     * The review assignments of the journal, from the core collector.
     */
    protected function reviewAssignments(int $contextId, ?array $reviewerIds = null)
    {
        return Repo::reviewAssignment()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByReviewerIds($reviewerIds)
            ->getQueryBuilder();
    }

    /**
     * For each reviewer, the submissions with an active review: notified, and not completed,
     * declined or cancelled.
     *
     * @return array<int, array<int, array{id: int, url: string}>>
     */
    protected function getActiveSubmissionsByReviewer($request, int $contextId, array $userIds): array
    {
        if (!$userIds) {
            return [];
        }

        $rows = $this->reviewAssignments($contextId, $userIds)
            ->whereNotNull('ra.date_notified')
            ->whereNull('ra.date_completed')
            ->where('ra.declined', '<>', 1)
            ->where('ra.cancelled', '<>', 1)
            ->orderBy('ra.submission_id')
            ->get(['ra.reviewer_id', 'ra.submission_id']);

        $dispatcher = $request->getDispatcher();
        $map = [];
        foreach ($rows as $row) {
            $submissionId = (int) $row->submission_id;
            $map[(int) $row->reviewer_id][$submissionId] = [
                'id' => $submissionId,
                'url' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['workflowSubmissionId' => $submissionId]),
            ];
        }

        // One entry per submission, whatever the number of rounds.
        return array_map('array_values', $map);
    }

    /**
     * The date of the last review each reviewer completed in the journal.
     *
     * @return array<int, string> [reviewerId => 'YYYY-MM-DD HH:MM:SS']
     */
    protected function getLastCompletedByReviewer(int $contextId, array $userIds): array
    {
        if (!$userIds) {
            return [];
        }

        return $this->reviewAssignments($contextId, $userIds)
            ->whereNotNull('ra.date_completed')
            ->where('ra.declined', '<>', 1)
            ->groupBy('ra.reviewer_id')
            ->selectRaw('ra.reviewer_id, MAX(ra.date_completed) as last_completed')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->reviewer_id => (string) $row->last_completed])
            ->all();
    }

    /**
     * The issues of the journal, for the roster form.
     *
     * @return array<int, array{id: int, label: string}>
     */
    protected function getIssues(int $contextId): array
    {
        $issues = [];
        $collector = Repo::issue()->getCollector()
            ->filterByContextIds([$contextId])
            ->orderBy(\APP\issue\Collector::ORDERBY_PUBLISHED_ISSUES);
        foreach ($collector->getMany() as $issue) {
            $issues[] = ['id' => (int) $issue->getId(), 'label' => $issue->getIssueIdentification()];
        }

        return $issues;
    }

    /**
     * The roster: reviewers who completed reviews in the period and/or issue.
     *
     * @return array{rows: array, count: int, reviewsTotal: int, issueLabel: string}
     */
    protected function getNominata(int $contextId, string $dateFrom, string $dateTo, int $issueId): array
    {
        $query = $this->reviewAssignments($contextId)
            ->whereNotNull('ra.date_completed')
            ->where('ra.declined', '<>', 1)
            ->when($dateFrom !== '', fn ($q) => $q->whereDate('ra.date_completed', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($q) => $q->whereDate('ra.date_completed', '<=', $dateTo));

        $issueLabel = '';
        if ($issueId > 0) {
            $submissionIds = Repo::publication()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByIssueIds([$issueId])
                ->getQueryBuilder()
                ->pluck('p.submission_id')
                ->unique()
                ->all();
            $query->whereIn('ra.submission_id', $submissionIds ?: [0]);
            $issueLabel = (string) Repo::issue()->get($issueId, $contextId)?->getIssueIdentification();
        }

        $grouped = self::groupCompletedReviews($query->get(['ra.reviewer_id', 'ra.submission_id', 'ra.date_completed'])->all());

        $users = [];
        if ($grouped) {
            foreach (Repo::user()->getCollector()->filterByUserIds(array_keys($grouped))->getMany() as $user) {
                $users[(int) $user->getId()] = $user;
            }
        }

        $rows = [];
        foreach ($grouped as $reviewerId => $data) {
            $user = $users[$reviewerId] ?? null;
            if (!$user) {
                continue;
            }
            $rows[] = [
                'id' => $reviewerId,
                'fullName' => $user->getFullName(),
                'affiliation' => (string) $user->getLocalizedAffiliation(),
                'country' => (string) $user->getCountryLocalized(),
                'orcid' => (string) $user->getOrcid(),
                'email' => (string) $user->getEmail(),
                'count' => $data['count'],
                'submissions' => $data['submissions'],
                'submissionsString' => implode(', ', array_map(fn ($id) => '#' . $id, $data['submissions'])),
                'firstDate' => $data['firstDate'],
                'lastDate' => $data['lastDate'],
            ];
        }

        usort($rows, fn ($a, $b) => strcoll(Str::lower($a['fullName']), Str::lower($b['fullName'])));

        return [
            'rows' => $rows,
            'count' => count($rows),
            'reviewsTotal' => array_sum(array_column($grouped, 'count')),
            'issueLabel' => $issueLabel,
        ];
    }

    /**
     * Groups completed review assignments by reviewer: number of reviews, submissions, and
     * the first and last completion dates.
     *
     * @param iterable<object{reviewer_id: int|string, submission_id: int|string, date_completed: string}> $rows
     *
     * @return array<int, array{count: int, submissions: int[], firstDate: string, lastDate: string}>
     */
    public static function groupCompletedReviews(iterable $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $reviewerId = (int) $row->reviewer_id;
            $date = substr((string) $row->date_completed, 0, 10);
            $entry = $grouped[$reviewerId] ?? ['count' => 0, 'submissions' => [], 'firstDate' => $date, 'lastDate' => $date];
            $entry['count']++;
            $entry['submissions'][(int) $row->submission_id] = (int) $row->submission_id;
            $entry['firstDate'] = min($entry['firstDate'], $date);
            $entry['lastDate'] = max($entry['lastDate'], $date);
            $grouped[$reviewerId] = $entry;
        }

        foreach ($grouped as &$entry) {
            ksort($entry['submissions']);
            $entry['submissions'] = array_values($entry['submissions']);
        }

        return $grouped;
    }

    /**
     * A date in the YYYY-MM-DD format, or an empty string.
     */
    public static function sanitizeDate($value): string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : '';
    }
}

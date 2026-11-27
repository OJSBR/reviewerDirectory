<?php

/**
 * @file plugins/generic/reviewerDirectory/classes/ReviewerStats.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerStats
 *
 * @brief The reviewers of the journal or press with their profile and two families of
 *  numbers:
 *
 *  - the numbers OJS/OMP shows when an editor chooses a reviewer (completed, declined,
 *    average days, rating, last assignment), taken from the core user collector
 *    (`includeReviewerData()`), so they are the same as on that screen. Unless the site
 *    administrator turned the sharing off, they cover every journal of the site;
 *  - the numbers of this journal or press only, worked out here from its review
 *    assignments: reviews in progress, overdue, acceptance, reviews on time, reviews in
 *    the last 12 months, last completion. The indices and the situations come from these.
 *
 *  Everything is loaded with a handful of queries, whatever the number of reviewers.
 */

namespace APP\plugins\generic\reviewerDirectory\classes;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\core\PKPRequest;
use PKP\security\Role;

class ReviewerStats
{
    /** Situations of a reviewer, in order of precedence: each reviewer takes the first that applies. */
    public const SITUATIONS = ['overdue', 'busy', 'reviewing', 'recent', 'idle', 'new'];

    /** Reviews in progress from which a reviewer counts as busy. */
    public const BUSY_FROM = 3;

    /** Months that count as recent. */
    public const RECENT_MONTHS = 12;

    /** @var ?array<int,array> */
    protected ?array $reviewers = null;

    public function __construct(
        protected Context $context,
        protected PKPRequest $request
    ) {
    }

    /**
     * Every reviewer of the journal or press, by user id, sorted by name.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getReviewers(): array
    {
        if ($this->reviewers !== null) {
            return $this->reviewers;
        }

        $rows = Repo::user()->getCollector()
            ->filterByContextIds([$this->context->getId()])
            ->filterByRoleIds([Role::ROLE_ID_REVIEWER])
            ->includeReviewerData()
            ->getQueryBuilder()
            ->get()
            ->keyBy(fn ($row) => (int) $row->user_id)
            ->all();
        $users = BatchHydrator::hydrate(Repo::user()->dao, $rows);
        $ids = array_keys($users);

        $interests = $ids ? Repo::user()->preloadInterests($ids) : [];
        $journal = $this->getJournalStatistics($ids);
        $active = $this->getActiveSubmissions($ids);
        $countries = $this->getCountryNames(array_map(fn ($user) => (string) $user->getCountry(), $users));

        $reviewers = [];
        foreach ($users as $id => $user) {
            $row = $rows[$id];
            $stats = $journal[$id] ?? [];
            $names = array_values(array_unique(array_filter(array_map(
                fn ($interest) => trim((string) (is_array($interest) ? ($interest['interest'] ?? '') : $interest)),
                $interests[$id] ?? []
            ))));
            $orcid = (string) $user->getOrcid();
            $completed = (int) ($stats['completed'] ?? 0);
            $responded = (int) ($stats['accepted'] ?? 0) + (int) ($stats['declined'] ?? 0);
            $activeSubmissions = $active[$id] ?? [];
            $country = (string) $user->getCountry();

            $reviewer = [
                'id' => $id,
                'name' => $user->getFullName(),
                'email' => (string) $user->getEmail(),
                'username' => (string) $user->getUsername(),
                'affiliation' => (string) $user->getLocalizedAffiliation(),
                'country' => $country,
                'countryName' => $countries[$country] ?? $country,
                'orcid' => $orcid,
                'orcidId' => preg_replace('#^https?://(sandbox\.)?orcid\.org/#', '', $orcid),
                'orcidVerified' => $orcid !== '' && (bool) $user->hasVerifiedOrcid(),
                'interests' => $names,
                // As OJS shows them when an editor chooses a reviewer.
                'ojsCompleted' => (int) $row->complete_count,
                'ojsDeclined' => (int) $row->declined_count,
                'ojsActive' => (int) $row->incomplete_count,
                'ojsAverageDays' => $row->average_time !== null ? (int) $row->average_time : null,
                'rating' => $row->reviewer_rating ? (int) max(1, round((float) $row->reviewer_rating)) : null,
                'lastAssigned' => substr((string) $row->last_assigned, 0, 10),
                // This journal or press only.
                'requests' => (int) ($stats['requests'] ?? 0),
                'active' => count($activeSubmissions),
                'activeSubmissions' => $activeSubmissions,
                'overdue' => (int) ($stats['overdue'] ?? 0),
                'completed' => $completed,
                'declined' => (int) ($stats['declined'] ?? 0),
                'acceptance' => $responded ? (int) round(100 * (int) $stats['accepted'] / $responded) : null,
                'onTime' => $completed ? (int) round(100 * (int) ($stats['onTime'] ?? 0) / $completed) : null,
                'averageDays' => isset($stats['averageDays']) ? (int) round((float) $stats['averageDays']) : null,
                'recent' => (int) ($stats['recent'] ?? 0),
                'lastCompleted' => substr((string) ($stats['lastCompleted'] ?? ''), 0, 10),
                'monthsIdle' => $this->monthsSince((string) ($stats['lastCompleted'] ?? '')),
            ];
            $reviewer['situation'] = self::situationOf($reviewer);
            $reviewers[$id] = $reviewer;
        }

        $collator = class_exists(\Collator::class) ? new \Collator((string) \PKP\facades\Locale::getLocale()) : null;
        uasort($reviewers, fn ($a, $b) => $collator ? $collator->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name']));

        return $this->reviewers = $reviewers;
    }

    /**
     * The situation of a reviewer: the first that applies in self::SITUATIONS.
     */
    public static function situationOf(array $reviewer): string
    {
        return match (true) {
            $reviewer['overdue'] > 0 => 'overdue',
            $reviewer['active'] >= self::BUSY_FROM => 'busy',
            $reviewer['active'] > 0 => 'reviewing',
            $reviewer['recent'] > 0 => 'recent',
            $reviewer['completed'] > 0 => 'idle',
            default => 'new',
        };
    }

    /**
     * Indices of a set of reviewers: how many there are in each group, and the rates of
     * the journal or press over their reviews.
     *
     * @param array<int,array> $reviewers
     */
    public static function getIndices(array $reviewers): array
    {
        $sum = fn (string $key) => array_sum(array_column($reviewers, $key));
        $count = fn (callable $test) => count(array_filter($reviewers, $test));

        return [
            'total' => count($reviewers),
            'available' => $count(fn ($r) => $r['active'] === 0),
            'reviewing' => $count(fn ($r) => $r['active'] > 0),
            'overdue' => $count(fn ($r) => $r['overdue'] > 0),
            'recent' => $count(fn ($r) => $r['recent'] > 0),
            'never' => $count(fn ($r) => $r['completed'] === 0),
            'withoutOrcid' => $count(fn ($r) => $r['orcid'] === ''),
            'reviewsInProgress' => $sum('active'),
            'reviewsRecent' => $sum('recent'),
        ];
    }

    /**
     * Rates of the journal or press over all the review assignments of a set of
     * reviewers: acceptance (accepted of answered), on time (completed by the due date),
     * average days from request to review.
     *
     * @param int[] $reviewerIds
     *
     * @return array{acceptance:?int,onTime:?int,averageDays:?int}
     */
    public function getRates(array $reviewerIds): array
    {
        if (!$reviewerIds) {
            return ['acceptance' => null, 'onTime' => null, 'averageDays' => null];
        }
        $row = null;
        foreach (array_chunk($reviewerIds, 1000) as $chunk) {
            $part = $this->assignments()
                ->whereIn('ra.reviewer_id', $chunk)
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_confirmed IS NOT NULL THEN 1 ELSE 0 END) AS accepted')
                ->selectRaw('SUM(CASE WHEN ra.declined = 1 THEN 1 ELSE 0 END) AS declined')
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_completed IS NOT NULL THEN 1 ELSE 0 END) AS completed')
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_completed IS NOT NULL AND ra.date_due IS NOT NULL AND ' . $this->dateOnly('ra.date_completed') . ' <= ' . $this->dateOnly('ra.date_due') . ' THEN 1 ELSE 0 END) AS on_time')
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_completed IS NOT NULL AND ra.date_notified IS NOT NULL THEN ' . $this->dateDiff('ra.date_completed', 'ra.date_notified') . ' ELSE 0 END) AS days')
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_completed IS NOT NULL AND ra.date_notified IS NOT NULL THEN 1 ELSE 0 END) AS timed')
                ->first();
            foreach ((array) $part as $key => $value) {
                $row[$key] = ($row[$key] ?? 0) + (float) $value;
            }
        }
        $answered = $row['accepted'] + $row['declined'];
        return [
            'acceptance' => $answered ? (int) round(100 * $row['accepted'] / $answered) : null,
            'onTime' => $row['completed'] ? (int) round(100 * $row['on_time'] / $row['completed']) : null,
            'averageDays' => $row['timed'] ? (int) round($row['days'] / $row['timed']) : null,
        ];
    }

    /**
     * The review assignments of the journal or press that count (not cancelled).
     */
    protected function assignments(): Builder
    {
        return DB::table('review_assignments AS ra')
            ->join('submissions AS s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $this->context->getId())
            ->where('ra.cancelled', 0);
    }

    /**
     * The numbers of each reviewer in this journal or press, in one query.
     *
     * @param int[] $reviewerIds
     *
     * @return array<int,array<string,mixed>>
     */
    protected function getJournalStatistics(array $reviewerIds): array
    {
        if (!$reviewerIds) {
            return [];
        }
        $today = date('Y-m-d');
        $recentFrom = date('Y-m-d', strtotime('-' . self::RECENT_MONTHS . ' months'));
        $active = 'ra.date_notified IS NOT NULL AND ra.date_completed IS NULL AND ra.declined = 0';

        $stats = [];
        foreach (array_chunk($reviewerIds, 1000) as $chunk) {
            $rows = $this->assignments()
                ->whereIn('ra.reviewer_id', $chunk)
                ->groupBy('ra.reviewer_id')
                ->select('ra.reviewer_id')
                ->selectRaw('COUNT(*) AS requests')
                ->selectRaw('SUM(CASE WHEN ' . $active . ' AND ((ra.date_confirmed IS NULL AND ra.date_response_due IS NOT NULL AND ' . $this->dateOnly('ra.date_response_due') . ' < ?) OR (ra.date_due IS NOT NULL AND ' . $this->dateOnly('ra.date_due') . ' < ?)) THEN 1 ELSE 0 END) AS overdue', [$today, $today])
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_confirmed IS NOT NULL THEN 1 ELSE 0 END) AS accepted')
                ->selectRaw('SUM(CASE WHEN ra.declined = 1 THEN 1 ELSE 0 END) AS declined')
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_completed IS NOT NULL THEN 1 ELSE 0 END) AS completed')
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_completed IS NOT NULL AND ra.date_due IS NOT NULL AND ' . $this->dateOnly('ra.date_completed') . ' <= ' . $this->dateOnly('ra.date_due') . ' THEN 1 ELSE 0 END) AS on_time')
                ->selectRaw('SUM(CASE WHEN ra.declined = 0 AND ra.date_completed IS NOT NULL AND ' . $this->dateOnly('ra.date_completed') . ' >= ? THEN 1 ELSE 0 END) AS recent', [$recentFrom])
                ->selectRaw('AVG(CASE WHEN ra.declined = 0 AND ra.date_completed IS NOT NULL AND ra.date_notified IS NOT NULL THEN ' . $this->dateDiff('ra.date_completed', 'ra.date_notified') . ' END) AS average_days')
                ->selectRaw('MAX(CASE WHEN ra.declined = 0 THEN ra.date_completed END) AS last_completed')
                ->get();
            foreach ($rows as $row) {
                $stats[(int) $row->reviewer_id] = [
                    'requests' => (int) $row->requests,
                    'overdue' => (int) $row->overdue,
                    'accepted' => (int) $row->accepted,
                    'declined' => (int) $row->declined,
                    'completed' => (int) $row->completed,
                    'onTime' => (int) $row->on_time,
                    'recent' => (int) $row->recent,
                    'averageDays' => $row->average_days,
                    'lastCompleted' => $row->last_completed,
                ];
            }
        }
        return $stats;
    }

    /**
     * The submissions each reviewer has a review in progress for: notified, and not
     * completed, declined or cancelled. One entry per submission, whatever the rounds.
     *
     * @param int[] $reviewerIds
     *
     * @return array<int,array<int,array{id:int,url:string}>>
     */
    protected function getActiveSubmissions(array $reviewerIds): array
    {
        if (!$reviewerIds) {
            return [];
        }
        $url = $this->request->getDispatcher()->url(
            $this->request,
            Application::ROUTE_PAGE,
            $this->context->getPath(),
            'dashboard',
            'editorial',
            null,
            ['workflowSubmissionId' => '__SUBMISSION_ID__']
        );
        $map = [];
        foreach (array_chunk($reviewerIds, 1000) as $chunk) {
            $rows = $this->assignments()
                ->whereIn('ra.reviewer_id', $chunk)
                ->whereNotNull('ra.date_notified')
                ->whereNull('ra.date_completed')
                ->where('ra.declined', 0)
                ->orderBy('ra.submission_id')
                ->get(['ra.reviewer_id', 'ra.submission_id']);
            foreach ($rows as $row) {
                $submissionId = (int) $row->submission_id;
                $map[(int) $row->reviewer_id][$submissionId] = [
                    'id' => $submissionId,
                    'url' => str_replace('__SUBMISSION_ID__', (string) $submissionId, $url),
                ];
            }
        }
        return array_map('array_values', $map);
    }

    /**
     * Names of countries in the language of the interface.
     *
     * @param string[] $codes
     *
     * @return array<string,string>
     */
    protected function getCountryNames(array $codes): array
    {
        $names = [];
        $countries = \PKP\facades\Locale::getCountries();
        foreach (array_unique(array_filter($codes)) as $code) {
            $country = $countries->getByAlpha2($code);
            $names[$code] = $country ? (string) $country->getLocalName() : $code;
        }
        return $names;
    }

    /**
     * Whole months since a date, or null without one.
     */
    protected function monthsSince(string $date): ?int
    {
        $time = $date !== '' ? strtotime($date) : false;
        if (!$time) {
            return null;
        }
        $diff = (new \DateTimeImmutable(date('Y-m-d', $time)))->diff(new \DateTimeImmutable(date('Y-m-d')));
        return $diff->invert ? 0 : $diff->y * 12 + $diff->m;
    }

    /**
     * SQL for the date part of a column, in MySQL/MariaDB and PostgreSQL.
     */
    protected function dateOnly(string $column): string
    {
        return $this->isMySql() ? "DATE({$column})" : "CAST({$column} AS DATE)";
    }

    /**
     * SQL for the days between two columns, as the core counts them.
     */
    protected function dateDiff(string $dateA, string $dateB): string
    {
        return $this->isMySql() ? "DATEDIFF({$dateA}, {$dateB})" : "DATE_PART('day', {$dateA} - {$dateB})";
    }

    protected function isMySql(): bool
    {
        return DB::connection() instanceof MySqlConnection || DB::connection() instanceof MariaDbConnection;
    }
}

<?php

/**
 * @file plugins/generic/reviewerDirectory/classes/Roster.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Roster
 *
 * @brief The reviewer roster (nominata): the reviewers who completed reviews in a period
 *  and, in OJS, for the articles of an issue, ready to be published as an
 *  acknowledgement.
 */

namespace APP\plugins\generic\reviewerDirectory\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;

class Roster
{
    public function __construct(protected Context $context)
    {
    }

    /**
     * Whether the application groups its publications in issues (OJS: yes; OMP: no).
     */
    public static function hasIssues(): bool
    {
        return method_exists(Repo::class, 'issue');
    }

    /**
     * The issues of the journal, newest first, for the form (a press has none).
     *
     * @return array<int,array{value:int,label:string}>
     */
    public function getIssues(): array
    {
        if (!self::hasIssues()) {
            return [];
        }
        $issues = [];
        $collector = Repo::issue()->getCollector()
            ->filterByContextIds([$this->context->getId()])
            ->orderBy(\APP\issue\Collector::ORDERBY_PUBLISHED_ISSUES);
        foreach ($collector->getMany() as $issue) {
            $issues[] = ['value' => (int) $issue->getId(), 'label' => (string) $issue->getIssueIdentification()];
        }
        return $issues;
    }

    /**
     * The roster: reviewers who completed reviews in the period and/or issue.
     *
     * @return array{rows:array,reviewers:int,reviews:int,submissions:int,countries:int,issueLabel:string}
     */
    public function get(string $dateFrom, string $dateTo, int $issueId): array
    {
        $query = DB::table('review_assignments AS ra')
            ->join('submissions AS s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $this->context->getId())
            ->whereNotNull('ra.date_completed')
            ->where('ra.declined', 0)
            ->when($dateFrom !== '', fn ($q) => $q->where('ra.date_completed', '>=', $dateFrom . ' 00:00:00'))
            ->when($dateTo !== '', fn ($q) => $q->where('ra.date_completed', '<=', $dateTo . ' 23:59:59'));

        $issueLabel = '';
        if ($issueId > 0 && self::hasIssues()) {
            $issue = Repo::issue()->get($issueId, $this->context->getId());
            $issueLabel = (string) $issue?->getIssueIdentification();
            $query->whereIn('ra.submission_id', fn ($q) => $q->select('p.submission_id')
                ->from('publications AS p')
                ->where('p.issue_id', $issue ? $issueId : 0));
        }

        $grouped = self::groupCompletedReviews($query->get(['ra.reviewer_id', 'ra.submission_id', 'ra.date_completed'])->all());

        $users = BatchHydrator::where(Repo::user()->dao, 'user_id', array_keys($grouped));
        $countries = \PKP\facades\Locale::getCountries();
        $rows = [];
        $submissions = [];
        foreach ($grouped as $reviewerId => $data) {
            $user = $users[$reviewerId] ?? null;
            if (!$user) {
                continue;
            }
            $country = (string) $user->getCountry();
            $orcid = (string) $user->getOrcid();
            $rows[] = [
                'id' => $reviewerId,
                'name' => $user->getFullName(),
                'affiliation' => (string) $user->getLocalizedAffiliation(),
                'country' => $country,
                'countryName' => $country !== '' ? (string) ($countries->getByAlpha2($country)?->getLocalName() ?? $country) : '',
                'orcid' => $orcid,
                'orcidId' => preg_replace('#^https?://(sandbox\.)?orcid\.org/#', '', $orcid),
                'email' => (string) $user->getEmail(),
                'count' => $data['count'],
                'submissions' => $data['submissions'],
                'firstDate' => $data['firstDate'],
                'lastDate' => $data['lastDate'],
            ];
            $submissions += array_flip($data['submissions']);
        }

        $collator = class_exists(\Collator::class) ? new \Collator((string) \PKP\facades\Locale::getLocale()) : null;
        usort($rows, fn ($a, $b) => $collator ? $collator->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name']));

        return [
            'rows' => $rows,
            'reviewers' => count($rows),
            'reviews' => array_sum(array_column($rows, 'count')),
            'submissions' => count($submissions),
            'countries' => count(array_unique(array_filter(array_column($rows, 'country')))),
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
}

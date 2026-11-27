<?php

/**
 * @file plugins/generic/reviewerDirectory/classes/DirectoryFilters.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DirectoryFilters
 *
 * @brief The filters of the directory, read from the query of a request (values out of
 *  the lists or ranges are dropped), and the filtering and sorting of the reviewers.
 */

namespace APP\plugins\generic\reviewerDirectory\classes;

class DirectoryFilters
{
    /** Groups of the tiles: a reviewer can be in several. */
    public const GROUPS = ['available', 'reviewing', 'overdue', 'recent', 'never', 'withoutOrcid'];

    /** ORCID choices. */
    public const ORCID = ['with', 'without', 'verified'];

    /** Columns the table can be sorted by. */
    public const ORDER_BY = [
        'name', 'completed', 'active', 'overdue', 'acceptance', 'onTime', 'averageDays',
        'recent', 'requests', 'rating', 'lastAssigned', 'lastCompleted', 'ojsCompleted',
    ];

    public const MONTHS_IDLE_MAX = 60;

    public const SEARCH_MAX_LENGTH = 200;

    public string $group = '';

    /** @var string[] */
    public array $situations = [];

    /** @var string[] */
    public array $countries = [];

    public string $interest = '';

    public string $orcid = '';

    public int $minCompleted = 0;

    public int $monthsIdle = 0;

    public int $minRating = 0;

    public string $searchPhrase = '';

    public string $orderBy = 'name';

    public string $orderDirection = 'ASC';

    public int $page = 1;

    public static function fromQuery(array $query): self
    {
        $filters = new self();
        $text = fn (string $key) => is_scalar($query[$key] ?? null) ? trim((string) $query[$key]) : '';
        $list = function (string $key) use ($query): array {
            $value = $query[$key] ?? [];
            $value = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
            return array_values(array_unique(array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value), 'strlen')));
        };

        $filters->group = in_array($text('group'), self::GROUPS, true) ? $text('group') : '';
        $filters->situations = array_values(array_intersect($list('situations'), ReviewerStats::SITUATIONS));
        $filters->countries = array_values(array_filter($list('countries'), fn ($code) => (bool) preg_match('/^[A-Z]{2}$/', $code)));
        $filters->interest = mb_substr($text('interest'), 0, self::SEARCH_MAX_LENGTH);
        $filters->orcid = in_array($text('orcid'), self::ORCID, true) ? $text('orcid') : '';
        $filters->minCompleted = max(0, min(1000, (int) $text('minCompleted')));
        $filters->monthsIdle = max(0, min(self::MONTHS_IDLE_MAX, (int) $text('monthsIdle')));
        $filters->minRating = max(0, min(5, (int) $text('minRating')));
        $filters->searchPhrase = mb_substr($text('searchPhrase'), 0, self::SEARCH_MAX_LENGTH);
        $filters->orderBy = in_array($text('orderBy'), self::ORDER_BY, true) ? $text('orderBy') : 'name';
        $filters->orderDirection = strtoupper($text('orderDirection')) === 'DESC' ? 'DESC' : 'ASC';
        $filters->page = max(1, (int) $text('page'));
        return $filters;
    }

    /**
     * Whether a reviewer is in a group of the tiles.
     */
    public static function inGroup(array $reviewer, string $group): bool
    {
        return match ($group) {
            'available' => $reviewer['active'] === 0,
            'reviewing' => $reviewer['active'] > 0,
            'overdue' => $reviewer['overdue'] > 0,
            'recent' => $reviewer['recent'] > 0,
            'never' => $reviewer['completed'] === 0,
            'withoutOrcid' => $reviewer['orcid'] === '',
            default => true,
        };
    }

    /**
     * The reviewers that pass the filters.
     *
     * @param array<int,array> $reviewers
     * @param bool $withGroup Apply the group of the tiles
     * @param bool $withSituations Apply the situations of the summary
     *
     * @return array<int,array>
     */
    public function apply(array $reviewers, bool $withGroup = true, bool $withSituations = true): array
    {
        $terms = $this->searchPhrase === '' ? [] : preg_split('/\s+/u', mb_strtolower($this->searchPhrase));
        $interest = mb_strtolower($this->interest);

        return array_filter($reviewers, function (array $reviewer) use ($terms, $interest, $withGroup, $withSituations) {
            if ($withGroup && $this->group !== '' && !self::inGroup($reviewer, $this->group)) {
                return false;
            }
            if ($withSituations && $this->situations && !in_array($reviewer['situation'], $this->situations, true)) {
                return false;
            }
            if ($this->countries && !in_array($reviewer['country'], $this->countries, true)) {
                return false;
            }
            if ($interest !== '' && !array_filter($reviewer['interests'], fn ($name) => str_contains(mb_strtolower($name), $interest))) {
                return false;
            }
            if (($this->orcid === 'with' && $reviewer['orcid'] === '')
                || ($this->orcid === 'without' && $reviewer['orcid'] !== '')
                || ($this->orcid === 'verified' && !$reviewer['orcidVerified'])) {
                return false;
            }
            if ($reviewer['completed'] < $this->minCompleted) {
                return false;
            }
            // Idle: no review completed in the journal for at least so many months, or never.
            if ($this->monthsIdle && $reviewer['monthsIdle'] !== null && $reviewer['monthsIdle'] < $this->monthsIdle) {
                return false;
            }
            if ($this->minRating && (int) $reviewer['rating'] < $this->minRating) {
                return false;
            }
            if ($terms) {
                $haystack = mb_strtolower(implode(' ', [
                    $reviewer['name'], $reviewer['email'], $reviewer['username'], $reviewer['affiliation'],
                    $reviewer['countryName'], $reviewer['orcidId'], implode(' ', $reviewer['interests']),
                    implode(' ', array_map(fn ($submission) => '#' . $submission['id'] . ' ' . $submission['id'], $reviewer['activeSubmissions'])),
                ]));
                foreach ($terms as $term) {
                    if (!str_contains($haystack, $term)) {
                        return false;
                    }
                }
            }
            return true;
        });
    }

    /**
     * The reviewers in the chosen order; empty values always last, the name breaks ties.
     *
     * @param array<int,array> $reviewers Already sorted by name
     *
     * @return array<int,array>
     */
    public function sort(array $reviewers): array
    {
        if ($this->orderBy === 'name') {
            return $this->orderDirection === 'DESC' ? array_reverse($reviewers, true) : $reviewers;
        }
        $position = array_flip(array_keys($reviewers));
        $key = $this->orderBy;
        $sign = $this->orderDirection === 'DESC' ? -1 : 1;
        uasort($reviewers, function (array $a, array $b) use ($key, $sign, $position) {
            $emptyA = $a[$key] === null || $a[$key] === '';
            $emptyB = $b[$key] === null || $b[$key] === '';
            if ($emptyA !== $emptyB) {
                return $emptyA ? 1 : -1;
            }
            $compare = $emptyA ? 0 : $sign * ($a[$key] <=> $b[$key]);
            return $compare ?: $position[$a['id']] <=> $position[$b['id']];
        });
        return $reviewers;
    }

    /**
     * The filters as query parameters.
     */
    public function toQuery(): array
    {
        return array_filter([
            'group' => $this->group,
            'situations' => $this->situations,
            'countries' => $this->countries,
            'interest' => $this->interest,
            'orcid' => $this->orcid,
            'minCompleted' => $this->minCompleted ?: null,
            'monthsIdle' => $this->monthsIdle ?: null,
            'minRating' => $this->minRating ?: null,
            'searchPhrase' => $this->searchPhrase,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}

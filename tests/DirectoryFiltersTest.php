<?php

/**
 * @file plugins/generic/reviewerDirectory/tests/DirectoryFiltersTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DirectoryFiltersTest
 *
 * @brief The filters of a request, the situation of a reviewer, the filtering and the
 *        sorting of the directory.
 */

namespace APP\plugins\generic\reviewerDirectory\tests;

use APP\plugins\generic\reviewerDirectory\classes\DirectoryFilters;
use APP\plugins\generic\reviewerDirectory\classes\ReviewerStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(DirectoryFilters::class)]
#[CoversClass(ReviewerStats::class)]
class DirectoryFiltersTest extends PKPTestCase
{
    protected function reviewer(int $id, array $data): array
    {
        $reviewer = array_merge([
            'id' => $id, 'name' => 'Reviewer ' . $id, 'email' => "r{$id}@example.org", 'username' => "r{$id}",
            'affiliation' => '', 'country' => '', 'countryName' => '', 'orcid' => '', 'orcidId' => '', 'orcidVerified' => false,
            'interests' => [], 'active' => 0, 'activeSubmissions' => [], 'overdue' => 0, 'completed' => 0, 'recent' => 0,
            'requests' => 0, 'acceptance' => null, 'onTime' => null, 'averageDays' => null, 'rating' => null,
            'lastAssigned' => '', 'lastCompleted' => '', 'monthsIdle' => null, 'ojsCompleted' => 0,
        ], $data);
        $reviewer['situation'] = ReviewerStats::situationOf($reviewer);
        return $reviewer;
    }

    /** @return array<int,array> */
    protected function pool(): array
    {
        return [
            1 => $this->reviewer(1, ['name' => 'Ana', 'active' => 1, 'overdue' => 1, 'completed' => 4, 'recent' => 2, 'country' => 'BR', 'orcid' => 'https://orcid.org/0000-0001', 'orcidId' => '0000-0001', 'orcidVerified' => true, 'interests' => ['Ecologia', 'Botânica'], 'activeSubmissions' => [['id' => 77, 'url' => '']], 'rating' => 4, 'lastCompleted' => '2026-10-01', 'monthsIdle' => 1]),
            2 => $this->reviewer(2, ['name' => 'Bruno', 'active' => 3, 'completed' => 9, 'recent' => 5, 'country' => 'PT', 'orcid' => 'https://orcid.org/0000-0002', 'orcidId' => '0000-0002', 'lastCompleted' => '2026-11-01', 'monthsIdle' => 0, 'rating' => 5]),
            3 => $this->reviewer(3, ['name' => 'Carla', 'active' => 1, 'completed' => 1, 'recent' => 0, 'country' => 'BR', 'lastCompleted' => '2023-01-01', 'monthsIdle' => 46]),
            4 => $this->reviewer(4, ['name' => 'Davi', 'completed' => 2, 'recent' => 1, 'lastCompleted' => '2026-06-01', 'monthsIdle' => 5]),
            5 => $this->reviewer(5, ['name' => 'Eva', 'completed' => 3, 'lastCompleted' => '2022-01-01', 'monthsIdle' => 58, 'interests' => ['Ecologia de solos']]),
            6 => $this->reviewer(6, ['name' => 'Fábio']),
        ];
    }

    public function testEachReviewerHasOneSituationInOrderOfPrecedence(): void
    {
        $this->assertSame(
            [1 => 'overdue', 2 => 'busy', 3 => 'reviewing', 4 => 'recent', 5 => 'idle', 6 => 'new'],
            array_map(fn ($r) => $r['situation'], $this->pool())
        );
        $this->assertSame(ReviewerStats::SITUATIONS, array_values(array_map(fn ($r) => $r['situation'], $this->pool())));
    }

    public function testTheIndicesCountEachGroup(): void
    {
        $indices = ReviewerStats::getIndices($this->pool());
        $this->assertSame(6, $indices['total']);
        $this->assertSame(3, $indices['available']);
        $this->assertSame(3, $indices['reviewing']);
        $this->assertSame(1, $indices['overdue']);
        $this->assertSame(3, $indices['recent']);
        $this->assertSame(1, $indices['never']);
        $this->assertSame(4, $indices['withoutOrcid']);
        $this->assertSame(5, $indices['reviewsInProgress']);
        $this->assertSame(8, $indices['reviewsRecent']);
        foreach (DirectoryFilters::GROUPS as $group) {
            $this->assertSame($indices[$group], count(array_filter($this->pool(), fn ($r) => DirectoryFilters::inGroup($r, $group))), $group);
        }
    }

    public function testFiltersFromTheQueryAreChecked(): void
    {
        $filters = DirectoryFilters::fromQuery([
            'group' => 'nope', 'situations' => ['idle', 'x'], 'countries' => ['BR', 'brasil', "B'"], 'orcid' => 'maybe',
            'minCompleted' => '-3', 'monthsIdle' => '999', 'minRating' => '9', 'orderBy' => 'email; DROP', 'orderDirection' => 'sideways',
            'searchPhrase' => ['array'], 'page' => '0',
        ]);
        $this->assertSame('', $filters->group);
        $this->assertSame(['idle'], $filters->situations);
        $this->assertSame(['BR'], $filters->countries);
        $this->assertSame('', $filters->orcid);
        $this->assertSame(0, $filters->minCompleted);
        $this->assertSame(DirectoryFilters::MONTHS_IDLE_MAX, $filters->monthsIdle);
        $this->assertSame(5, $filters->minRating);
        $this->assertSame(['name', 'ASC'], [$filters->orderBy, $filters->orderDirection]);
        $this->assertSame('', $filters->searchPhrase);
        $this->assertSame(1, $filters->page);
    }

    public function testFiltering(): void
    {
        $ids = fn (array $query) => array_keys(DirectoryFilters::fromQuery($query)->apply($this->pool()));
        $this->assertSame([1, 2, 3, 4, 5, 6], $ids([]));
        $this->assertSame([4, 5, 6], $ids(['group' => 'available']));
        $this->assertSame([5], $ids(['situations' => ['idle']]));
        $this->assertSame([1, 3], $ids(['countries' => ['BR']]));
        $this->assertSame([1, 5], $ids(['interest' => 'ecolog']));
        $this->assertSame([1, 2], $ids(['orcid' => 'with']));
        $this->assertSame([1], $ids(['orcid' => 'verified']));
        $this->assertSame([3, 4, 5, 6], $ids(['orcid' => 'without']));
        $this->assertSame([1, 2, 5], $ids(['minCompleted' => '3']));
        // Idle for at least 12 months, or never completed a review.
        $this->assertSame([3, 5, 6], $ids(['monthsIdle' => '12']));
        $this->assertSame([2], $ids(['minRating' => '5']));
        // Search by name, by interest, by submission id.
        $this->assertSame([2], $ids(['searchPhrase' => 'BRUNO']));
        $this->assertSame([1], $ids(['searchPhrase' => 'botânica ana']));
        $this->assertSame([1], $ids(['searchPhrase' => '#77']));
        // The group and the situations can be left out, for the tiles and the summary.
        $filters = DirectoryFilters::fromQuery(['group' => 'available', 'situations' => ['new'], 'countries' => ['BR']]);
        $this->assertSame([1, 3], array_keys($filters->apply($this->pool(), false, false)));
        $this->assertSame([], array_keys($filters->apply($this->pool())));
    }

    public function testSortingKeepsEmptyValuesLastAndTiesByName(): void
    {
        $order = fn (array $query) => array_keys(DirectoryFilters::fromQuery($query)->sort($this->pool()));
        $this->assertSame([2, 1, 5, 4, 3, 6], $order(['orderBy' => 'completed', 'orderDirection' => 'DESC']));
        $this->assertSame([6, 3, 4, 5, 1, 2], $order(['orderBy' => 'completed', 'orderDirection' => 'ASC']));
        $this->assertSame([2, 1, 3, 4, 5, 6], $order(['orderBy' => 'rating', 'orderDirection' => 'DESC']));
        $rated = $order(['orderBy' => 'rating', 'orderDirection' => 'ASC']);
        $this->assertSame([1, 2], array_slice($rated, 0, 2), 'Reviewers without a rating come after the rated ones.');
        $this->assertSame([2, 1, 4, 3, 5, 6], $order(['orderBy' => 'lastCompleted', 'orderDirection' => 'DESC']));
        $this->assertSame([6, 5, 4, 3, 2, 1], $order(['orderBy' => 'name', 'orderDirection' => 'DESC']));
    }
}

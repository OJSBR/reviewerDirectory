<?php

/**
 * @file plugins/generic/reviewerDirectory/ReviewerDirectoryHandler.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewerDirectoryHandler
 *
 * @brief The backend page with the reviewer directory and the reviewer roster, the data
 *  the page asks for, and the exports. Only managers, section editors and site
 *  administrators get in.
 */

namespace APP\plugins\generic\reviewerDirectory;

use APP\handler\Handler;
use APP\plugins\generic\reviewerDirectory\classes\DirectoryFilters;
use APP\plugins\generic\reviewerDirectory\classes\ReviewerStats;
use APP\plugins\generic\reviewerDirectory\classes\Roster;
use APP\plugins\generic\reviewerDirectory\classes\SpreadsheetWriter;
use APP\template\TemplateManager;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldSlider;
use PKP\components\forms\FieldText;
use PKP\components\forms\FormComponent;
use PKP\core\JSONMessage;
use PKP\core\PKPRequest;
use PKP\core\PKPString;
use PKP\security\authorization\ContextAccessPolicy;

class ReviewerDirectoryHandler extends Handler
{
    /** @copydoc PKPHandler::_isBackendPage */
    public $_isBackendPage = true;

    /** Reviewers per page of the table. */
    public const PER_PAGE = 50;

    /** Countries shown as options before the field becomes too long to be useful. */
    public const COUNTRIES_MAX = 40;

    public function __construct(protected ReviewerDirectoryPlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment(ReviewerDirectoryPlugin::ALLOWED_ROLES, ['index', 'data', 'export', 'roster', 'rosterExport']);
    }

    /**
     * Only the allowed roles, in the journal or press of the request.
     *
     * @param PKPRequest $request
     * @param array $args
     * @param array $roleAssignments
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * The page.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function index($args, $request)
    {
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->setState(['reviewerDirectory' => $this->getPageConfig($request)]);
        $templateMgr->assign([
            'pageComponent' => 'Page',
            'pageTitle' => __('plugins.generic.reviewerDirectory.displayName'),
            'pageWidth' => TemplateManager::PAGE_WIDTH_FULL,
        ]);

        $assetsUrl = $request->getBaseUrl() . '/' . $this->plugin->getPluginPath();
        // The date of each file in the address, so that browsers never keep an old copy.
        $version = fn (string $file) => is_file($path = $this->plugin->getPluginPath() . '/' . $file) ? (string) filemtime($path) : '';
        $templateMgr->addStyleSheet(
            'reviewerDirectory',
            $assetsUrl . '/css/reviewerDirectory.css?v=' . $version('css/reviewerDirectory.css'),
            ['contexts' => 'backend']
        );
        // After the core scripts, so that the component is registered before the page mounts.
        $templateMgr->addJavaScript(
            'reviewerDirectory',
            $assetsUrl . '/js/reviewerDirectory.js?v=' . $version('js/reviewerDirectory.js'),
            ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );

        $templateMgr->display($this->plugin->getTemplateResource('directory.tpl'));
    }

    /**
     * The indices, the summary and one page of the directory, for the filters of the query.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function data($args, $request): JSONMessage
    {
        $stats = new ReviewerStats($request->getContext(), $request);
        $reviewers = $stats->getReviewers();
        $filters = DirectoryFilters::fromQuery($request->getQueryArray());

        // The tiles count with every filter but their own group; the summary with every
        // filter but its own situations; the table with all of them.
        $forTiles = $filters->apply($reviewers, false, true);
        $forSummary = $filters->apply($reviewers, true, false);
        $listed = $filters->sort($filters->apply($reviewers));

        $situations = array_fill_keys(ReviewerStats::SITUATIONS, 0);
        foreach ($forSummary as $reviewer) {
            $situations[$reviewer['situation']]++;
        }

        $total = count($listed);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($filters->page, $lastPage);

        return new JSONMessage(true, [
            'generatedAt' => $this->formatDateTime($request, time()),
            'indices' => ReviewerStats::getIndices($forTiles),
            'rates' => $stats->getRates(array_keys($listed)),
            'situationCounts' => $situations,
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'rows' => array_values(array_slice($listed, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
        ]);
    }

    /**
     * Every reviewer of the filters, as CSV or XLSX.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function export($args, $request)
    {
        $stats = new ReviewerStats($request->getContext(), $request);
        $filters = DirectoryFilters::fromQuery($request->getQueryArray());
        $listed = $filters->sort($filters->apply($stats->getReviewers()));
        $t = fn (string $key) => __('plugins.generic.reviewerDirectory.' . $key);

        $writer = $this->openWriter($request, 'filePrefix');
        $writer->row([$this->describe($request, $filters)]);
        $columns = ['name', 'email', 'username', 'affiliation', 'country', 'orcid', 'interests', 'situation',
            'active', 'activeSubmissions', 'overdue', 'requests', 'completed', 'recent', 'declined', 'acceptance', 'onTime',
            'averageDays', 'lastCompleted', 'ojsCompleted', 'ojsDeclined', 'ojsAverageDays', 'rating', 'lastAssigned'];
        $writer->row(array_map(fn ($id) => $this->getColumnLabel($id), $columns), true);
        foreach ($listed as $r) {
            $writer->row([
                $r['name'], $r['email'], $r['username'], $r['affiliation'], $r['countryName'], $r['orcidId'],
                implode('; ', $r['interests']), $t('situation.' . $r['situation']),
                $r['active'], implode(', ', array_map(fn ($s) => '#' . $s['id'], $r['activeSubmissions'])), $r['overdue'],
                $r['requests'], $r['completed'], $r['recent'], $r['declined'], $r['acceptance'], $r['onTime'],
                $r['averageDays'], $r['lastCompleted'], $r['ojsCompleted'], $r['ojsDeclined'], $r['ojsAverageDays'],
                $r['rating'], $r['lastAssigned'],
            ]);
        }
        $writer->close($this->fileName($request, 'filePrefix'));
        exit;
    }

    /**
     * The roster for the period and issue of the query.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function roster($args, $request): JSONMessage
    {
        [$dateFrom, $dateTo, $issueId] = $this->getRosterFilters($request);
        $roster = (new Roster($request->getContext()))->get($dateFrom, $dateTo, $issueId);
        $roster['generatedAt'] = $this->formatDateTime($request, time());
        return new JSONMessage(true, $roster);
    }

    /**
     * The roster as CSV or XLSX.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function rosterExport($args, $request)
    {
        [$dateFrom, $dateTo, $issueId] = $this->getRosterFilters($request);
        $roster = (new Roster($request->getContext()))->get($dateFrom, $dateTo, $issueId);
        $t = fn (string $key, array $params = []) => __('plugins.generic.reviewerDirectory.' . $key, $params);

        $writer = $this->openWriter($request, 'rosterFilePrefix');
        $period = $t('rosterPeriod', ['from' => $dateFrom ?: '…', 'to' => $dateTo ?: '…']) . ($roster['issueLabel'] !== '' ? ' — ' . $roster['issueLabel'] : '');
        $writer->row([$t('export.firstLine', [
            'context' => $request->getContext()->getLocalizedName(),
            'date' => $this->formatDateTime($request, time()),
            'user' => $request->getUser()->getFullName(),
            'filters' => $period,
        ])]);
        $writer->row([
            __('user.name'), __('user.affiliation'), __('common.country'), __('user.orcid'), __('user.email'),
            $t('reviewsInScope'), $t('submissions'), $t('firstDate'), $t('lastDate'),
        ], true);
        foreach ($roster['rows'] as $r) {
            $writer->row([
                $r['name'], $r['affiliation'], $r['countryName'], $r['orcidId'], $r['email'], $r['count'],
                implode(', ', array_map(fn ($id) => '#' . $id, $r['submissions'])), $r['firstDate'], $r['lastDate'],
            ]);
        }
        $writer->close($this->fileName($request, 'rosterFilePrefix'));
        exit;
    }

    /**
     * Period and issue of the roster, checked.
     *
     * @return array{0:string,1:string,2:int}
     */
    protected function getRosterFilters(PKPRequest $request): array
    {
        $dateFrom = self::sanitizeDate($request->getUserVar('dateFrom'));
        $dateTo = self::sanitizeDate($request->getUserVar('dateTo'));
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        $issueId = Roster::hasIssues() ? max(0, (int) $request->getUserVar('issueId')) : 0;
        return [$dateFrom, $dateTo, $issueId];
    }

    /**
     * Start an export file in the format of the query.
     */
    protected function openWriter(PKPRequest $request, string $prefixKey): SpreadsheetWriter
    {
        set_time_limit(0);
        $format = $request->getUserVar('format') === SpreadsheetWriter::FORMAT_XLSX && SpreadsheetWriter::canWriteXlsx()
            ? SpreadsheetWriter::FORMAT_XLSX
            : SpreadsheetWriter::FORMAT_CSV;
        $writer = new SpreadsheetWriter($format);
        $writer->open($this->fileName($request, $prefixKey));
        return $writer;
    }

    /**
     * Name of an export file: what, journal or press, date and time.
     */
    protected function fileName(PKPRequest $request, string $prefixKey): string
    {
        static $names = [];
        return $names[$prefixKey] ??= trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', __('plugins.generic.reviewerDirectory.export.' . $prefixKey)))), '-')
            . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $request->getContext()->getPath())
            . '-' . date('Ymd-Hi');
    }

    /**
     * The first line of the directory export: when, by whom and with which filters.
     */
    protected function describe(PKPRequest $request, DirectoryFilters $filters): string
    {
        $t = fn (string $key, array $params = []) => __('plugins.generic.reviewerDirectory.' . $key, $params);
        $parts = [];
        if ($filters->group !== '') {
            $parts[] = $t('group.' . $filters->group);
        }
        if ($filters->situations) {
            $parts[] = $t('summaryHeading') . ': ' . implode(', ', array_map(fn ($id) => $t('situation.' . $id), $filters->situations));
        }
        if ($filters->countries) {
            $parts[] = __('common.country') . ': ' . implode(', ', $filters->countries);
        }
        if ($filters->interest !== '') {
            $parts[] = __('user.interests') . ': ' . $filters->interest;
        }
        if ($filters->orcid !== '') {
            $parts[] = __('user.orcid') . ': ' . $t('filter.orcid.' . $filters->orcid);
        }
        if ($filters->minCompleted) {
            $parts[] = $t('filter.minCompleted') . ': ' . $filters->minCompleted;
        }
        if ($filters->monthsIdle) {
            $parts[] = $t('filter.monthsIdle') . ': ' . $filters->monthsIdle;
        }
        if ($filters->minRating) {
            $parts[] = __('reviewer.list.filterRating') . ': ' . $filters->minRating;
        }
        if ($filters->searchPhrase !== '') {
            $parts[] = __('common.search') . ': ' . $filters->searchPhrase;
        }
        return $t('export.firstLine', [
            'context' => $request->getContext()->getLocalizedName(),
            'date' => $this->formatDateTime($request, time()),
            'user' => $request->getUser()->getFullName(),
            'filters' => $parts ? implode(' | ', $parts) : $t('allReviewers'),
        ]);
    }

    /**
     * Everything the page needs to start.
     */
    protected function getPageConfig(PKPRequest $request): array
    {
        $context = $request->getContext();
        $stats = new ReviewerStats($context, $request);
        $roster = new Roster($context);
        $t = fn (string $key) => __('plugins.generic.reviewerDirectory.' . $key);

        $columns = [];
        foreach (self::COLUMNS as $id => $column) {
            $columns[] = ['id' => $id, 'label' => $this->getColumnLabel($id), 'description' => $this->getColumnDescription($id)] + $column;
        }

        $groups = [];
        foreach (DirectoryFilters::GROUPS as $id) {
            $groups[] = ['id' => $id, 'name' => $t('group.' . $id), 'description' => $t('group.' . $id . '.description')];
        }
        $situations = [];
        foreach (ReviewerStats::SITUATIONS as $id) {
            $situations[] = ['id' => $id, 'name' => $t('situation.' . $id), 'description' => $t('situation.' . $id . '.description')];
        }

        $exportFormats = [['value' => SpreadsheetWriter::FORMAT_CSV, 'label' => $t('export.csv')]];
        if (SpreadsheetWriter::canWriteXlsx()) {
            $exportFormats[] = ['value' => SpreadsheetWriter::FORMAT_XLSX, 'label' => $t('export.xlsx')];
        }

        return [
            'dataUrl' => $this->plugin->getDirectoryUrl($request, 'data'),
            'exportUrl' => $this->plugin->getDirectoryUrl($request, 'export'),
            'rosterUrl' => $this->plugin->getDirectoryUrl($request, 'roster'),
            'rosterExportUrl' => $this->plugin->getDirectoryUrl($request, 'rosterExport'),
            'perPage' => self::PER_PAGE,
            'groups' => $groups,
            'situations' => $situations,
            'columns' => $columns,
            'filtersForm' => $this->getFiltersForm($stats)->getConfig(),
            'rosterForm' => $this->getRosterForm($roster)->getConfig(),
            'exportFormats' => $exportFormats,
            'busyFrom' => ReviewerStats::BUSY_FROM,
            'recentMonths' => ReviewerStats::RECENT_MONTHS,
            'contextPath' => $context->getPath(),
            'i18n' => $this->getUiStrings(),
        ];
    }

    /**
     * The filters of the directory, made of the core form fields.
     */
    protected function getFiltersForm(ReviewerStats $stats): FormComponent
    {
        $t = fn (string $key) => __('plugins.generic.reviewerDirectory.' . $key);
        $form = new FormComponent('reviewerDirectoryFilters', 'GET', '', []);
        $form->action = FormComponent::ACTION_EMIT;
        $form->addPage(['id' => 'default', 'submitButton' => null])->addGroup(['id' => 'default', 'pageId' => 'default']);

        $situations = array_map(fn ($id) => ['value' => $id, 'label' => $t('situation.' . $id)], ReviewerStats::SITUATIONS);
        $form->addField(new FieldOptions('situations', [
            'groupId' => 'default',
            'label' => $t('summaryHeading'),
            'options' => $situations,
            'value' => [],
        ]));

        $countries = [];
        foreach ($stats->getReviewers() as $reviewer) {
            if ($reviewer['country'] !== '') {
                $countries[$reviewer['country']] = ($countries[$reviewer['country']] ?? ['value' => $reviewer['country'], 'label' => $reviewer['countryName'], 'count' => 0]);
                $countries[$reviewer['country']]['count']++;
            }
        }
        usort($countries, fn ($a, $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);
        $countries = array_slice($countries, 0, self::COUNTRIES_MAX);
        if (count($countries) > 1) {
            $form->addField(new FieldOptions('countries', [
                'groupId' => 'default',
                'label' => __('common.country'),
                'options' => array_map(fn ($c) => ['value' => $c['value'], 'label' => $c['label'] . ' (' . $c['count'] . ')'], $countries),
                'value' => [],
            ]));
        }

        $form->addField(new FieldText('interest', [
            'groupId' => 'default',
            'label' => __('user.interests'),
            'description' => $t('filter.interest.description'),
            'value' => '',
        ]));
        $form->addField(new FieldOptions('orcid', [
            'groupId' => 'default',
            'label' => __('user.orcid'),
            'type' => 'radio',
            'options' => array_merge(
                [['value' => '', 'label' => $t('filter.orcid.any')]],
                array_map(fn ($id) => ['value' => $id, 'label' => $t('filter.orcid.' . $id)], DirectoryFilters::ORCID)
            ),
            'value' => '',
        ]));
        $form->addField(new FieldText('minCompleted', [
            'groupId' => 'default',
            'label' => $t('filter.minCompleted'),
            'inputType' => 'number',
            'size' => 'small',
            'value' => '',
        ]));
        $form->addField(new FieldSlider('monthsIdle', [
            'groupId' => 'default',
            'label' => $t('filter.monthsIdle'),
            'description' => $t('filter.monthsIdle.description'),
            'min' => 0,
            'max' => DirectoryFilters::MONTHS_IDLE_MAX,
            'value' => 0,
        ]));
        $form->addField(new FieldOptions('minRating', [
            'groupId' => 'default',
            'label' => __('reviewer.list.filterRating'),
            'type' => 'radio',
            'options' => array_merge(
                [['value' => 0, 'label' => $t('filter.orcid.any')]],
                array_map(fn ($n) => ['value' => $n, 'label' => str_repeat('★', $n) . str_repeat('☆', 5 - $n)], range(1, 5))
            ),
            'value' => 0,
        ]));
        return $form;
    }

    /**
     * The form of the roster: period and, in OJS, issue.
     */
    protected function getRosterForm(Roster $roster): FormComponent
    {
        $t = fn (string $key) => __('plugins.generic.reviewerDirectory.' . $key);
        $form = new FormComponent('reviewerDirectoryRoster', 'GET', '', []);
        $form->action = FormComponent::ACTION_EMIT;
        $form->addPage(['id' => 'default', 'submitButton' => null])->addGroup(['id' => 'default', 'pageId' => 'default']);
        $form->addField(new FieldText('dateFrom', [
            'groupId' => 'default',
            'label' => $t('dateFrom'),
            'inputType' => 'date',
            'size' => 'small',
            'value' => date('Y-01-01'),
        ]));
        $form->addField(new FieldText('dateTo', [
            'groupId' => 'default',
            'label' => $t('dateTo'),
            'inputType' => 'date',
            'size' => 'small',
            'value' => date('Y-12-31'),
        ]));
        $issues = $roster->getIssues();
        if ($issues) {
            $form->addField(new \PKP\components\forms\FieldSelect('issueId', [
                'groupId' => 'default',
                'label' => $t('issue'),
                'description' => $t('issue.description'),
                'options' => array_merge([['value' => 0, 'label' => $t('allIssues')]], $issues),
                'value' => 0,
            ]));
        }
        return $form;
    }

    /** Columns of the table: sortable by, shown at first. */
    public const COLUMNS = [
        'name' => ['sort' => 'name', 'visible' => true],
        'affiliation' => ['sort' => null, 'visible' => true],
        'country' => ['sort' => null, 'visible' => false],
        'orcid' => ['sort' => null, 'visible' => false],
        'email' => ['sort' => null, 'visible' => false],
        'username' => ['sort' => null, 'visible' => false],
        'interests' => ['sort' => null, 'visible' => true],
        'situation' => ['sort' => null, 'visible' => true],
        'active' => ['sort' => 'active', 'visible' => true],
        'overdue' => ['sort' => 'overdue', 'visible' => false],
        'completed' => ['sort' => 'completed', 'visible' => true],
        'recent' => ['sort' => 'recent', 'visible' => false],
        'requests' => ['sort' => 'requests', 'visible' => false],
        'acceptance' => ['sort' => 'acceptance', 'visible' => true],
        'onTime' => ['sort' => 'onTime', 'visible' => true],
        'averageDays' => ['sort' => 'averageDays', 'visible' => false],
        'lastCompleted' => ['sort' => 'lastCompleted', 'visible' => true],
        'ojs' => ['sort' => 'ojsCompleted', 'visible' => false],
        'rating' => ['sort' => 'rating', 'visible' => false],
        'lastAssigned' => ['sort' => 'lastAssigned', 'visible' => false],
    ];

    /**
     * The name of a column (of the table or of the export).
     */
    public function getColumnLabel(string $id): string
    {
        return match ($id) {
            'name' => __('user.name'),
            'affiliation' => __('user.affiliation'),
            'country' => __('common.country'),
            'orcid' => __('user.orcid'),
            'email' => __('user.email'),
            'username' => __('user.username'),
            'interests' => __('user.interests'),
            'lastAssigned', 'lastCompleted', 'rating' => __('plugins.generic.reviewerDirectory.' . $id),
            default => __('plugins.generic.reviewerDirectory.column.' . $id),
        };
    }

    /**
     * What a column of numbers counts, shown as its tooltip.
     */
    public function getColumnDescription(string $id): string
    {
        return in_array($id, ['active', 'overdue', 'completed', 'recent', 'requests', 'acceptance', 'onTime', 'averageDays', 'lastCompleted', 'ojs', 'rating', 'lastAssigned'], true)
            ? __('plugins.generic.reviewerDirectory.column.' . $id . '.description')
            : '';
    }

    /**
     * A date and time in the short format of the journal or press.
     */
    protected function formatDateTime(PKPRequest $request, int $time): string
    {
        return date(PKPString::convertStrftimeFormat((string) $request->getContext()->getLocalizedDateTimeFormatShort()), $time);
    }

    /**
     * The texts of the page.
     *
     * @return array<string,string>
     */
    protected function getUiStrings(): array
    {
        $own = [
            'generatedAt', 'export', 'exportTitle', 'exportFormat', 'download', 'tabDirectory',
            'indicesHeading', 'indicesNote', 'indicesFiltered', 'ratesHeading', 'rate.acceptance', 'rate.acceptance.description',
            'rate.onTime', 'rate.onTime.description', 'rate.averageDays', 'rate.averageDays.description', 'rate.reviewsRecent',
            'rate.reviewsRecent.description', 'rate.reviewsInProgress', 'rate.reviewsInProgress.description',
            'summaryHeading', 'summaryDescription', 'summaryClear',
            'filtersShow', 'filtersHide', 'activeFilters', 'searchPlaceholder', 'columns', 'columnsReset',
            'loadError', 'retry', 'totalCount', 'allReviewers', 'pageOf', 'percent', 'days', 'monthsAgo', 'thisMonth', 'none', 'orcidVerified',
            'openSubmission', 'mailto', 'ojsNote', 'ratingOf',
            'roster.reviewers', 'roster.countries', 'roster.copy', 'roster.copied', 'roster.copyDescription',
        ];
        $strings = [];
        foreach ($own as $key) {
            $strings[$key] = __('plugins.generic.reviewerDirectory.' . $key);
        }
        // Texts the page names after their place, kept under their former keys (translated in every language).
        foreach ([
            'tabRoster' => 'tabNominata', 'roster.intro' => 'nominataIntro', 'roster.generate' => 'generate',
            'roster.empty' => 'nominataEmpty', 'roster.reviews' => 'reviewsInScope', 'roster.submissions' => 'submissions',
            'roster.firstDate' => 'firstDate', 'roster.lastDate' => 'lastDate',
        ] as $name => $key) {
            $strings[$name] = __('plugins.generic.reviewerDirectory.' . $key);
        }
        $strings += [
            'filtersApply' => __('dashboard.applyFilters'),
            'filtersClear' => __('common.filtersClear'),
            'cancel' => __('common.cancel'),
            'loading' => __('common.loading'),
            'empty' => __('reviewer.list.empty'),
            'search' => __('common.search'),
            'name' => __('user.name'),
            'affiliation' => __('user.affiliation'),
            'country' => __('common.country'),
            'orcid' => __('user.orcid'),
            'email' => __('user.email'),
        ];
        return $strings;
    }

    /**
     * A date in the YYYY-MM-DD format, or an empty string.
     */
    public static function sanitizeDate($value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : '';
    }
}

/**
 * @file plugins/generic/reviewerDirectory/js/reviewerDirectory.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The reviewer directory page: indices of the reviewer pool, a summary of the reviewers'
 * situation that adds up, filters, a sortable table with the chosen columns and an
 * export; and the reviewer roster of a period or issue, with an export and a text ready
 * to be published.
 *
 * The component is registered through pkp.registry and is made of the components of
 * the core (table, form, search, pagination, buttons). The filters live in the address
 * of the page, so a filtered directory can be shared as a link.
 */
(function () {
	'use strict';

	if (!window.pkp || !pkp.registry || !pkp.modules || !pkp.modules.vue || !pkp.modules.useFiltersForm) {
		return;
	}
	var vue = pkp.modules.vue;
	var useFiltersForm = pkp.modules.useFiltersForm.useFiltersForm;

	/** Query parameters of the directory that are not fields of the filters form. */
	var OWN_PARAMS = ['tab', 'group', 'searchPhrase', 'orderBy', 'orderDirection', 'page', 'dateFrom', 'dateTo', 'issueId'];

	/** Months without a completed review from which the page calls attention to a reviewer. */
	var IDLE_WARNING = 24;

	function format(text, params) {
		return String(text).replace(/\{\$(\w+)\}/g, function (match, key) {
			return Object.prototype.hasOwnProperty.call(params || {}, key) ? params[key] : match;
		});
	}

	function hasValue(value) {
		return Array.isArray(value) ? value.length > 0 : value !== null && value !== undefined && value !== '' && value !== 0 && value !== '0';
	}

	/** Query string with the arrays as key[]=value, the way PHP reads them. */
	function toSearch(params) {
		var search = new URLSearchParams();
		Object.keys(params).forEach(function (key) {
			var value = params[key];
			if (Array.isArray(value)) {
				value.forEach(function (item) {
					search.append(key + '[]', item);
				});
			} else if (hasValue(value)) {
				search.append(key, value);
			}
		});
		return search.toString();
	}

	function fromSearch(search) {
		var params = {};
		new URLSearchParams(search).forEach(function (value, key) {
			if (key.slice(-2) === '[]') {
				key = key.slice(0, -2);
				params[key] = (params[key] || []).concat([value]);
			} else {
				params[key] = value;
			}
		});
		return params;
	}

	function storageGet(key) {
		try {
			return JSON.parse(window.localStorage.getItem(key));
		} catch (e) {
			return null;
		}
	}

	function storageSet(key, value) {
		try {
			window.localStorage.setItem(key, JSON.stringify(value));
		} catch (e) {
			// Private windows and blocked storage: the choice just is not remembered.
		}
	}

	function getJson(url, params) {
		return fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + toSearch(params), {
			credentials: 'same-origin',
			headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'}
		}).then(function (response) {
			if (!response.ok) {
				throw new Error(String(response.status));
			}
			return response.json();
		}).then(function (json) {
			if (!json || !json.status || !json.content) {
				throw new Error('content');
			}
			return json.content;
		});
	}

	pkp.registry.registerComponent('OjsbrReviewerDirectory', {
		name: 'OjsbrReviewerDirectory',
		props: {
			config: {type: Object, required: true}
		},
		setup: function (props) {
			var c = props.config;
			var t = c.i18n;
			var initial = fromSearch(window.location.search);
			var tab = vue.ref(initial.tab === 'roster' ? 'roster' : 'directory');

			// ---- Directory ----
			var filters = useFiltersForm(vue.ref(JSON.parse(JSON.stringify(c.filtersForm))));
			var filtersForm = filters.filtersForm;
			var applied = vue.ref({});
			var group = vue.ref('');
			var searchPhrase = vue.ref('');
			var orderBy = vue.ref('name');
			var orderDirection = vue.ref('ASC');
			var page = vue.ref(1);
			var loading = vue.ref(false);
			var failed = vue.ref(false);
			var result = vue.ref(null);
			var showFilters = vue.ref(false);
			var showExport = vue.ref(false);
			var exportFormat = vue.ref(c.exportFormats[0].value);

			var columnsKey = 'ojsbrReviewerDirectory.columns.' + c.contextPath;
			var defaultColumns = c.columns.filter(function (column) {
				return column.visible;
			}).map(function (column) {
				return column.id;
			});
			var stored = storageGet(columnsKey);
			var visibleColumnIds = vue.ref(Array.isArray(stored) && stored.length ? stored : defaultColumns.slice());
			vue.watch(visibleColumnIds, function (value) {
				storageSet(columnsKey, value);
			}, {deep: true});
			var visibleColumns = vue.computed(function () {
				return c.columns.filter(function (column) {
					return column.id === 'name' || visibleColumnIds.value.indexOf(column.id) !== -1;
				});
			});

			var situationNames = {};
			c.situations.forEach(function (situation) {
				situationNames[situation.id] = situation.name;
			});
			var groupNames = {};
			c.groups.forEach(function (item) {
				groupNames[item.id] = item.name;
			});

			function getField(form, name) {
				return form.value.fields.find(function (field) {
					return field.name === name;
				});
			}

			var otherFilters = vue.computed(function () {
				return filters.filtersFormList.value.filter(function (item) {
					return item.name !== 'situations' && hasValue(item.value);
				}).map(function (item) {
					var field = getField(filtersForm, item.name);
					var label = item.label === 'TODO' || (field && field.component === 'field-slider') ? item.value : item.label;
					return {name: item.name, value: item.value, text: item.fieldLabel + ': ' + label};
				});
			});

			var hasOtherFilters = vue.computed(function () {
				return Object.keys(applied.value).some(function (key) {
					return key !== 'situations' && hasValue(applied.value[key]);
				}) || searchPhrase.value !== '' || group.value !== '';
			});

			function requestParams() {
				var params = Object.assign({}, applied.value);
				params.group = group.value;
				params.searchPhrase = searchPhrase.value;
				params.orderBy = orderBy.value;
				params.orderDirection = orderDirection.value;
				return params;
			}

			function updateAddress() {
				var params = {};
				if (tab.value === 'roster') {
					params = Object.assign({tab: 'roster'}, rosterParams());
				} else {
					var formParams = filters.filtersFormQueryParams.value;
					Object.keys(formParams).forEach(function (key) {
						if (hasValue(formParams[key])) {
							params[key] = formParams[key];
						}
					});
					params.group = group.value;
					params.searchPhrase = searchPhrase.value;
					if (orderBy.value !== 'name' || orderDirection.value !== 'ASC') {
						params.orderBy = orderBy.value;
						params.orderDirection = orderDirection.value;
					}
					if (page.value > 1) {
						params.page = page.value;
					}
				}
				var search = toSearch(params);
				window.history.replaceState(window.history.state, '', window.location.pathname + (search ? '?' + search : '') + window.location.hash);
			}

			var requestNumber = 0;
			function load() {
				var number = ++requestNumber;
				var params = requestParams();
				params.page = page.value;
				loading.value = true;
				failed.value = false;
				updateAddress();
				return getJson(c.dataUrl, params).then(function (content) {
					if (number !== requestNumber) {
						return;
					}
					result.value = content;
					page.value = content.page;
					updateAddress();
				}).catch(function () {
					if (number === requestNumber) {
						failed.value = true;
					}
				}).finally(function () {
					if (number === requestNumber) {
						loading.value = false;
					}
				});
			}

			function applyFilters() {
				applied.value = JSON.parse(JSON.stringify(filters.filtersFormQueryParamsApi.value));
				page.value = 1;
				load();
			}

			function clearFilters() {
				filters.clearFiltersForm();
				group.value = '';
				searchPhrase.value = '';
				applyFilters();
			}

			function removeFilter(item) {
				filters.clearFiltersFormField(item.name, item.value);
				applyFilters();
			}

			function selectGroup(id) {
				group.value = group.value === id ? '' : id;
				page.value = 1;
				load();
			}

			function toggleSituation(id) {
				var field = getField(filtersForm, 'situations');
				var current = (field.value || []).slice();
				var index = current.indexOf(id);
				if (index === -1) {
					current.push(id);
				} else {
					current.splice(index, 1);
				}
				field.value = current;
				applyFilters();
			}

			function clearSituations() {
				getField(filtersForm, 'situations').value = [];
				applyFilters();
			}

			var selectedSituations = vue.computed(function () {
				return applied.value.situations || [];
			});

			function onSearch(phrase) {
				searchPhrase.value = phrase || '';
				page.value = 1;
				load();
			}

			var sortDescriptor = vue.computed(function () {
				var column = c.columns.find(function (item) {
					return item.sort === orderBy.value;
				});
				return {column: column ? column.id : '', direction: orderDirection.value === 'ASC' ? 'ascending' : 'descending'};
			});

			function onSort(columnId) {
				var column = c.columns.find(function (item) {
					return item.id === columnId;
				});
				if (!column || !column.sort) {
					return;
				}
				if (orderBy.value === column.sort) {
					orderDirection.value = orderDirection.value === 'ASC' ? 'DESC' : 'ASC';
				} else {
					orderBy.value = column.sort;
					// Names A to Z, dates and numbers from the largest.
					orderDirection.value = column.sort === 'name' ? 'ASC' : 'DESC';
				}
				page.value = 1;
				load();
			}

			function setPage(number) {
				page.value = number;
				load();
				var table = document.getElementById('ojsbr-rd-table');
				if (table && table.scrollIntoView) {
					table.scrollIntoView({block: 'start'});
				}
			}

			function toggleColumn(id) {
				var list = visibleColumnIds.value.slice();
				var index = list.indexOf(id);
				if (index === -1) {
					list.push(id);
				} else {
					list.splice(index, 1);
				}
				visibleColumnIds.value = list;
			}

			function resetColumns() {
				visibleColumnIds.value = defaultColumns.slice();
			}

			var summary = vue.computed(function () {
				var counts = result.value ? result.value.situationCounts : {};
				var total = 0;
				c.situations.forEach(function (situation) {
					total += counts[situation.id] || 0;
				});
				return {
					total: total,
					items: c.situations.map(function (situation) {
						var count = counts[situation.id] || 0;
						return {
							id: situation.id,
							name: situation.name,
							description: situation.description,
							count: count,
							percent: total ? Math.round(count * 1000 / total) / 10 : 0,
							isActive: selectedSituations.value.indexOf(situation.id) !== -1
						};
					})
				};
			});

			function stars(rating) {
				return rating ? '★★★★★'.slice(0, rating) + '☆☆☆☆☆'.slice(0, 5 - rating) : '';
			}

			// ---- Roster ----
			var rosterForm = vue.ref(JSON.parse(JSON.stringify(c.rosterForm)));
			var roster = vue.ref(null);
			var rosterLoading = vue.ref(false);
			var rosterFailed = vue.ref(false);
			var copied = vue.ref(false);

			function rosterParams() {
				var params = {};
				rosterForm.value.fields.forEach(function (field) {
					params[field.name] = field.value;
				});
				return params;
			}

			function onRosterSet(formId, data) {
				Object.keys(data).forEach(function (key) {
					rosterForm.value[key] = data[key];
				});
			}

			function loadRoster() {
				rosterLoading.value = true;
				rosterFailed.value = false;
				copied.value = false;
				updateAddress();
				return getJson(c.rosterUrl, rosterParams()).then(function (content) {
					roster.value = content;
				}).catch(function () {
					rosterFailed.value = true;
				}).finally(function () {
					rosterLoading.value = false;
				});
			}

			var rosterText = vue.computed(function () {
				if (!roster.value) {
					return '';
				}
				return roster.value.rows.map(function (row) {
					var clean = function (text) {
						return String(text || '').replace(/\s+/g, ' ').trim();
					};
					var where = [clean(row.affiliation), clean(row.countryName)].filter(Boolean).join(', ');
					return clean(row.name) + (where ? ' (' + where + ')' : '');
				}).join('\n');
			});

			function copyRoster() {
				var done = function () {
					copied.value = true;
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(rosterText.value).then(done);
				} else {
					var area = document.getElementById('ojsbr-rd-roster-text');
					if (area) {
						area.select();
						document.execCommand('copy');
						done();
					}
				}
			}

			// ---- Export ----
			function download() {
				var params = tab.value === 'roster' ? rosterParams() : requestParams();
				params.format = exportFormat.value;
				showExport.value = false;
				var url = tab.value === 'roster' ? c.rosterExportUrl : c.exportUrl;
				window.location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + toSearch(params);
			}

			function setTab(name) {
				tab.value = name;
				showExport.value = false;
				if (name === 'roster' && !roster.value) {
					loadRoster();
				} else {
					updateAddress();
				}
			}

			// ---- Start: the address may bring filters (a shared link). ----
			if (Object.keys(initial).some(function (key) {
				return OWN_PARAMS.indexOf(key) === -1;
			})) {
				filters.initFiltersFormFromQueryParams(initial);
			}
			group.value = typeof initial.group === 'string' ? initial.group : '';
			searchPhrase.value = typeof initial.searchPhrase === 'string' ? initial.searchPhrase : '';
			if (initial.orderBy) {
				orderBy.value = initial.orderBy;
				orderDirection.value = initial.orderDirection === 'DESC' ? 'DESC' : 'ASC';
			}
			['dateFrom', 'dateTo', 'issueId'].forEach(function (name) {
				var field = getField(rosterForm, name);
				if (field && typeof initial[name] === 'string') {
					field.value = name === 'issueId' ? parseInt(initial[name], 10) || 0 : initial[name];
				}
			});
			applied.value = JSON.parse(JSON.stringify(filters.filtersFormQueryParamsApi.value));
			page.value = Math.max(1, parseInt(initial.page, 10) || 1);
			showFilters.value = Object.keys(applied.value).some(function (key) {
				return key !== 'situations' && hasValue(applied.value[key]);
			});
			load();
			if (tab.value === 'roster') {
				loadRoster();
			}

			return {
				c: c, t: t, format: format, tab: tab, setTab: setTab,
				filtersForm: filtersForm, otherFilters: otherFilters, hasOtherFilters: hasOtherFilters,
				group: group, groupNames: groupNames, searchPhrase: searchPhrase, page: page,
				loading: loading, failed: failed, result: result, showFilters: showFilters,
				showExport: showExport, exportFormat: exportFormat, visibleColumns: visibleColumns,
				visibleColumnIds: visibleColumnIds, sortDescriptor: sortDescriptor, situationNames: situationNames,
				summary: summary, selectedSituations: selectedSituations, idleWarning: IDLE_WARNING,
				load: load, applyFilters: applyFilters, clearFilters: clearFilters, removeFilter: removeFilter,
				selectGroup: selectGroup, toggleSituation: toggleSituation, clearSituations: clearSituations,
				onSearch: onSearch, onSort: onSort, setPage: setPage, toggleColumn: toggleColumn,
				resetColumns: resetColumns, stars: stars,
				onFormSet: function (formId, data) {
					filters.updateFiltersForm(data);
				},
				rosterForm: rosterForm, roster: roster, rosterLoading: rosterLoading, rosterFailed: rosterFailed,
				onRosterSet: onRosterSet, loadRoster: loadRoster, rosterText: rosterText, copyRoster: copyRoster,
				copied: copied, download: download
			};
		},
		template:
'<div class="ojsbrRd">' +
'  <div class="ojsbrRd__top">' +
'    <div class="ojsbrRd__tabs" role="tablist">' +
'      <button type="button" role="tab" class="ojsbrRd__tab" :class="{\'is-active\': tab === \'directory\'}" :aria-selected="tab === \'directory\' ? \'true\' : \'false\'" data-rd="tab-directory" @click="setTab(\'directory\')">{{ t.tabDirectory }}</button>' +
'      <button type="button" role="tab" class="ojsbrRd__tab" :class="{\'is-active\': tab === \'roster\'}" :aria-selected="tab === \'roster\' ? \'true\' : \'false\'" data-rd="tab-roster" @click="setTab(\'roster\')">{{ t.tabRoster }}</button>' +
'    </div>' +
'    <p class="ojsbrRd__generated" aria-live="polite">' +
'      <span v-if="tab === \'directory\' && result">{{ format(t.generatedAt, {date: result.generatedAt}) }}</span>' +
'      <span v-if="tab === \'roster\' && roster">{{ format(t.generatedAt, {date: roster.generatedAt}) }}</span>' +
'      <PkpSpinner v-if="loading || rosterLoading"></PkpSpinner>' +
'    </p>' +
'    <div class="ojsbrRd__exportWrap">' +
'      <PkpButton :is-primary="true" :aria-expanded="showExport ? \'true\' : \'false\'" aria-controls="ojsbr-rd-export" :is-disabled="tab === \'roster\' && !(roster && roster.reviewers)" @click="showExport = !showExport">{{ t.export }}</PkpButton>' +
'      <div v-if="showExport" id="ojsbr-rd-export" class="ojsbrRd__export" role="group" :aria-label="t.exportTitle">' +
'        <p class="ojsbrRd__exportTitle">{{ t.exportTitle }}</p>' +
'        <fieldset><legend>{{ t.exportFormat }}</legend>' +
'          <label v-for="item in c.exportFormats" :key="item.value"><input type="radio" name="ojsbrRdFormat" :value="item.value" v-model="exportFormat"> {{ item.label }}</label>' +
'        </fieldset>' +
'        <div class="ojsbrRd__buttons">' +
'          <PkpButton @click="showExport = false">{{ t.cancel }}</PkpButton>' +
'          <PkpButton :is-primary="true" data-rd="download" @click="download">{{ t.download }}</PkpButton>' +
'        </div>' +
'      </div>' +
'    </div>' +
'  </div>' +

// ================= Directory =================
'  <div v-show="tab === \'directory\'" role="tabpanel">' +
'    <section class="ojsbrRd__panel" aria-labelledby="ojsbr-rd-indices">' +
'      <h2 id="ojsbr-rd-indices" class="ojsbrRd__heading">{{ t.indicesHeading }}</h2>' +
'      <p class="ojsbrRd__note">{{ t.indicesNote }} <strong v-if="hasOtherFilters">{{ t.indicesFiltered }}</strong></p>' +
'      <ul class="ojsbrRd__tiles">' +
'        <li class="ojsbrRd__tile" :class="{\'is-active\': group === \'\'}" data-group="">' +
'          <button type="button" class="ojsbrRd__tileButton" :aria-pressed="group === \'\' ? \'true\' : \'false\'" @click="selectGroup(\'\')">' +
'            <span class="ojsbrRd__tileCount">{{ result ? result.indices.total : \'–\' }}</span><span class="ojsbrRd__tileName">{{ t.allReviewers }}</span>' +
'          </button>' +
'        </li>' +
'        <li v-for="item in c.groups" :key="item.id" class="ojsbrRd__tile" :class="[\'ojsbrRd__tile--\' + item.id, {\'is-active\': group === item.id, \'is-zero\': result && !result.indices[item.id]}]" :data-group="item.id">' +
'          <button type="button" class="ojsbrRd__tileButton" :title="item.description" :aria-pressed="group === item.id ? \'true\' : \'false\'" @click="selectGroup(item.id)">' +
'            <span class="ojsbrRd__tileCount">{{ result ? result.indices[item.id] : \'–\' }}</span><span class="ojsbrRd__tileName">{{ item.name }}</span>' +
'          </button>' +
'        </li>' +
'      </ul>' +
'      <h3 class="ojsbrRd__subheading">{{ t.ratesHeading }}</h3>' +
'      <ul class="ojsbrRd__kpis" v-if="result">' +
'        <li class="ojsbrRd__kpi" :title="t[\'rate.acceptance.description\']"><span class="ojsbrRd__kpiValue">{{ result.rates.acceptance === null ? \'–\' : format(t.percent, {percent: result.rates.acceptance}) }}</span><span class="ojsbrRd__kpiName">{{ t[\'rate.acceptance\'] }}</span><span class="ojsbrRd__meter" aria-hidden="true"><span :style="{width: (result.rates.acceptance || 0) + \'%\'}"></span></span></li>' +
'        <li class="ojsbrRd__kpi" :title="t[\'rate.onTime.description\']"><span class="ojsbrRd__kpiValue">{{ result.rates.onTime === null ? \'–\' : format(t.percent, {percent: result.rates.onTime}) }}</span><span class="ojsbrRd__kpiName">{{ t[\'rate.onTime\'] }}</span><span class="ojsbrRd__meter" aria-hidden="true"><span :style="{width: (result.rates.onTime || 0) + \'%\'}"></span></span></li>' +
'        <li class="ojsbrRd__kpi" :title="t[\'rate.averageDays.description\']"><span class="ojsbrRd__kpiValue">{{ result.rates.averageDays === null ? \'–\' : format(t.days, {days: result.rates.averageDays}) }}</span><span class="ojsbrRd__kpiName">{{ t[\'rate.averageDays\'] }}</span></li>' +
'        <li class="ojsbrRd__kpi" :title="t[\'rate.reviewsInProgress.description\']"><span class="ojsbrRd__kpiValue">{{ result.indices.reviewsInProgress }}</span><span class="ojsbrRd__kpiName">{{ t[\'rate.reviewsInProgress\'] }}</span></li>' +
'        <li class="ojsbrRd__kpi" :title="t[\'rate.reviewsRecent.description\']"><span class="ojsbrRd__kpiValue">{{ result.indices.reviewsRecent }}</span><span class="ojsbrRd__kpiName">{{ t[\'rate.reviewsRecent\'] }}</span></li>' +
'      </ul>' +
'    </section>' +

'    <section class="ojsbrRd__panel" aria-labelledby="ojsbr-rd-summary">' +
'      <h2 id="ojsbr-rd-summary" class="ojsbrRd__heading">{{ t.summaryHeading }} <span class="ojsbrRd__muted" v-if="result">{{ format(t.totalCount, {n: summary.total}) }}</span></h2>' +
'      <p class="ojsbrRd__note">{{ t.summaryDescription }}</p>' +
'      <div v-if="summary.total" class="ojsbrRd__bar" aria-hidden="true">' +
'        <span v-for="item in summary.items" :key="item.id" v-show="item.count" class="ojsbrRd__barPart" :class="[\'ojsbrRd--\' + item.id, {\'is-dimmed\': selectedSituations.length && !item.isActive}]" :style="{flexGrow: item.count}" :title="item.name + \': \' + item.count"></span>' +
'      </div>' +
'      <ul class="ojsbrRd__legend">' +
'        <li v-for="item in summary.items" :key="item.id">' +
'          <button type="button" class="ojsbrRd__legendButton" :class="{\'is-active\': item.isActive, \'is-zero\': !item.count}" :title="item.description" :aria-pressed="item.isActive ? \'true\' : \'false\'" :data-situation="item.id" @click="toggleSituation(item.id)">' +
'            <span class="ojsbrRd__dot" :class="\'ojsbrRd--\' + item.id" aria-hidden="true"></span>' +
'            <span>{{ item.name }}</span> <strong>{{ item.count }}</strong> <span class="ojsbrRd__muted">{{ format(t.percent, {percent: item.percent}) }}</span>' +
'          </button>' +
'        </li>' +
'      </ul>' +
'      <PkpButton v-if="selectedSituations.length" :is-link="true" @click="clearSituations">{{ t.summaryClear }}</PkpButton>' +
'    </section>' +

'    <section class="ojsbrRd__panel">' +
'      <div class="ojsbrRd__filtersBar">' +
'        <PkpButton :aria-expanded="showFilters ? \'true\' : \'false\'" aria-controls="ojsbr-rd-filters" @click="showFilters = !showFilters">{{ showFilters ? t.filtersHide : t.filtersShow }}</PkpButton>' +
'        <ul v-if="otherFilters.length || group" class="ojsbrRd__chips" :aria-label="t.activeFilters">' +
'          <li v-if="group"><button type="button" class="ojsbrRd__chip" @click="selectGroup(group)">{{ groupNames[group] }} <span aria-hidden="true">×</span></button></li>' +
'          <li v-for="item in otherFilters" :key="item.name + \'-\' + item.value"><button type="button" class="ojsbrRd__chip" @click="removeFilter(item)">{{ item.text }} <span aria-hidden="true">×</span></button></li>' +
'        </ul>' +
'        <PkpButton v-if="hasOtherFilters || selectedSituations.length" :is-link="true" @click="clearFilters">{{ t.filtersClear }}</PkpButton>' +
'      </div>' +
'      <div v-show="showFilters" id="ojsbr-rd-filters" class="ojsbrRd__filters">' +
'        <PkpForm v-bind="filtersForm" @set="onFormSet"></PkpForm>' +
'        <div class="ojsbrRd__buttons">' +
'          <PkpButton @click="clearFilters">{{ t.filtersClear }}</PkpButton>' +
'          <PkpButton :is-primary="true" data-rd="apply" @click="applyFilters">{{ t.filtersApply }}</PkpButton>' +
'        </div>' +
'      </div>' +
'    </section>' +

'    <section class="ojsbrRd__panel" id="ojsbr-rd-table">' +
'      <div class="ojsbrRd__toolbar">' +
'        <div class="ojsbrRd__search"><PkpSearch :search-phrase="searchPhrase" :search-label="t.searchPlaceholder" @search-phrase-changed="onSearch"></PkpSearch></div>' +
'        <span class="ojsbrRd__count" v-if="result" aria-live="polite">{{ format(t.totalCount, {n: result.total}) }}</span>' +
'        <details class="ojsbrRd__columns">' +
'          <summary>{{ t.columns }}</summary>' +
'          <div class="ojsbrRd__columnsList">' +
'            <label v-for="column in c.columns" :key="column.id" v-show="column.id !== \'name\'"><input type="checkbox" :checked="visibleColumnIds.indexOf(column.id) !== -1" @change="toggleColumn(column.id)"> {{ column.label }}</label>' +
'            <PkpButton :is-link="true" @click="resetColumns">{{ t.columnsReset }}</PkpButton>' +
'          </div>' +
'        </details>' +
'      </div>' +
'      <div v-if="failed" class="ojsbrRd__error" role="alert">{{ t.loadError }} <PkpButton @click="load">{{ t.retry }}</PkpButton></div>' +
'      <div class="ojsbrRd__tableWrap" :class="{\'is-loading\': loading}">' +
'        <PkpTable :aria-label="t.tabDirectory" :sort-descriptor="sortDescriptor" @sort="onSort">' +
'          <PkpTableHeader>' +
'            <PkpTableColumn v-for="column in visibleColumns" :key="column.id" :id="column.id" :allows-sorting="!!column.sort"><span :title="column.description">{{ column.label }}</span></PkpTableColumn>' +
'          </PkpTableHeader>' +
'          <PkpTableBody :empty-text="result ? t.empty : t.loading">' +
'            <PkpTableRow v-for="r in (result ? result.rows : [])" :key="r.id">' +
'              <PkpTableCell v-for="column in visibleColumns" :key="column.id" :class="\'ojsbrRd__cell--\' + column.id">' +
'                <template v-if="column.id === \'name\'"><span class="ojsbrRd__name">{{ r.name }}</span><a v-if="visibleColumnIds.indexOf(\'email\') === -1 && r.email" class="ojsbrRd__sub" :href="\'mailto:\' + r.email" :title="format(t.mailto, {name: r.name})">{{ r.email }}</a></template>' +
'                <template v-else-if="column.id === \'affiliation\'">{{ r.affiliation }}</template>' +
'                <template v-else-if="column.id === \'country\'">{{ r.countryName }}</template>' +
'                <template v-else-if="column.id === \'orcid\'"><a v-if="r.orcid" :href="r.orcid" target="_blank" rel="noopener noreferrer" class="ojsbrRd__nowrap"><span v-if="r.orcidVerified" class="ojsbrRd__verified" :title="t.orcidVerified">✓ </span>{{ r.orcidId }}</a></template>' +
'                <template v-else-if="column.id === \'email\'"><a :href="\'mailto:\' + r.email">{{ r.email }}</a></template>' +
'                <template v-else-if="column.id === \'username\'">{{ r.username }}</template>' +
'                <template v-else-if="column.id === \'interests\'"><ul class="ojsbrRd__tags"><li v-for="(name, i) in r.interests.slice(0, 8)" :key="i">{{ name }}</li><li v-if="r.interests.length > 8" class="ojsbrRd__muted">+{{ r.interests.length - 8 }}</li></ul></template>' +
'                <template v-else-if="column.id === \'situation\'"><span class="ojsbrRd__situation"><span class="ojsbrRd__dot" :class="\'ojsbrRd--\' + r.situation" aria-hidden="true"></span>{{ situationNames[r.situation] }}</span></template>' +
'                <template v-else-if="column.id === \'active\'"><strong>{{ r.active }}</strong><span v-if="r.activeSubmissions.length" class="ojsbrRd__sub"><template v-for="(s, i) in r.activeSubmissions" :key="s.id"><a :href="s.url" target="_blank" rel="noopener" :title="format(t.openSubmission, {id: s.id})">#{{ s.id }}</a><template v-if="i < r.activeSubmissions.length - 1">, </template></template></span></template>' +
'                <template v-else-if="column.id === \'overdue\'"><strong :class="{\'is-danger\': r.overdue}">{{ r.overdue }}</strong></template>' +
'                <template v-else-if="column.id === \'completed\'">{{ r.completed }}</template>' +
'                <template v-else-if="column.id === \'recent\'">{{ r.recent }}</template>' +
'                <template v-else-if="column.id === \'requests\'">{{ r.requests }}</template>' +
'                <template v-else-if="column.id === \'acceptance\' || column.id === \'onTime\'"><template v-if="r[column.id] !== null"><span class="ojsbrRd__nowrap">{{ format(t.percent, {percent: r[column.id]}) }}</span><span class="ojsbrRd__meter ojsbrRd__meter--small" aria-hidden="true"><span :style="{width: r[column.id] + \'%\'}"></span></span></template><span v-else class="ojsbrRd__muted">–</span></template>' +
'                <template v-else-if="column.id === \'averageDays\'"><span v-if="r.averageDays !== null" class="ojsbrRd__nowrap">{{ format(t.days, {days: r.averageDays}) }}</span><span v-else class="ojsbrRd__muted">–</span></template>' +
'                <template v-else-if="column.id === \'lastCompleted\'"><template v-if="r.lastCompleted"><span class="ojsbrRd__nowrap">{{ r.lastCompleted }}</span><span class="ojsbrRd__sub" :class="{\'is-warning\': r.monthsIdle >= idleWarning}">{{ r.monthsIdle ? format(t.monthsAgo, {months: r.monthsIdle}) : t.thisMonth }}</span></template><span v-else class="ojsbrRd__muted">{{ t.none }}</span></template>' +
'                <template v-else-if="column.id === \'ojs\'"><span class="ojsbrRd__nowrap" :title="t.ojsNote">{{ r.ojsCompleted }} · {{ r.ojsDeclined }} · {{ r.ojsAverageDays === null ? \'–\' : format(t.days, {days: r.ojsAverageDays}) }}</span></template>' +
'                <template v-else-if="column.id === \'rating\'"><span v-if="r.rating" class="ojsbrRd__stars" :title="format(t.ratingOf, {rating: r.rating})">{{ stars(r.rating) }}</span></template>' +
'                <template v-else-if="column.id === \'lastAssigned\'"><span class="ojsbrRd__nowrap">{{ r.lastAssigned }}</span></template>' +
'              </PkpTableCell>' +
'            </PkpTableRow>' +
'          </PkpTableBody>' +
'        </PkpTable>' +
'      </div>' +
'      <div class="ojsbrRd__pagination" v-if="result && result.lastPage > 1">' +
'        <span class="ojsbrRd__muted">{{ format(t.pageOf, {page: result.page, lastPage: result.lastPage}) }}</span>' +
'        <PkpPagination :current-page="page" :last-page="result.lastPage" :is-loading="loading" @set-page="setPage"></PkpPagination>' +
'      </div>' +
'    </section>' +
'  </div>' +

// ================= Roster =================
'  <div v-show="tab === \'roster\'" role="tabpanel">' +
'    <section class="ojsbrRd__panel">' +
'      <p class="ojsbrRd__note">{{ t[\'roster.intro\'] }}</p>' +
'      <div class="ojsbrRd__rosterForm">' +
'        <PkpForm v-bind="rosterForm" @set="onRosterSet"></PkpForm>' +
'        <div class="ojsbrRd__buttons"><PkpButton :is-primary="true" data-rd="roster" @click="loadRoster">{{ t[\'roster.generate\'] }}</PkpButton></div>' +
'      </div>' +
'    </section>' +
'    <div v-if="rosterFailed" class="ojsbrRd__error" role="alert">{{ t.loadError }} <PkpButton @click="loadRoster">{{ t.retry }}</PkpButton></div>' +
'    <template v-if="roster">' +
'      <section class="ojsbrRd__panel">' +
'        <h2 class="ojsbrRd__heading">{{ t.tabRoster }} <span class="ojsbrRd__muted" v-if="roster.issueLabel">— {{ roster.issueLabel }}</span></h2>' +
'        <ul class="ojsbrRd__tiles ojsbrRd__tiles--static">' +
'          <li class="ojsbrRd__tile"><div class="ojsbrRd__tileButton"><span class="ojsbrRd__tileCount">{{ roster.reviewers }}</span><span class="ojsbrRd__tileName">{{ t[\'roster.reviewers\'] }}</span></div></li>' +
'          <li class="ojsbrRd__tile"><div class="ojsbrRd__tileButton"><span class="ojsbrRd__tileCount">{{ roster.reviews }}</span><span class="ojsbrRd__tileName">{{ t[\'roster.reviews\'] }}</span></div></li>' +
'          <li class="ojsbrRd__tile"><div class="ojsbrRd__tileButton"><span class="ojsbrRd__tileCount">{{ roster.submissions }}</span><span class="ojsbrRd__tileName">{{ t[\'roster.submissions\'] }}</span></div></li>' +
'          <li class="ojsbrRd__tile"><div class="ojsbrRd__tileButton"><span class="ojsbrRd__tileCount">{{ roster.countries }}</span><span class="ojsbrRd__tileName">{{ t[\'roster.countries\'] }}</span></div></li>' +
'        </ul>' +
'      </section>' +
'      <section class="ojsbrRd__panel">' +
'        <div class="ojsbrRd__tableWrap" :class="{\'is-loading\': rosterLoading}">' +
'          <PkpTable :aria-label="t.tabRoster">' +
'            <PkpTableHeader>' +
'              <PkpTableColumn>{{ t.name }}</PkpTableColumn><PkpTableColumn>{{ t.affiliation }}</PkpTableColumn><PkpTableColumn>{{ t.country }}</PkpTableColumn><PkpTableColumn>{{ t.orcid }}</PkpTableColumn><PkpTableColumn>{{ t.email }}</PkpTableColumn>' +
'              <PkpTableColumn>{{ t[\'roster.reviews\'] }}</PkpTableColumn><PkpTableColumn>{{ t[\'roster.submissions\'] }}</PkpTableColumn><PkpTableColumn>{{ t[\'roster.firstDate\'] }}</PkpTableColumn><PkpTableColumn>{{ t[\'roster.lastDate\'] }}</PkpTableColumn>' +
'            </PkpTableHeader>' +
'            <PkpTableBody :empty-text="t[\'roster.empty\']">' +
'              <PkpTableRow v-for="r in roster.rows" :key="r.id">' +
'                <PkpTableCell><span class="ojsbrRd__name">{{ r.name }}</span></PkpTableCell>' +
'                <PkpTableCell>{{ r.affiliation }}</PkpTableCell>' +
'                <PkpTableCell>{{ r.countryName }}</PkpTableCell>' +
'                <PkpTableCell><a v-if="r.orcid" :href="r.orcid" target="_blank" rel="noopener noreferrer" class="ojsbrRd__nowrap">{{ r.orcidId }}</a></PkpTableCell>' +
'                <PkpTableCell><a :href="\'mailto:\' + r.email">{{ r.email }}</a></PkpTableCell>' +
'                <PkpTableCell><strong>{{ r.count }}</strong></PkpTableCell>' +
'                <PkpTableCell>{{ r.submissions.map(function (id) { return \'#\' + id; }).join(\', \') }}</PkpTableCell>' +
'                <PkpTableCell><span class="ojsbrRd__nowrap">{{ r.firstDate }}</span></PkpTableCell>' +
'                <PkpTableCell><span class="ojsbrRd__nowrap">{{ r.lastDate }}</span></PkpTableCell>' +
'              </PkpTableRow>' +
'            </PkpTableBody>' +
'          </PkpTable>' +
'        </div>' +
'      </section>' +
'      <section class="ojsbrRd__panel" v-if="roster.reviewers">' +
'        <h2 class="ojsbrRd__heading">{{ t[\'roster.copy\'] }}</h2>' +
'        <p class="ojsbrRd__note">{{ t[\'roster.copyDescription\'] }}</p>' +
'        <textarea id="ojsbr-rd-roster-text" class="ojsbrRd__rosterText" readonly :value="rosterText" :aria-label="t[\'roster.copy\']"></textarea>' +
'        <div class="ojsbrRd__buttons"><span v-if="copied" class="ojsbrRd__muted" role="status">{{ t[\'roster.copied\'] }}</span><PkpButton data-rd="copy" @click="copyRoster">{{ t[\'roster.copy\'] }}</PkpButton></div>' +
'      </section>' +
'    </template>' +
'  </div>' +
'</div>'
	});
})();

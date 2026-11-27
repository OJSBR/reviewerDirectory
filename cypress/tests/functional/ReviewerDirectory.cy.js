/**
 * @file cypress/tests/functional/ReviewerDirectory.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests of the reviewer directory, through the page: it opens from the menu;
 * the numbers OJS/OMP shows when choosing a reviewer are the ones the directory shows,
 * for every reviewer; the groups and the situations add up; the roster of a period is
 * built, with its text for publishing; the export has the rows of the screen; and a
 * reviewer gets no directory at all.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword, reviewerUser,
 * reviewerPassword (captcha on login must be off for the run). The defaults match the
 * data set of PKP's continuous integration, whose journal or press has reviewers. The
 * first test enables the plugin when it is off. Nothing is changed on the server.
 */

describe('Reviewer Directory plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const reviewerUser = Cypress.env('reviewerUser') || 'agallego';
	const reviewerPassword = Cypress.env('reviewerPassword') || 'agallegoagallego';

	const row = 'reviewerdirectoryplugin';

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	// jQuery may not be on the page yet when this runs, so the check retries on the window
	// itself instead of on a property that would resolve as undefined.
	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => {
		expect(win.jQuery && win.jQuery.active, 'pending jQuery requests').to.eq(0);
	});

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----

	const openDirectory = (query) => {
		cy.intercept('GET', '**/reviewerdirectory/data*').as('data');
		cy.visit(pageUrl('reviewerdirectory') + '?reload=' + Date.now() + (query || ''));
		cy.wait('@data', {timeout: 60000}).its('response.statusCode').should('eq', 200);
		cy.get('.ojsbrRd__tile', {timeout: 60000}).should('have.length.greaterThan', 1);
	};

	const tileCount = (group) => cy.get('[data-group="' + group + '"] .ojsbrRd__tileCount').invoke('text').then(Number);

	it('Enables the plugin and opens the directory from the menu', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(row);
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('a[href*="/reviewerdirectory"]', {timeout: 60000}).first().click({force: true});
		cy.get('.ojsbrRd', {timeout: 60000}).should('be.visible');
	});

	it('Keeps the page to signed-in editors', function() {
		cy.clearCookies();
		// OJS 3.5 may first redirect to the URL with the language; the page ends at the login form.
		request({url: pageUrl('reviewerdirectory'), failOnStatusCode: false}).then((response) => {
			expect(response.body).to.match(/<form[^>]*id="login"/);
			expect(response.body).to.not.contain('ojsbr-reviewer-directory');
		});
	});

	it('Shows, for every reviewer, the numbers OJS shows when choosing a reviewer', function() {
		login(adminUser, adminPassword);
		openDirectory();
		api(pageUrl('reviewerdirectory/data')).then((data) => {
			const rows = {};
			data.content.rows.forEach((r) => {
				rows[r.id] = r;
			});
			return api(pageUrl('api/v1/users/reviewers?count=100')).then((reviewers) => {
				expect(data.content.total, 'reviewers listed').to.eq(reviewers.itemsMax);
				expect(reviewers.items, 'the data set has reviewers').to.not.be.empty;
				reviewers.items.forEach((reviewer) => {
					const r = rows[reviewer.id];
					if (!r) {
						return; // On a later page of the directory.
					}
					expect(r.ojsCompleted, 'completed by ' + reviewer.id).to.eq(Number(reviewer.reviewsCompleted || 0));
					expect(r.ojsDeclined, 'declined by ' + reviewer.id).to.eq(Number(reviewer.reviewsDeclined || 0));
					expect(r.ojsActive, 'active for ' + reviewer.id).to.eq(Number(reviewer.reviewsActive || 0));
					expect(Number(r.ojsAverageDays || 0), 'average days of ' + reviewer.id).to.eq(Number(reviewer.averageReviewCompletionDays || 0));
					expect(r.rating || null, 'rating of ' + reviewer.id).to.eq(reviewer.reviewerRating || null);
					expect(r.lastAssigned, 'last assignment of ' + reviewer.id).to.eq((reviewer.dateLastReviewAssignment || '').slice(0, 10));
				});
			});
		});
	});

	it('Adds up the situations and lists a group', function() {
		login(adminUser, adminPassword);
		openDirectory();
		tileCount('').then((total) => {
			cy.get('.ojsbrRd__legendButton strong').then(($counts) => {
				const sum = $counts.toArray().reduce((n, el) => n + Number(el.textContent), 0);
				expect(sum, 'the situations add up to the reviewers').to.eq(total);
			});
		});
		tileCount('available').then((available) => {
			cy.get('[data-group="available"] button').click();
			cy.wait('@data');
			cy.location('search').should('contain', 'group=available');
			cy.get('.ojsbrRd__count').should('contain', String(available));
		});
	});

	it('Builds the roster of a period, with its text for publishing', function() {
		login(adminUser, adminPassword);
		cy.intercept('GET', '**/reviewerdirectory/roster*').as('roster');
		cy.visit(pageUrl('reviewerdirectory') + '?tab=roster&dateFrom=2000-01-01&dateTo=2099-12-31&reload=' + Date.now());
		cy.wait('@roster', {timeout: 60000}).then((interception) => {
			const roster = interception.response.body.content;
			cy.get('.ojsbrRd__tiles--static .ojsbrRd__tileCount').first().should('have.text', String(roster.reviewers));
			if (roster.reviewers) {
				cy.get('#ojsbr-rd-roster-text').invoke('val').then((text) => {
					expect(text.split('\n'), 'one line per reviewer').to.have.length(roster.reviewers);
				});
			}
		});
		// Journals can filter the roster by issue; a press has no issues and no field for them.
		request({url: pageUrl('api/v1/issues?count=1'), failOnStatusCode: false}).then((response) => {
			cy.get('.ojsbrRd__rosterForm select').should(response.status === 404 ? 'not.exist' : 'exist');
		});
	});

	it('Exports the reviewers of the screen as CSV', function() {
		login(adminUser, adminPassword);
		openDirectory();
		tileCount('').then((total) => {
			request({url: pageUrl('reviewerdirectory/export') + '?format=csv', encoding: 'binary'}).then((response) => {
				expect(response.headers['content-type']).to.contain('text/csv');
				expect(response.body.slice(0, 3)).to.eq('\xEF\xBB\xBF');
				let records = 0;
				let quoted = false;
				for (const character of response.body) {
					if (character === '"') {
						quoted = !quoted;
					} else if (character === '\n' && !quoted) {
						records++;
					}
				}
				expect(records, 'the line of the filters, the header and one line per reviewer').to.eq(total + 2);
			});
		});
	});

	it('Gives a reviewer no directory', function() {
		login(reviewerUser, reviewerPassword);
		cy.get('a[href*="/reviewerdirectory"]').should('not.exist');
		['reviewerdirectory', 'reviewerdirectory/data', 'reviewerdirectory/export?format=csv', 'reviewerdirectory/roster', 'reviewerdirectory/rosterExport?format=csv'].forEach((path) => {
			request({url: pageUrl(path), failOnStatusCode: false}).then((response) => {
				expect(response.body).not.to.contain('ojsbr-reviewer-directory');
				expect(response.body).not.to.contain('"rows"');
				expect(response.redirects ? response.redirects.join(' ') : '').to.contain('authorizationDenied');
			});
		});
	});
});

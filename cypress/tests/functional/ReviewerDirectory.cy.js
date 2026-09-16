/**
 * @file cypress/tests/functional/ReviewerDirectory.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the reviewer directory page an editor opens.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration, whose journal has reviewers. The first test enables the plugin
 * when it is off. Nothing is changed on the server.
 */

describe('Reviewer Directory plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

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
		cy.visit(pageUrl('reviewerdirectory') + '?reload=' + Date.now() + (query || ''));
		cy.get('.rd-wrapper', {timeout: 30000}).should('exist');
	};

	it('Enables the plugin', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(row);
	});

	it('Keeps the page to signed-in editors', function() {
		cy.clearCookies();
		// OJS 3.5 may first redirect to the URL with the language; the page ends at the login form.
		request({url: pageUrl('reviewerdirectory'), failOnStatusCode: false}).then((response) => {
			expect(response.body).to.match(/<form[^>]*id="login"/);
			expect(response.body).to.not.contain('rd-wrapper');
		});
	});

	it('Lists the reviewers, filters them and hides columns', function() {
		login(adminUser, adminPassword);
		openDirectory();
		cy.get('link[href*="/reviewerDirectory/css/reviewerDirectory.css"]').should('have.length', 1);
		cy.get('script[src*="/reviewerDirectory/js/reviewerDirectory.js"]').should('have.length', 1);
		// The shortcut at the end of the backend menu.
		cy.get('a[href*="/reviewerdirectory"]').should('exist');

		cy.get('#rd-table-directory tbody tr[data-rd-row]').should('have.length.at.least', 1).then(($rows) => {
			cy.get('#rd-shown').should('have.text', String($rows.length));
			const name = $rows.first().find('.rd-name').text().trim();

			cy.get('#rd-search').type(name.split(/\s+/)[0].toLowerCase());
			cy.get('#rd-table-directory tbody tr[data-rd-row]:visible').should('have.length.at.least', 1).first().find('.rd-name').should('contain', name.split(/\s+/)[0]);
			cy.get('#rd-search').clear().type('zz-no-reviewer-has-this-zz');
			cy.get('#rd-table-directory tbody tr[data-rd-row]:visible').should('have.length', 0);
			cy.get('#rd-shown').should('have.text', '0');
			cy.get('#rd-search').clear();
			cy.get('#rd-shown').should('have.text', String($rows.length));
		});

		cy.get('th.rd-col-interests').should('not.have.class', 'rd-hidden');
		cy.get('.rd-colpicker input[data-col-key="interests"]').uncheck();
		cy.get('th.rd-col-interests').should('have.class', 'rd-hidden');
		cy.get('.rd-colpicker input[data-col-key="interests"]').check();
		cy.get('th.rd-col-interests').should('not.have.class', 'rd-hidden');
	});

	it('Builds the reviewer roster of a period', function() {
		login(adminUser, adminPassword);
		openDirectory('&rdNominata=1&rdDateFrom=2000-01-01&rdDateTo=2099-12-31&rdIssueId=0');
		cy.get('.rd-panel[data-panel="nominata"]').should('have.class', 'rd-active');
		cy.get('#rdDateFrom').should('have.value', '2000-01-01');
		// Journals group submissions in issues and can filter the roster by one; a press has
		// none, no issues endpoint either, and the field is not rendered at all.
		request({url: pageUrl('api/v1/issues?count=1'), failOnStatusCode: false}).then((response) => {
			cy.get('#rdIssueId').should(response.status === 404 ? 'not.exist' : 'exist');
		});
		// Either the roster or the message that nobody completed a review in the period.
		cy.get('.rd-panel[data-panel="nominata"]').find('#rd-table-nominata, .rd-empty').should('have.length', 1);
		cy.get('.rd-tab-btn[data-tab="directory"]').click();
		cy.get('.rd-panel[data-panel="directory"]').should('have.class', 'rd-active');

		// A date that is not a date is ignored. The attribute, as the server wrote it, is checked:
		// the browser itself empties an invalid value of a date field.
		openDirectory('&rdNominata=1&rdDateFrom=2026-02-30');
		cy.get('#rdDateFrom').should('have.attr', 'value', '');
	});
});

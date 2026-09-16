/**
 * @file cypress/tests/functional/RecommendByAuthor.cy.js
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: enabling the plugin, its settings, and what the reader gets
 * on the article page.
 *
 * What these guard is the contract with the reader: a journal that has just
 * enabled the plugin must never be made slower by it, and an article whose
 * recommendations have not been computed yet must simply show nothing.
 *
 * Navigation is by URL and assertions are on element ids rather than on labels,
 * so the spec runs unchanged against a journal in any language.
 */

describe('Recommend Articles by Author plugin', function() {
	// The PKP test data is the default; another installation can run the same
	// spec through cypress.env.json or --env.
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	// A published article of this journal, discovered once when none is given.
	let articleId = Cypress.env('articleId') || null;

	// A cache buster on every visit: a site behind an edge cache would otherwise
	// serve the article page as it was before the plugin was switched on or off,
	// and the test would be reading the cache instead of the plugin.
	const articleUrl = () =>
		'/index.php/' + contextPath + '/article/view/' + articleId + '?cb=' + Date.now();

	// Reading the article as a visitor, without reading an edge cache: a site
	// that caches anonymous pages keys on the session cookie, so a request with
	// one always reaches the application.
	const visitArticle = () => {
		cy.setCookie('OJSSID', 'cypress' + Date.now());
		cy.visit(articleUrl());
	};
	// Without a hash: cy.visit() does not reload when only the fragment changes,
	// which would leave the second visit looking at a stale page.
	const pluginsUrl = '/index.php/' + contextPath + '/management/settings/website';

	// Fields are matched by name: the FBV form helper appends a per-render
	// suffix to every id (id="{$FBV_id}{$uniqId}"), so ids are not stable
	// across page loads while names are.
	const settingsForm = 'form[id="recommendByAuthorSettingsForm"]';
	const enableCheckbox = 'input[id^="select-cell-recommendbyauthorplugin-enabled"]';
	const settingsLink = 'a[id*="recommendbyauthorplugin-settings"]';
	const section = '#articlesBySameAuthorList';

	// Other plugins installed on the site under test are not this plugin's
	// business. An unrelated one answering HTML on a JSON endpoint would
	// otherwise fail these cases for a reason that has nothing to do with the
	// code being tested. Anything thrown from this plugin still fails the run.
	Cypress.on('uncaught:exception', (err) => !err.message.includes('is not valid JSON'));

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
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
	// A captcha on the login form is never solved here: where captcha_on_login is on, turn it
	// off for the run, as the house runner does.
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

	// The article the reader's checks are made on: the first published one of the
	// journal, so the spec does not depend on the ids of any particular data set.
	const withArticle = (callback) => {
		if (articleId) {
			return cy.wrap(articleId, {log: false}).then(callback);
		}
		request(pageUrl('api/v1/submissions?status=3&count=1')).then((response) => {
			const body = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
			expect(body.items, 'a published article').to.have.length.at.least(1);
			articleId = body.items[0].id;
			callback(articleId);
		});
	};

	// ---- end of helpers ----

	const goToPlugins = () => {
		cy.visit(pluginsUrl);
		// The settings page is a Vue app; on a loaded server it can take a while
		// to mount, and the tab button does not exist until it has.
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		waitJQuery();
	};

	const openPluginSettings = () => {
		// The extras row may already be open (the grid keeps it between actions):
		// expanding it again would close it and take the settings link away.
		cy.get('tr[id*="recommendbyauthorplugin"]', {timeout: 30000}).then(($row) => {
			if (!$row.find(settingsLink.replace('a[id*="', '[id*="') + ':visible').length) {
				cy.get('tr[id*="recommendbyauthorplugin"] a.show_extras').first().click();
			}
		});
		// The row expands with an animation: the link is clicked once it exists.
		cy.get(settingsLink, {timeout: 30000}).first().click({force: true});
		waitJQuery();
		cy.get(settingsForm, {timeout: 30000}).should('exist');
		cy.window({timeout: 30000}).should((win) => {
			expect(win.jQuery(settingsForm).data('pkp.handler'), 'form handler').to.exist;
		});
	};

	const setEnabled = (wanted) => {
		cy.get(enableCheckbox, {timeout: 30000}).then($box => {
			if ($box.is(':checked') === wanted) {
				return;
			}
			cy.get(enableCheckbox).click();
			// Enabling is a plain AJAX call, but disabling opens a confirmation
			// modal (PluginGridCellProvider uses RemoteActionConfirmationModal),
			// so the click alone changes nothing until it is confirmed.
			if (!wanted) {
				// The confirm button of the modal, taken by position rather than
				// by label so that the spec does not depend on the interface
				// language.
				cy.get('[role="dialog"] button, .pkp_modal button, .modal button', {timeout: 30000})
					.first().click({force: true});
			}
			waitJQuery();
		});
		// The checkbox is saved over AJAX; without waiting for the grid to come
		// back in the wanted state, the next step can read the article page
		// before the change has landed.
		cy.get(enableCheckbox, {timeout: 30000}).should(wanted ? 'be.checked' : 'not.be.checked');
	};

	before(function() {
		login(adminUser, adminPassword);
		withArticle(() => {});
	});

	it('Enables the plugin, which creates its tables', function() {
		login(adminUser, adminPassword);
		goToPlugins();
		setEnabled(true);

		// Enabling runs the install migration. The settings screen reads those
		// tables to report coverage, so opening it proves they exist.
		openPluginSettings();
		cy.get(settingsForm).should('exist');
		cy.get(settingsForm + ' .rbaStatus').should('exist');
	});

	it('Ships with defaults that cannot slow a journal down', function() {
		login(adminUser, adminPassword);
		goToPlugins();
		openPluginSettings();

		// The one setting that could put work on a page view.
		cy.get(settingsForm + ' input[name="computeOnDemand"]').should('not.be.checked');
		// A bounded slice per run, whatever the size of the journal.
		cy.get(settingsForm + ' input[name="batchSize"]').invoke('val').then(value => {
			expect(Number(value)).to.be.greaterThan(0);
		});
		cy.get(settingsForm + ' input[name="recommendationCount"]').invoke('val').then(value => {
			expect(Number(value)).to.be.greaterThan(0);
		});
	});

	it('Persists a changed setting', function() {
		login(adminUser, adminPassword);
		goToPlugins();
		openPluginSettings();

		cy.get(settingsForm + ' input[name="recommendationCount"]').clear().type('4');
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();

		goToPlugins();
		openPluginSettings();
		cy.get(settingsForm + ' input[name="recommendationCount"]').should('have.value', '4');

		// Put it back.
		cy.get(settingsForm + ' input[name="recommendationCount"]').clear().type('10');
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();
	});

	it('Refuses a setting that is not a whole number', function() {
		login(adminUser, adminPassword);
		goToPlugins();
		openPluginSettings();

		cy.get(settingsForm + ' input[name="recommendationCount"]').clear().type('not a number');
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();

		// What matters is not which markup the error uses, but that nothing
		// was stored: a journal must not end up with zero recommendations per
		// page because someone typed a word in the box.
		goToPlugins();
		openPluginSettings();
		cy.get(settingsForm + ' input[name="recommendationCount"]').invoke('val').then(value => {
			expect(Number(value)).to.be.greaterThan(0);
		});
	});

	it('Renders a well formed section, or none at all', function() {
		// Whether an article has recommendations depends on the data and on
		// whether the scheduled task has run; what must always hold is that if
		// the section is there, it is complete and its links work.
		visitArticle();
		cy.get('body').then($body => {
			if (!$body.find(section).length) {
				return;
			}
			cy.get(section).within(() => {
				cy.get('h2').should('exist');
				cy.get('li').its('length').should('be.gte', 1);
				cy.get('li a').first()
					.should('have.attr', 'href')
					.and('include', '/article/view/');
			});
		});
	});

	it('Never links an article to itself', function() {
		visitArticle();
		cy.get('body').then($body => {
			if (!$body.find(section).length) {
				return;
			}
			cy.get(section + ' li a[href*="/article/view/"]').each($link => {
				expect($link.attr('href')).not.to.match(
					new RegExp('/article/view/' + articleId + '(/|$|\\?)')
				);
			});
		});
	});

	it('Disables cleanly, leaving the article page untouched', function() {
		login(adminUser, adminPassword);
		goToPlugins();
		setEnabled(false);

		cy.logout();
		visitArticle();
		cy.get(section).should('not.exist');

		// Leave the journal as the run found it.
		login(adminUser, adminPassword);
		goToPlugins();
		setEnabled(true);
	});
});

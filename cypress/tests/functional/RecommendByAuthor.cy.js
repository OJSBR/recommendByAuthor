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
	const articleId = Cypress.env('articleId') || 1;

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

	// A site with the Altcha captcha turned on for login (captcha_on_login)
	// expects a solved proof of work along with the form. The PKP test data has
	// it off, so this is a no-op there; solving it here is what lets the very
	// same spec run against a real installation, which is where the plugin has
	// to work anyway.
	const solveAltcha = (win, challenge) => {
		const encoder = new win.TextEncoder();
		const digest = async (number) => {
			const buffer = await win.crypto.subtle.digest(
				challenge.algorithm,
				encoder.encode(challenge.salt + number)
			);
			return [...new Uint8Array(buffer)].map(b => b.toString(16).padStart(2, '0')).join('');
		};
		return (async () => {
			for (let number = 0; number <= (challenge.maxnumber || 100000); number++) {
				if (await digest(number) === challenge.challenge) {
					return number;
				}
			}
			throw new Error('the Altcha challenge could not be solved');
		})();
	};

	const login = () => {
		cy.visit('/index.php/' + contextPath + '/en/login');
		cy.get('input[id=username]').clear().type(adminUser, {delay: 0});
		cy.get('input[id=password]').clear().type(adminPassword, {delay: 0});

		cy.window().then(win => {
			const widget = win.document.querySelector('altcha-widget');
			if (!widget) {
				return;
			}
			const challenge = JSON.parse(widget.getAttribute('challengejson'));
			return solveAltcha(win, challenge).then(number => {
				const input = win.document.createElement('input');
				input.type = 'hidden';
				input.name = 'altcha';
				input.value = win.btoa(JSON.stringify({
					algorithm: challenge.algorithm,
					challenge: challenge.challenge,
					number: number,
					salt: challenge.salt,
					signature: challenge.signature,
					took: 1,
				}));
				win.document.querySelector('form[id=login]').appendChild(input);
				// The floating widget hooks the submit event and would replace
				// what was just put there.
				widget.remove();
			});
		});

		cy.get('form[id=login] button').click();
		// The form posts to /login/signIn, so the path alone does not say whether
		// the credentials were accepted; the login form being gone does.
		cy.get('form[id=login]', {timeout: 30000}).should('not.exist');
	};

	const goToPlugins = () => {
		cy.visit(pluginsUrl);
		// The settings page is a Vue app; on a loaded server it can take a while
		// to mount, and the tab button does not exist until it has.
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.waitJQuery();
	};

	const openPluginSettings = () => {
		// The row expands with an animation; the settings link does not exist
		// until it has finished.
		cy.get('tr[id*="recommendbyauthorplugin"] a.show_extras', {timeout: 30000}).click();
		cy.get(settingsLink, {timeout: 30000}).should('be.visible').click();
		cy.waitJQuery();
		cy.get(settingsForm, {timeout: 30000}).should('exist');
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
			cy.waitJQuery();
		});
		// The checkbox is saved over AJAX; without waiting for the grid to come
		// back in the wanted state, the next step can read the article page
		// before the change has landed.
		cy.get(enableCheckbox, {timeout: 30000}).should(wanted ? 'be.checked' : 'not.be.checked');
	};

	it('Enables the plugin, which creates its tables', function() {
		login();
		goToPlugins();
		setEnabled(true);

		// Enabling runs the install migration. The settings screen reads those
		// tables to report coverage, so opening it proves they exist.
		openPluginSettings();
		cy.get(settingsForm).should('exist');
		cy.get(settingsForm + ' .rbaStatus').should('exist');
	});

	it('Ships with defaults that cannot slow a journal down', function() {
		login();
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
		login();
		goToPlugins();
		openPluginSettings();

		cy.get(settingsForm + ' input[name="recommendationCount"]').clear().type('4');
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		cy.waitJQuery();

		goToPlugins();
		openPluginSettings();
		cy.get(settingsForm + ' input[name="recommendationCount"]').should('have.value', '4');

		// Put it back.
		cy.get(settingsForm + ' input[name="recommendationCount"]').clear().type('10');
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		cy.waitJQuery();
	});

	it('Refuses a setting that is not a whole number', function() {
		login();
		goToPlugins();
		openPluginSettings();

		cy.get(settingsForm + ' input[name="recommendationCount"]').clear().type('not a number');
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		cy.waitJQuery();

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
		login();
		goToPlugins();
		setEnabled(false);

		cy.logout();
		visitArticle();
		cy.get(section).should('not.exist');

		// Leave the journal as the run found it.
		login();
		goToPlugins();
		setEnabled(true);
	});
});

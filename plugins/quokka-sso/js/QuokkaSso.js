(rl => { if (rl) {

	// The sign-in page: "Sign in with Pocket ID" under the usual form, when the server has Pocket ID configured
	addEventListener('rl-view-model', e => {
		const vm = e.detail;
		if (!vm || 'Login' !== vm.viewModelTemplateID || !rl.settings.get('QuokkaSso')) return;
		const form = vm.viewModelDom?.querySelector('form');
		if (!form || form.querySelector('.quokka-sso')) return;
		const box = document.createElement('div');
		box.className = 'quokka-sso';
		const t = key => rl.i18n('QUOKKA_SSO/' + key).replace(/[&<>"]/g, c => '&#' + c.charCodeAt(0) + ';');
		box.innerHTML = '<div class="quokka-sso-or"><span>' + t('OR') + '</span></div>'
			+ '<a class="btn quokka-sso-button" href="./?QuokkaSso">'
			+ '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path></svg>'
			+ '<span>' + t('SIGN_IN_WITH') + '</span></a>';
		form.append(box);
	});

	// Settings → Sign-in: is this mailbox opened by a Pocket ID link? forget it
	class QuokkaSsoSettings
	{
		constructor()
		{
			this.configured = ko.observable(false);
			this.email = ko.observable('');
			this.linked = ko.observable(0);
			this.message = ko.observable('');
			// "You can also open <b>mario@…</b> with Pocket ID…": the address escaped, the rest from the translations
			this.linkedText = ko.computed(() => rl.i18n('QUOKKA_SSO/LINKED', {
				EMAIL: this.email().replace(/[&<>"]/g, c => '&#' + c.charCodeAt(0) + ';')
			}));
		}

		onShow()
		{
			this.message('');
			rl.pluginRemoteRequest((iError, oData) => {
				const r = oData?.Result;
				if (!iError && r) {
					this.configured(!!r.configured);
					this.email(r.email || '');
					this.linked(r.linked || 0);
				}
			}, 'QuokkaSsoStatus');
		}

		unlink()
		{
			if (!confirm(rl.i18n('QUOKKA_SSO/UNLINK_ASK'))) return;
			rl.pluginRemoteRequest((iError, oData) => {
				this.message(rl.i18n(iError ? 'QUOKKA_SSO/UNLINK_FAILED' : 'QUOKKA_SSO/UNLINKED'));
				!iError && this.linked(0);
			}, 'QuokkaSsoUnlink');
		}
	}

	rl.addSettingsViewModel(QuokkaSsoSettings, 'QuokkaSsoSettingsTab', 'QUOKKA_SSO/TAB', 'sign-in');

}})(window.rl);

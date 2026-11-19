(rl => { if (rl) {

	// Sign-in: the code field appears only when the server asks for it (two-step verification turned on)
	const messages = {
		QUOKKA_2FA_REQUIRED: 'QUOKKA_SECURITY/CODE_REQUIRED',
		QUOKKA_2FA_WRONG: 'QUOKKA_SECURITY/CODE_WRONG',
		QUOKKA_2FA_LOCKED: 'QUOKKA_SECURITY/CODE_LOCKED'
	};
	let login = null, field = null;

	addEventListener('rl-view-model', e => {
		const vm = e.detail;
		if ('Login' === vm?.viewModelTemplateID) {
			login = vm;
			const place = vm.viewModelDom?.querySelector('#plugin-Login-BottomControlGroup');
			if (place && !field) {
				field = Element.fromHTML('<div class="controls quokka-code" hidden>'
					+ '<input name="quokka_code" type="text" inputmode="numeric" autocomplete="one-time-code"'
					+ ' autocorrect="off" autocapitalize="none" maxlength="9" placeholder="' + rl.i18n('QUOKKA_SECURITY/CODE_PLACEHOLDER').replace(/"/g, '&quot;') + '">'
					+ '</div>');
				place.prepend(field);
			}
		}
		if ('MailFolderList' === vm?.viewModelTemplateID) {
			setTimeout(warnPwned, 1500);
		}
	});

	addEventListener('sm-user-login-response', e => {
		const key = e.detail?.error && e.detail.data?.messageAdditional;
		if (field && messages[key]) {
			field.hidden = false;
			const input = field.querySelector('input');
			'QUOKKA_2FA_REQUIRED' === key || (input.value = '');
			setTimeout(() => {
				login?.submitError?.(rl.i18n(messages[key]));
				login?.submitErrorAdditional?.('');
				input.focus();
			}, 0);
		}
	});

	// Stolen password (only when the person turned the check on): said once per session
	const warnPwned = () => {
		const count = rl.settings.get('QuokkaPwned');
		let told = false;
		try { told = !!sessionStorage.getItem('quokka-pwned-told'); } catch (e) {}
		if (!count || told || !rl.app?.ask) return;
		try { sessionStorage.setItem('quokka-pwned-told', '1'); } catch (e) {}
		rl.app.ask.showModal([
			rl.i18n('QUOKKA_SECURITY/STOLEN_ALERT', { COUNT: count.toLocaleString(document.documentElement.lang || undefined) }),
			() => { location.hash = '#/settings/security'; },
			null, true, 0, 'QUOKKA_SECURITY/GO_TO_SECURITY'
		]);
	};

}})(window.rl);

(rl => { if (rl) {

	// A tap on a notification of new email: ?quokka-account=<mailbox>#/mailbox/INBOX/m<uid> (sorter/push.py).
	// In another of the person's mailboxes: switch to it, then the same address without the parameter opens the email.
	const openAccount = new URLSearchParams(location.search).get('quokka-account');
	if (openAccount) {
		const clean = location.pathname + location.hash;
		const wait = setInterval(() => {
			if (!rl.settings?.get?.('Auth')) return;          // not signed in yet
			clearInterval(wait);
			rl.pluginRemoteRequest((iError, oData) => {
				if (!iError && oData?.Result?.switched) {
					location.replace(clean);
				} else {
					history.replaceState(null, '', clean);
				}
			}, 'QuokkaOpenAccount', { email: openAccount });
		}, 200);
		setTimeout(() => clearInterval(wait), 30000);
	}

	// This device gets the push notifications of a mailbox: remembered, so the page does not show a second one
	const pushFlag = (on, mailbox) => {
		try {
			const key = 'quokka.push.' + String(mailbox || '').toLowerCase();
			on ? localStorage.setItem(key, '1') : localStorage.removeItem(key);
		} catch (e) { /* storage off: at worst two notifications */ }
	};

	// At every start: does this device get this mailbox's push notifications? Then the page shows none of its own,
	// or the same email would notify twice (closed: the push one; then again when Quokka opens)
	(() => {
		if (!('serviceWorker' in navigator) || !('PushManager' in window) || window.Notification?.permission !== 'granted') return;
		const wait = setInterval(async () => {
			if (!rl.settings?.get?.('Auth')) return;
			clearInterval(wait);
			try {
				const reg = await navigator.serviceWorker.getRegistration('./');
				const sub = reg && await reg.pushManager.getSubscription();
				if (!sub) return pushFlag(false, rl.settings.get('Email'));
				rl.pluginRemoteRequest((iError, oData) => {
					const r = oData?.Result;
					!iError && r?.available && pushFlag(!!r.subscribed, r.mailbox);
				}, 'QuokkaPushStatus', { endpoint: sub.endpoint });
			} catch (e) { /* no service worker yet: nothing to check */ }
		}, 300);
		setTimeout(() => clearInterval(wait), 30000);
	})();

	// Settings → Family apps: each person's tokens for Pelican and Kooka (written once, never shown back)
	class QuokkaAppsSettings
	{
		constructor()
		{
			this.pelican = ko.observable(false);
			this.kooka = ko.observable(false);
			this.pelicanSaved = ko.observable(false);
			this.kookaSaved = ko.observable(false);
			this.pelicanToken = ko.observable('');
			this.kookaToken = ko.observable('');
			this.savedText = rl.i18n('QUOKKA_EXTRAS/TOKEN_SAVED');
			this.saving = ko.observable(false);
			this.message = ko.observable('');
			this.error = ko.observable(false);
		}

		load()
		{
			rl.pluginRemoteRequest((iError, oData) => {
				const r = oData?.Result;
				if (!iError && r) {
					this.pelican(!!r.pelican);
					this.kooka(!!r.kooka);
					this.pelicanSaved(!!r.pelicanToken);
					this.kookaSaved(!!r.kookaToken);
				}
			}, 'QuokkaExtrasGet');
		}

		onShow()
		{
			this.message('');
			this.load();
		}

		save(pelican, kooka)
		{
			this.saving(true);
			rl.pluginRemoteRequest((iError, oData) => {
				this.saving(false);
				const r = oData?.Result;
				this.error(!!(iError || r?.error));
				this.message(iError ? rl.i18n('QUOKKA_EXTRAS/NOT_SAVED') : r?.error ? rl.i18n('QUOKKA_EXTRAS/' + r.error, { PREFIX: r.prefix || '' }) : rl.i18n('QUOKKA_EXTRAS/SAVED'));
				if (!iError && !r?.error) {
					this.pelicanToken('');
					this.kookaToken('');
					this.load();
				}
			}, 'QuokkaExtrasSave', { pelicanToken: pelican, kookaToken: kooka });
		}

		saveTokens()
		{
			this.save(this.pelicanToken().trim(), this.kookaToken().trim());
		}

		forgetPelican() { this.save('-', ''); }
		forgetKooka() { this.save('', '-'); }
	}

	// Settings → Notifications: new emails of this mailbox on this device, also with Quokka closed (the sorter sends them)
	class QuokkaPushSettings
	{
		constructor()
		{
			this.state = ko.observable('loading');     // loading | unsupported | ios | off | not_watched | ready
			this.mailbox = ko.observable('');
			this.subscribed = ko.observable(false);
			this.every = ko.observable(5);
			this.busy = ko.observable(false);
			this.message = ko.observable('');
			this.error = ko.observable(false);
			this.denied = ko.observable(false);
			this.everyOptions = [0, 5, 10, 15, 30, 60].map(n => ({ value: n,
				label: n ? rl.i18n('QUOKKA_EXTRAS/PUSH_EVERY_N', { N: n }) : rl.i18n('QUOKKA_EXTRAS/PUSH_EVERY_NOW') }));
			this.every.subscribe(n => this.loaded && this.subscribed()
				&& this.call('QuokkaPushEvery', { every: n }, () => this.say('PUSH_SAVED')));
		}

		say(key, error) { this.error(!!error); this.message(key ? rl.i18n('QUOKKA_EXTRAS/' + key) : ''); }

		call(action, params, done)
		{
			rl.pluginRemoteRequest((iError, oData) => {
				const r = oData?.Result;
				if (iError || !r || r.error) {
					this.busy(false);
					this.say(r?.error === 'not_watched' ? 'PUSH_NOT_WATCHED' : 'PUSH_FAILED', true);
				} else {
					done(r);
				}
			}, action, params);
		}

		async onShow()
		{
			this.loaded = false;
			this.say('');
			const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
			if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
				// on iPhone the notifications work only from the app on the home screen
				this.state(ios && !navigator.standalone ? 'ios' : 'unsupported');
				return;
			}
			this.denied(Notification.permission === 'denied');
			let endpoint = '';
			try {
				this.reg = await navigator.serviceWorker.register('./quokka-sw.js', { scope: './' });
				await navigator.serviceWorker.ready;
				this.sub = await this.reg.pushManager.getSubscription();
				endpoint = this.sub?.endpoint || '';
			} catch (e) {
				console.log('Quokka notifications:', e);
				this.state('unsupported');
				return;
			}
			this.call('QuokkaPushStatus', { endpoint }, r => {
				this.mailbox(r.mailbox || '');
				this.key = r.key;
				this.state(!r.available ? 'off' : r.watched ? 'ready' : 'not_watched');
				this.subscribed(!!r.subscribed);
				r.available && pushFlag(!!r.subscribed, r.mailbox);
				this.every(r.every ?? 5);
				this.loaded = true;
			});
		}

		static urlKey(b64)
		{
			const s = atob((b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
			return Uint8Array.from(s, c => c.charCodeAt(0));
		}

		async enable()
		{
			this.busy(true);
			this.say('');
			try {
				if (await Notification.requestPermission() !== 'granted') {
					this.denied(true);
					this.busy(false);
					this.say('PUSH_DENIED', true);
					return;
				}
				this.sub = await this.reg.pushManager.getSubscription()
					|| await this.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: QuokkaPushSettings.urlKey(this.key) });
			} catch (e) {
				console.log('Quokka notifications:', e);
				this.busy(false);
				this.say('PUSH_FAILED', true);
				return;
			}
			this.call('QuokkaPushSubscribe', { subscription: this.sub.toJSON(), lang: document.documentElement.lang || 'it' }, r => {
				this.busy(false);
				this.subscribed(true);
				pushFlag(true, this.mailbox());
				this.every(r.every ?? this.every());
				this.say('PUSH_ON');
			});
		}

		disable()
		{
			this.busy(true);
			// the browser's subscription stays: it may serve the other mailboxes of this device
			this.call('QuokkaPushUnsubscribe', { endpoint: this.sub?.endpoint || '' }, () => {
				this.busy(false);
				this.subscribed(false);
				pushFlag(false, this.mailbox());
				this.say('PUSH_OFF');
			});
		}

		test()
		{
			this.busy(true);
			this.call('QuokkaPushTest', { endpoint: this.sub?.endpoint || '' }, () => { this.busy(false); this.say('PUSH_TEST_SENT'); });
		}
	}

	// Settings → Categories: which of the sorter's categories this person sees
	class QuokkaCategoriesSettings
	{
		constructor()
		{
			const cats = rl.quokkaCategories;
			this.message = ko.observable('');
			this.error = ko.observable(false);
			this.categories = (cats?.all?.() || []).map(([key, label]) => {
				const on = ko.observable(true);
				on.subscribe(() => this.save());
				return { key, label, on };
			});
			this.loading = false;
		}

		onShow()
		{
			const shown = rl.quokkaCategories?.visible ? rl.quokkaCategories.visible().map(([k]) => k) : null;
			this.loading = true;
			this.categories.forEach(c => c.on(!shown || shown.includes(c.key)));
			this.loading = false;
			this.message('');
		}

		save()
		{
			if (this.loading) return;
			const hidden = this.categories.filter(c => !c.on()).map(c => c.key);
			rl.quokkaCategories?.hidden(hidden);
			rl.pluginRemoteRequest((iError, oData) => {
				const failed = !!(iError || !oData?.Result);
				this.error(failed);
				this.message(rl.i18n(failed ? 'QUOKKA_EXTRAS/NOT_SAVED' : 'QUOKKA_EXTRAS/SAVED'));
			}, 'QuokkaCategoriesSave', { hidden });
		}
	}

	rl.addSettingsViewModel(QuokkaCategoriesSettings, 'QuokkaCategoriesSettingsTab', 'QUOKKA_EXTRAS/TAB_CATEGORIES', 'categories');
	rl.addSettingsViewModel(QuokkaPushSettings, 'QuokkaPushSettingsTab', 'QUOKKA_EXTRAS/TAB_PUSH', 'notifications');
	rl.addSettingsViewModel(QuokkaAppsSettings, 'QuokkaAppsSettingsTab', 'QUOKKA_EXTRAS/TAB_APPS', 'family-apps');

}})(window.rl);

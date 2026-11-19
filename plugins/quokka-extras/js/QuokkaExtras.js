(rl => { if (rl) {

	// Settings → Family apps: each person's tokens for paTrack and doTrack (written once, never shown back)
	class QuokkaAppsSettings
	{
		constructor()
		{
			this.patrack = ko.observable(false);
			this.dotrack = ko.observable(false);
			this.patrackSaved = ko.observable(false);
			this.dotrackSaved = ko.observable(false);
			this.patrackToken = ko.observable('');
			this.dotrackToken = ko.observable('');
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
					this.patrack(!!r.patrack);
					this.dotrack(!!r.dotrack);
					this.patrackSaved(!!r.patrackToken);
					this.dotrackSaved(!!r.dotrackToken);
				}
			}, 'QuokkaExtrasGet');
		}

		onShow()
		{
			this.message('');
			this.load();
		}

		save(patrack, dotrack)
		{
			this.saving(true);
			rl.pluginRemoteRequest((iError, oData) => {
				this.saving(false);
				const r = oData?.Result;
				this.error(!!(iError || r?.error));
				this.message(iError ? rl.i18n('QUOKKA_EXTRAS/NOT_SAVED') : r?.error ? rl.i18n('QUOKKA_EXTRAS/' + r.error, { PREFIX: r.prefix || '' }) : rl.i18n('QUOKKA_EXTRAS/SAVED'));
				if (!iError && !r?.error) {
					this.patrackToken('');
					this.dotrackToken('');
					this.load();
				}
			}, 'QuokkaExtrasSave', { patrackToken: patrack, dotrackToken: dotrack });
		}

		saveTokens()
		{
			this.save(this.patrackToken().trim(), this.dotrackToken().trim());
		}

		forgetPatrack() { this.save('-', ''); }
		forgetDotrack() { this.save('', '-'); }
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
	rl.addSettingsViewModel(QuokkaAppsSettings, 'QuokkaAppsSettingsTab', 'QUOKKA_EXTRAS/TAB_APPS', 'family-apps');

}})(window.rl);

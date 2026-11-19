(rl => { if (rl) {

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

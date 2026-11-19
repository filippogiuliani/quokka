import ko from 'ko';
import { koComputable } from 'External/ko';

import { SettingsCapa } from 'Common/Globals';
import { i18n, translateTrigger, relativeTime } from 'Common/Translator';

import { AbstractViewSettings } from 'Knoin/AbstractViews';

import { SettingsUserStore } from 'Stores/User/Settings';

import { GnuPGUserStore } from 'Stores/User/GnuPG';
import { OpenPGPUserStore } from 'Stores/User/OpenPGP';

import { showScreenPopup } from 'Knoin/Knoin';

import { OpenPgpImportPopupView } from 'View/Popup/OpenPgpImport';
import { OpenPgpGeneratePopupView } from 'View/Popup/OpenPgpGenerate';

import { SMimeUserStore } from 'Stores/User/SMime';
import { SMimeImportPopupView } from 'View/Popup/SMimeImport';

/** Quokka: "iPhone · Safari" from a User-Agent */
/** Quokka: dates in the interface's language */
const lang = () => document.documentElement.lang || undefined;

const deviceName = ua => {
	ua = ua || '';
	const os = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) ? 'iPad' : /Android/.test(ua) ? 'Android'
		: /Windows/.test(ua) ? 'Windows' : /Mac OS X|Macintosh/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : '';
	const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /OPR\//.test(ua) ? 'Opera'
		: /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : '';
	return [os, browser].filter(Boolean).join(' · ') || i18n('QUOKKA/UNKNOWN_DEVICE');
};

export class UserSettingsSecurity extends AbstractViewSettings {
	constructor() {
		super();

		this.autoLogout = SettingsUserStore.autoLogout;
		this.autoLogoutOptions = koComputable(() => {
			translateTrigger();
			return [
				{ id: 0, name: i18n('SETTINGS_SECURITY/NEVER') },
				{ id: 5, name: relativeTime(300) },
				{ id: 15, name: relativeTime(900) },
				{ id: 30, name: relativeTime(1800) },
				{ id: 60, name: relativeTime(3600) },
				{ id: 120, name: relativeTime(7200) },
				{ id: 300, name: relativeTime(18000) },
				{ id: 600, name: relativeTime(36000) }
			];
		});
		this.addSetting('AutoLogout');

		this.keyPassForget = SettingsUserStore.keyPassForget;
		this.addSetting('keyPassForget');

		this.gnupgPublicKeys = GnuPGUserStore.publicKeys;
		this.gnupgPrivateKeys = GnuPGUserStore.privateKeys;

		this.openpgpkeysPublic = OpenPGPUserStore.publicKeys;
		this.openpgpkeysPrivate = OpenPGPUserStore.privateKeys;

		this.smimeCertificates = SMimeUserStore;

		this.canOpenPGP = SettingsCapa('OpenPGP');
		this.canGnuPG = GnuPGUserStore.isSupported();
		this.canMailvelope = !!window.mailvelope;

		// Quokka: privacy (moved here from General: external images are how senders see that an email was opened)
		this.viewImages = SettingsUserStore.viewImages;
		this.viewImagesWhitelist = SettingsUserStore.viewImagesWhitelist;
		this.addSettings(['ViewImages', 'ViewImagesWhitelist']);

		// Quokka: two-step verification, last sign-ins, stolen passwords (plugins/quokka-security)
		this.sec = ko.observable(null);
		this.secBusy = ko.observable(false);
		this.secMessage = ko.observable('');
		this.secError = ko.observable(false);
		this.totpSetup = ko.observable(null);
		this.totpCode = ko.observable('');
		this.codeFor = ko.observable('');
		this.backupLeftText = koComputable(() => i18n('QUOKKA/BACKUP_LEFT', { COUNT: this.sec()?.backupLeft || 0 }));
		this.codeForText = koComputable(() => i18n('disable' === this.codeFor() ? 'QUOKKA/CODE_TO_TURN_OFF' : 'QUOKKA/CODE_TO_RENEW'));
		this.totpCodeReady = koComputable(() => !this.secBusy() && 6 <= this.totpCode().replace(/\D/g, '').length);
		this.backupShown = ko.observableArray([]);
		this.pwnedCheck = ko.observable(false);
		this.pwnedLoading = false;
		this.pwnedCheck.subscribe(on => this.pwnedLoading || this.secRequest('QuokkaSecurityPwned', { enabled: on ? 1 : 0 }));
		this.logins = koComputable(() => { let hereSeen = false; return (this.sec()?.logins || []).map(l => ({
			when: new Date(l.time * 1000).toLocaleString(lang(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }),
			device: deviceName(l.ua),
			where: l.local ? i18n('QUOKKA/HOME_NETWORK') : (l.ip || i18n('QUOKKA/UNKNOWN_ADDRESS')),
			how: i18n(l.ok ? ({ pocketid: 'QUOKKA/HOW_POCKETID', 'password+code': 'QUOKKA/HOW_PASSWORD_CODE', password: 'QUOKKA/HOW_PASSWORD' }[l.how] || l.how) : 'QUOKKA/WRONG_CODE'),
			ok: l.ok,
			here: l.here && l.ok && !hereSeen && (hereSeen = true)
		})); });
		this.totpSince = koComputable(() => {
			const s = this.sec();
			return s?.totpSince ? i18n('QUOKKA/TWO_STEP_ON_SINCE', { DATE: new Date(s.totpSince * 1000).toLocaleDateString(lang(), { day: 'numeric', month: 'long', year: 'numeric' }) }) : '';
		});
		this.pwnedText = koComputable(() => {
			const p = this.sec()?.pwned;
			if (!this.sec()?.pwnedCheck || !p) return '';
			const day = new Date(p.time * 1000).toLocaleDateString(lang(), { day: 'numeric', month: 'long' });
			return p.count
				? i18n('QUOKKA/STOLEN_FOUND', { COUNT: p.count.toLocaleString(lang()), DATE: day })
				: i18n('QUOKKA/STOLEN_CLEAN', { DATE: day });
		});
	}

	secRequest(action, params, then) {
		this.secBusy(true);
		this.secMessage('');
		rl.pluginRemoteRequest((iError, oData) => {
			this.secBusy(false);
			const r = oData?.Result;
			if (iError || !r || r.error) {
				this.secError(true);
				this.secMessage(i18n(r?.error ? 'QUOKKA/' + r.error : 'QUOKKA/SEC_FAILED'));
				return;
			}
			this.secError(false);
			if ('logins' in r) {
				this.sec(r);
				this.pwnedLoading = true;
				this.pwnedCheck(!!r.pwnedCheck);
				this.pwnedLoading = false;
			}
			then?.(r);
		}, action, params || {}, 30000);
	}

	onShow() {
		this.totpSetup(null);
		this.codeFor('');
		this.backupShown([]);
		this.secMessage('');
		this.secRequest('QuokkaSecurityGet');
	}

	totpStart() {
		this.totpCode('');
		this.secRequest('QuokkaSecurityTotpStart', {}, r => this.totpSetup(r));
	}

	totpConfirm() {
		this.secRequest('QuokkaSecurityTotpEnable', { code: this.totpCode() }, r => {
			this.totpSetup(null);
			this.totpCode('');
			this.backupShown(r.backup || []);
		});
	}

	totpCancel() {
		this.totpSetup(null);
		this.codeFor('');
		this.totpCode('');
		this.secMessage('');
	}

	askCode(what) {
		this.codeFor(what);
		this.totpCode('');
		this.secMessage('');
	}

	codeConfirm() {
		const what = this.codeFor();
		this.secRequest('disable' === what ? 'QuokkaSecurityTotpDisable' : 'QuokkaSecurityTotpBackup', { code: this.totpCode() }, r => {
			this.codeFor('');
			this.totpCode('');
			this.backupShown(r.backup || []);
			this.secMessage('disable' === what ? i18n('QUOKKA/TWO_STEP_TURNED_OFF') : '');
		});
	}

	backupDone() {
		this.backupShown([]);
	}

	addOpenPgpKey() {
		showScreenPopup(OpenPgpImportPopupView);
	}

	generateOpenPgpKey() {
		showScreenPopup(OpenPgpGeneratePopupView);
	}

	importToOpenPGP() {
		OpenPGPUserStore.loadBackupKeys();
	}

	importToSMime() {
		showScreenPopup(SMimeImportPopupView);
	}

	onBuild() {
		/**
		 * Create an iframe to display the Mailvelope keyring settings.
		 * The iframe will be injected into the container identified by selector.
		 */
		window.mailvelope && mailvelope.createSettingsContainer('#mailvelope-settings'/*[, keyring], options*/);
		/**
		 * https://github.com/the-djmaze/snappymail/issues/973
		Remote.request('GetStoredPGPKeys', (iError, data) => {
			console.dir([iError, data]);
		});
		*/
	}
}

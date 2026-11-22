import ko from 'ko';

//import { koComputable } from 'External/ko';
import { SettingsCapa, SettingsGet } from 'Common/Globals';
import { loadAccountsAndIdentities, editIdentity } from 'Common/UtilsUser';

import { AccountUserStore } from 'Stores/User/Account';
import { IdentityUserStore } from 'Stores/User/Identity';
import { SettingsUserStore } from 'Stores/User/Settings';
import Remote from 'Remote/User/Fetch';

import { showScreenPopup } from 'Knoin/Knoin';

import { AccountPopupView } from 'View/Popup/Account';
import { AskPopupView } from 'View/Popup/Ask';
import { i18n, getNotification, translateTrigger } from 'Common/Translator';
import { koComputable } from 'External/ko';
import * as Boxes from 'Settings/User/Boxes';

export class UserSettingsAccounts /*extends AbstractViewSettings*/ {
	constructor() {
		this.allowAdditionalAccount = SettingsCapa('AdditionalAccounts');
		this.allowIdentities = SettingsCapa('Identities');

		this.accounts = AccountUserStore;
		this.loading = AccountUserStore.loading;
		this.identities = IdentityUserStore;
		this.mainEmail = SettingsGet('mainEmail');

		this.accountForDeletion = ko.observable(null).askDeleteHelper();
		this.identityForDeletion = ko.observable(null).askDeleteHelper();

		// Quokka: each mailbox with its identities and folders (Settings/User/Boxes.js)
		this.boxes = koComputable(() => AccountUserStore().map(account => Boxes.boxOf(account)));
		this.tr = key => (translateTrigger(), i18n(key));
		this.isOpenMailbox = box => box.email.toLowerCase() === (rl.settings.get('Email') || '').toLowerCase();
		['toggleBox', 'createFolder', 'startRename', 'renameFolder', 'deleteFolder', 'toggleSubscribed',
			'editIdentity', 'deleteIdentity'].forEach(name => this[name] = Boxes[name]);
		this.renameKey = (box, folder, event) => {
			'Enter' === event.key && Boxes.renameFolder(box, folder);
			'Escape' === event.key && folder.renaming(false);
			return true;
		};

		this.showUnread = SettingsUserStore.showUnreadCount;
		SettingsUserStore.showUnreadCount.subscribe(value => Remote.saveSetting('ShowUnreadCount', value));

		// called by the sortable list without its object
		this.accountsAndIdentitiesAfterMove = this.accountsAndIdentitiesAfterMove.bind(this);

//		this.additionalAccounts = koComputable(() => AccountUserStore.filter(account => account.isAdditional()));
	}

	addNewAccount() {
		showScreenPopup(AccountPopupView);
	}

	editAccount(account) {
		if (account?.isAdditional()) {
			showScreenPopup(AccountPopupView, [account]);
		}
	}

	addNewIdentity() {
		editIdentity();
	}

	editIdentity(identity) {
		editIdentity(identity);
	}

	/**
	 * @param {AccountModel} accountToRemove
	 * @returns {void}
	 */
	deleteAccount(accountToRemove) {
		if (accountToRemove?.askDelete()) {
			this.accountForDeletion(null);
			this.accounts.remove(account => accountToRemove === account);

			Remote.request('AccountDelete', (iError, data) => {
				if (!iError && data.Reload) {
					rl.route.root();
					setTimeout(() => location.reload(), 1);
				} else {
					loadAccountsAndIdentities();
				}
			}, {
				emailToDelete: accountToRemove.email
			});
		}
	}

	/**
	 * @param {IdentityModel} identityToRemove
	 * @returns {void}
	 */
	deleteIdentity(identityToRemove) {
		if (identityToRemove?.askDelete()) {
			this.identityForDeletion(null);
			IdentityUserStore.remove(oIdentity => identityToRemove === oIdentity);
			Remote.request('IdentityDelete', () => rl.app.accountsAndIdentities(), {
				idToDelete: identityToRemove.id()
			});
		}
	}

	/** Quokka: the first account is the main one (Pocket ID opens it, the others live inside it) */
	makeMain(account) {
		const order = [account.email, ...AccountUserStore.map(item => item.email).filter(email => email !== account.email)];
		AskPopupView.showModal([
			i18n('QUOKKA/MAKE_MAIN_ASK', { EMAIL: account.label() }),
			() => Remote.request('AccountMakeMain', (iError, data) => {
				if (!iError && data?.Result) {
					rl.route.root();
					setTimeout(() => location.reload(), 1);
				} else {
					alert(getNotification(iError) || i18n('QUOKKA/MAKE_MAIN_FAILED'));
					loadAccountsAndIdentities();
				}
			}, { email: account.email, order: order }),
			() => loadAccountsAndIdentities()
		]);
	}

	accountsAndIdentitiesAfterMove() {
		const first = AccountUserStore()[0];
		if (first?.isAdditional()) {
			// dragged above the main account: that one becomes the main account
			return this.makeMain(first);
		}
		Remote.request('AccountsAndIdentitiesSortOrder', null, {
			Accounts: AccountUserStore.filter(item => item.isAdditional()).map(item => item.email),
			Identities: IdentityUserStore.map(item => (item ? item.id() : ""))
		});
	}

	onBuild(oDom) {
		oDom.addEventListener('click', event => {
			let el = event.target.closestWithin('.accounts-list .e-action', oDom);
			el && ko.dataFor(el) && this.editAccount(ko.dataFor(el));

			el = event.target.closestWithin('.identities-list .e-action', oDom);
			el && ko.dataFor(el) && this.editIdentity(ko.dataFor(el));
		});
	}
}

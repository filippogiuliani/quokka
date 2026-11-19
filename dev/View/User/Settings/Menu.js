import { settings } from 'Common/Links';
import { mailbox } from 'Common/Links';
import { getFolderInboxName } from 'Common/Cache';
import { AbstractViewLeft } from 'Knoin/AbstractViews';
import { AccountUserStore } from 'Stores/User/Account';
import ko from 'ko';

export class SettingsMenuUserView extends AbstractViewLeft {
	/**
	 * @param {Object} screen
	 */
	constructor(screen) {
		super();

		this.menu = screen.menu;

		// Quokka: who is signed in, at the bottom of the menu
		this.accountEmail = AccountUserStore.email;
		this.accountInitial = ko.computed(() => (AccountUserStore.email() || '?').charAt(0).toUpperCase());
	}

	link(route) {
		return settings(route);
	}

	backToInbox() {
		hasher.setHash(mailbox(getFolderInboxName()));
	}
}

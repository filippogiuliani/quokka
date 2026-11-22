import ko from 'ko';

import { ScopeFolderList, ScopeMessageList } from 'Common/Enums';
import { addShortcut, leftPanelDisabled, stopEvent, SettingsGet } from 'Common/Globals';
import { mailBox, settings } from 'Common/Links';
//import { setFolderETag } from 'Common/Cache';
import { addComputablesTo } from 'External/ko';

import { AppUserStore } from 'Stores/User/App';
import { AccountUserStore } from 'Stores/User/Account';
import { SettingsUserStore } from 'Stores/User/Settings';
import { FolderUserStore } from 'Stores/User/Folder';
import { MessageUserStore } from 'Stores/User/Message';
import { MessagelistUserStore } from 'Stores/User/Messagelist';

import { showScreenPopup } from 'Knoin/Knoin';
import { AbstractViewLeft } from 'Knoin/AbstractViews';

import { showMessageComposer, moveAction } from 'Common/UtilsUser';
import { FolderCreatePopupView } from 'View/Popup/FolderCreate';
import { ContactsPopupView } from 'View/Popup/Contacts';
import { ComposePopupView } from 'View/Popup/Compose';

import { setExpandedFolder, foldersFilter } from 'Model/FolderCollection';
import { CATEGORY_KEYS, CATEGORIES, categorySearch } from 'Common/Categories';
import { i18n, translateTrigger } from 'Common/Translator';
import { getFolderHashFromCacheList, getFolderInboxName } from 'Common/Cache';
import { ThemeStore } from '../../../Stores/Theme';
import { mailboxes, hasMailboxes, unifiedOn, unifiedTotal, selectMailbox, mailboxesOpen, chosenMailbox } from 'Common/Mailboxes';

export class MailFolderList extends AbstractViewLeft {
	constructor() {
		super();

//		this.oContentScrollable = null;

		this.composeInEdit = ComposePopupView.inEdit;

		this.systemFolders = FolderUserStore.systemFolders;

		this.moveAction = moveAction;

		this.allowContacts = AppUserStore.allowContacts();

		this.foldersFilter = foldersFilter;

		this.filterUnseen = ko.observable(false);

		// Quokka: who is signed in, at the bottom of the column
		this.accountEmail = AccountUserStore.email;
		this.accountInitial = ko.computed(() => (AccountUserStore.email() || '?').charAt(0).toUpperCase());
		this.settingsHref = settings();

		// Quokka: the person's mailboxes, "All mailboxes" first (Common/Mailboxes.js)
		this.mailboxes = mailboxes;
		this.hasMailboxes = hasMailboxes;
		this.allMailboxesSelected = unifiedOn;
		this.allMailboxesUnread = unifiedTotal;
		this.selectAllMailboxes = () => selectMailbox(null);
		this.selectMailbox = box => selectMailbox(box);
		// one line (the mailbox chosen) that opens the list: the categories stay in view on a phone
		this.mailboxesOpen = mailboxesOpen;
		this.chosenMailbox = chosenMailbox;
		this.toggleMailboxes = () => mailboxesOpen(!mailboxesOpen());
		// Quokka: Kooka's calendar (when Kooka is there), in a tab of its own
		this.calendarUrl = SettingsGet('QuokkaCalendarUrl') || '';
		this.calendarLabel = ko.computed(() => (translateTrigger(), i18n('QUOKKA/CALENDAR')));
		this.mailboxesTitle = ko.computed(() => (translateTrigger(), i18n('QUOKKA/MAILBOXES')));
		this.allMailboxesLabel = ko.computed(() => (translateTrigger(), i18n('QUOKKA/ALL_MAILBOXES')));

		// Quokka: the categories, each a search of the inbox
		const categories = CATEGORY_KEYS.map(key => ({
			key,
			label: ko.computed(() => (translateTrigger(), i18n('MESSAGE_TAGS/' + key))),
			href: ko.computed(() => (FolderUserStore.folderList(),
				mailBox(getFolderHashFromCacheList(getFolderInboxName()), 1, categorySearch(key)))),
			selected: ko.computed(() => MessagelistUserStore.listSearch() === categorySearch(key)
				&& FolderUserStore.currentFolder()?.isInbox())
		}));
		this.categories = ko.computed(() => categories.filter(c => CATEGORIES().some(([key]) => key === c.key)));

		addComputablesTo(this, {
			foldersFilterVisible: () => 20 < FolderUserStore.folderList().CountRec,

			folderListVisible: () => {
				let result = FolderUserStore.folderList().visible();
				return 1 === result.length && result[0].isInbox() ? result[0].visibleSubfolders() : result;
			}
		});
	}

	onBuild(dom) {
		const qs = s => dom.querySelector(s),
			eqs = (ev, s) => ev.target.closestWithin(s, dom);

		this.oContentScrollable = qs('.b-content');

		dom.addEventListener('click', event => {
			// Quokka: on the phone a category closes the column and shows its emails (the link does the rest)
			if (ThemeStore.isMobile() && eqs(event, '.b-categories a, .b-mailboxes a')) {
				leftPanelDisabled(true);
				return;
			}
			let el = eqs(event, '.e-collapsed-sign');
			if (el) {
				const folder = ko.dataFor(el);
				if (folder) {
					const collapsed = folder.collapsed();
					setExpandedFolder(folder.fullName, collapsed);

					folder.collapsed(!collapsed);
					stopEvent(event);
					return;
				}
			}

			el = eqs(event, 'a');
			if (el?.matches('.selectable')) {
				event.preventDefault();
				const folder = ko.dataFor(el);
				if (folder) {
					if (moveAction()) {
						const copy = event.ctrlKey || 2 === moveAction(),
							messages = MessagelistUserStore.listCheckedOrSelectedUidsWithSubMails();
						moveAction(0);
						messages.size && MessagelistUserStore.moveMessages(
							messages.folder,
							messages,
							folder.fullName,
							copy
						);
					} else {
						if (!SettingsUserStore.usePreviewPane()) {
							MessageUserStore.message(null);
						}
/*
						if (folder.fullName === FolderUserStore.currentFolderFullName()) {
							setFolderETag(folder.fullName, '');
						}
*/
						let search = '';
						if (event.target.matches('.flag-icon') && !folder.isFlagged()) {
							search = 'flagged';
						} else if (folder.unreadCount() && event.clientX > el.getBoundingClientRect().right - 25) {
							search = 'unseen';
						}
						hasher.setHash(mailBox(folder.fullNameHash, 1, search));

						// in mobile mode hide the panel when a folder is clicked
						ThemeStore.isMobile() && leftPanelDisabled(true);
					}

					AppUserStore.focusedState(ScopeMessageList);
				}
			}
		});

		addShortcut('arrowup,arrowdown', '', ScopeFolderList, event => {
			let items = [], index = 0;
			dom.querySelectorAll('li a').forEach(node => {
				if (node.offsetHeight || node.getClientRects().length) {
					items.push(node);
					if (node.matches('.focused')) {
						node.classList.remove('focused');
						index = items.length - 1;
					}
				}
			});
			if (items.length) {
				if ('ArrowUp' === event.key) {
					index && --index;
				} else if (index < items.length - 1) {
					++index;
				}
				items[index].classList.add('focused');
				this.scrollToFocused();
			}

			return false;
		});

		addShortcut('enter,open', '', ScopeFolderList, () => {
			const item = qs('li a.focused');
			if (item) {
				AppUserStore.focusedState(ScopeMessageList);
				item.click();
			}

			return false;
		});

		addShortcut('space', '', ScopeFolderList, () => {
			const item = qs('li a.focused'),
				folder = item && ko.dataFor(item);
			if (folder) {
				const collapsed = folder.collapsed();
				setExpandedFolder(folder.fullName, collapsed);
				folder.collapsed(!collapsed);
			}

			return false;
		});

//		addShortcut('tab', 'shift', ScopeFolderList, () => {
		addShortcut('escape,tab,arrowright', '', ScopeFolderList, () => {
			AppUserStore.focusedState(ScopeMessageList);
			moveAction(0);
			return false;
		});
	}

	scrollToFocused() {
		const scrollable = this.oContentScrollable;
		if (scrollable) {
			let block, focused = scrollable.querySelector('li a.focused');
			if (focused) {
				const fRect = focused.getBoundingClientRect(),
					sRect = scrollable.getBoundingClientRect();
				if (fRect.top < sRect.top) {
					block = 'start';
				} else if (fRect.bottom > sRect.bottom) {
					block = 'end';
				}
				block && focused.scrollIntoView(block === 'start');
			}
		}
	}

	composeClick() {
		showMessageComposer();
	}

	clearFolderSearch() {
		foldersFilter('');
	}

	createFolder() {
		showScreenPopup(FolderCreatePopupView);
	}

	configureFolders() {
		hasher.setHash(settings('folders'));
	}

	contactsClick() {
		if (this.allowContacts) {
			showScreenPopup(ContactsPopupView);
		}
	}
}

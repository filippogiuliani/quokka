// Quokka: a person's mailboxes ("Caselle" in the column) and "All mailboxes": the INBOX of every one of them in one
// list, from the sorter's index (sorter/inbox.py, through plugins/quokka-extras). One mailbox chosen: Quokka as ever.
import ko from 'ko';

import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore } from 'Stores/User/Folder';
import { MessagelistUserStore } from 'Stores/User/Messagelist';
import { CATEGORIES } from 'Common/Categories';
import { FolderType } from 'Common/EnumsUser';
import { mailBox } from 'Common/Links';
import { getFolderHashFromCacheList, getFolderFromCacheList, getFolderInboxName } from 'Common/Cache';
import { i18n, getNotification, translateTrigger } from 'Common/Translator';
import { UNUSED_OPTION_VALUE } from 'Common/Consts';

import Remote from 'Remote/User/Fetch';
import { MessageUserStore } from 'Stores/User/Message';
import { SettingsUserStore } from 'Stores/User/Settings';
import { ContactUserStore } from 'Stores/User/Contact';
import { loadFolders, resetSystemFolders } from 'Model/FolderCollection';
import { loadAccountsAndIdentities } from 'Common/UtilsUser';

const
	// one per mailbox, in the order of Settings → Accounts: told apart by lightness too, not only by hue
	COLORS = ['#1d4ed8', '#7c3aed', '#0f766e', '#c2410c', '#be185d', '#4d7c0f', '#0369a1', '#a16207', '#6b7280'],
	STORE = 'quokka.unified',
	REFRESH = 60000,
	deferred = { deferEvaluation: true },
	lower = s => String(s || '').toLowerCase(),
	b64url = s => btoa(unescape(encodeURIComponent(s))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
	remember = on => { try { localStorage.setItem(STORE, on ? '1' : '0'); } catch (e) { /* storage off: not remembered */ } },
	remembered = () => { try { return localStorage.getItem(STORE); } catch (e) { return null; } };

/** "All mailboxes" chosen (remembered on this device; on unless one mailbox was chosen) */
export const unifiedOn = ko.observable('0' !== remembered());
unifiedOn.subscribe(remember);

export const unifiedMessages = ko.observableArray([]);
export const unifiedUnread = ko.observable({});         // {mailbox (lower case): unread} of the INBOXes
export const unifiedHere = ko.observable(0);            // unread in the folder shown (Trash of all the mailboxes…)
export const unifiedSearching = ko.observable('');      // '' | 'busy' | 'done' | 'failed': the search everywhere
export const unifiedFound = ko.observable(0);
export const unifiedFailed = ko.observable([]);         // mailboxes that did not answer the search
export const unifiedLoading = ko.observable(false);
export const unifiedAvailable = ko.observable(true);    // false: the sorter does not answer
export const unifiedMore = ko.observable(false);
export const unifiedPending = ko.observable([]);        // mailboxes the sorter has not read yet
export const boxFolders = ko.observable({});            // {mailbox (lower case): [{name, delim, role, unseen, selectable}]}

// Outlook's way: every mailbox in the column with its folders, each one closed or open (remembered on this device)
const COLLAPSED = 'quokka.collapsed', collapsedOf = {};
const collapsedSaved = (() => { try { return JSON.parse(localStorage.getItem(COLLAPSED) || '[]'); } catch (e) { return []; } })();
const collapsed = email => collapsedOf[lower(email)] || (collapsedOf[lower(email)] = (() => {
	const o = ko.observable(collapsedSaved.includes(lower(email)));
	o.subscribe(() => {
		try {
			localStorage.setItem(COLLAPSED, JSON.stringify(Object.keys(collapsedOf).filter(k => collapsedOf[k]())));
		} catch (e) { /* storage off: not remembered */ }
	});
	return o;
})());

const ROLE_ICONS = { inbox: '📥', sent: '📧', drafts: '🗎', junk: '⚠', trash: '🗑', archive: '🗄' },
	ROLE_LABELS = { inbox: 'INBOX_NAME', sent: 'SENT_NAME', drafts: 'DRAFTS_NAME', junk: 'SPAM_NAME', trash: 'TRASH_NAME', archive: 'ARCHIVE_NAME' },
	ROLE_STORES = { sent: 'sentFolder', drafts: 'draftsFolder', junk: 'spamFolder', trash: 'trashFolder', archive: 'archiveFolder' },
	roleLabel = role => i18n('FOLDER_LIST/' + ROLE_LABELS[role]),
	// the folder of this role in the mailbox shown (its full name), '' when it has none
	roleFolder = role => {
		const name = 'inbox' === role ? getFolderInboxName() : FolderUserStore[ROLE_STORES[role]]?.();
		return name && UNUSED_OPTION_VALUE !== name && getFolderFromCacheList(name) ? name : '';
	};

/** The folders of a mailbox that is not the one shown, as the sorter saw them: a tree, its system folders first */
const remoteFolders = email => ko.computed(() => {
	translateTrigger();
	const list = boxFolders()[lower(email)] || [{ name: 'INBOX', delim: '', role: 'inbox', unseen: null, selectable: true }];
	return list.map(f => {
		let parts = f.delim ? f.name.split(f.delim) : [f.name];
		if (1 < parts.length && 'INBOX' === parts[0].toUpperCase()) parts = parts.slice(1);   // "INBOX.Clienti": a folder
		return {
			...f,
			label: f.role ? roleLabel(f.role) : parts[parts.length - 1],
			icon: ROLE_ICONS[f.role] || null,
			unread: f.unseen || null,
			indent: (f.role ? 0 : parts.length - 1) * 14 + 6 + 'px'
		};
	});
}, null, deferred);

/** The person's mailboxes: {email, name, letter, color, unread, current, selected} */
export const mailboxes = ko.computed(() => {
	const list = AccountUserStore(),
		firsts = list.map(a => (a.label() || a.email).trim().charAt(0).toUpperCase());
	return list.map((a, i) => {
		const name = a.label() || a.email,
			// two mailboxes with the same first letter: two letters each
			letter = 1 < firsts.filter(f => f === firsts[i]).length
				? firsts[i] + (name.trim().charAt(1) || '').toLowerCase() : firsts[i];
		return {
			email: a.email, name, letter,
			color: COLORS[i % COLORS.length],
			unread: ko.computed(() => unifiedUnread()[lower(a.email)] || 0),
			current: ko.computed(() => lower(AccountUserStore.email()) === lower(a.email)),
			selected: ko.computed(() => !unifiedOn() && lower(AccountUserStore.email()) === lower(a.email)),
			collapsed: collapsed(a.email),
			folders: remoteFolders(a.email)
		};
	});
}, null, deferred);

export const hasMailboxes = ko.computed(() => 1 < mailboxes().length, null, deferred);

export const unifiedTotal = ko.computed(() => Object.values(unifiedUnread()).reduce((s, n) => s + n, 0), null, deferred);


/** The kind of folder shown (inbox, sent, drafts, trash, junk, archive), '' for the others: the sorter knows those */
const ROLES = { [FolderType.Inbox]: 'inbox', [FolderType.Sent]: 'sent', [FolderType.Drafts]: 'drafts',
	[FolderType.Junk]: 'junk', [FolderType.Trash]: 'trash', [FolderType.Archive]: 'archive' };
export const currentRole = ko.computed(() => {
	const folder = FolderUserStore.currentFolder();
	return folder?.isInbox?.() ? 'inbox' : ROLES[folder?.type?.()] || '';
}, null, deferred);

/** The list shows all the mailboxes: chosen, more than one, in the inbox or a system folder (one Trash for all…) */
export const unifiedView = ko.computed(() => unifiedOn() && hasMailboxes() && !!currentRole()
	&& !MessagelistUserStore.threadUid(), null, deferred);

/** The name of the mailbox shown, for the heading ("All mailboxes" or one of them), '' with only one */
export const mailboxTitle = ko.computed(() => {
	if (!hasMailboxes()) return '';
	if (unifiedView()) return i18n('QUOKKA/ALL_MAILBOXES');
	return mailboxes().find(b => b.current())?.name || '';
}, null, deferred);

const boxOf = email => mailboxes().find(b => lower(b.email) === lower(email));

/** What the search box and the filters ask for, as the index can answer it */
const filterOf = search => {
	const out = { category: '', unseen: false, attachment: false, text: '' }, words = [];
	(String(search || '').match(/(?:[^\s"]+|"[^"]*")+/g) || []).forEach(part => {
		const m = part.match(/^([a-z]+)=(.*)$/i),
			value = (m ? m[2] : part).replace(/^"|"$/g, '');
		if (!m) words.push(value);
		else if ('keyword' === m[1]) out.category = value.toLowerCase();
		else if ('unseen' === m[1]) out.unseen = true;
		else if ('attachment' === m[1]) out.attachment = true;
		else if (['from', 'to', 'subject', 'text', 'body'].includes(m[1])) words.push(value);
	});
	out.text = words.join(' ');
	return out;
};

const item = m => {
	const box = boxOf(m.account) || { name: m.account, letter: '?', color: COLORS[COLORS.length - 1] },
		unseen = ko.observable(!m.seen),
		flagged = ko.observable(!!m.flagged);
	return {
		...m, from: undefined, box, unseen, flagged,
		boxStyle: 'background:' + box.color,
		lineCss: ko.computed(() => (unseen() ? 'unseen' : '') + (flagged() ? ' msgflag-\\flagged' : '')),
		categoryLabel: ko.computed(() => CATEGORIES().find(([key]) => key === m.category)?.[1] || ''),
		readTitle: ko.computed(() => i18n(unseen() ? 'QUOKKA/UNIFIED_READ' : 'QUOKKA/UNIFIED_UNREAD')),
		// the sender's picture as in the list of one mailbox (avatars plugin); none: the initial
		avatar: m.address ? '?Avatar/' + (m.signed ? 1 : 0) + '-/' + b64url(lower(m.address)) : '',
		sender: m.from || m.address,           // not "from": the avatars plugin reads that as a list of addresses
		initial: (m.from || m.address || '?').trim().charAt(0).toUpperCase(),
		role: undefined === m.role ? 'inbox' : m.role, folder: m.folder || 'INBOX',
		// a result of the search everywhere says its folder too (unless the inbox); a folder's own list need not
		folderLabel: m.everywhere && m.folder && 'INBOX' !== m.folder.toUpperCase() ? m.folder : '',
		// from the Trash nothing goes to the Trash or the Archive; from the Archive nothing to the Archive
		canArchive: !['trash', 'archive'].includes(m.role), canTrash: 'trash' !== m.role,
		threadsLen: () => 0
	};
};

let seq = 0, timer = 0, shownRole = '';

/** (Re)load the list; more: the next page after the ones shown */
export const loadUnified = more => {
	if (!hasMailboxes()) return;
	clearTimeout(timer);
	timer = setTimeout(() => loadUnified(), REFRESH);
	const role = currentRole() || 'inbox';
	if (role !== shownRole && !more) {           // another folder: its rows only, never the last folder's meanwhile
		shownRole = role;
		unifiedMessages([]);
		unifiedHere(0);
	}
	const n = ++seq,
		params = { ...filterOf(MessagelistUserStore.listSearch()), role: currentRole() || 'inbox', offset: more ? unifiedMessages().length : 0,
			folders: more ? 0 : 1 };
	unifiedLoading(true);
	rl.pluginRemoteRequest((iError, oData) => {
		if (n !== seq) return;                       // an answer to an older question
		unifiedLoading(false);
		const r = oData?.Result;
		if (iError || !r?.available) {
			unifiedAvailable(false);
			return;
		}
		unifiedAvailable(true);
		unifiedUnread(Object.fromEntries(Object.entries(r.unread || {}).map(([k, v]) => [lower(k), v])));
		unifiedHere(r.unread_here || 0);
		unifiedPending(r.pending || []);
		r.folders && !more && boxFolders(Object.fromEntries(Object.entries(r.folders).map(([k, v]) => [lower(k), v])));
		unifiedMore(!!r.more);
		const items = (r.messages || []).map(item);
		more ? unifiedMessages.push(...items) : unifiedMessages(items);
		more || searchEverywhere(params.text, n);
	}, 'QuokkaUnifiedList', params);
};

/**
 * With words in the search: after the quick answer of the index (60 days, this folder), the mail servers themselves,
 * every folder of every mailbox, at any date, as Outlook searches. Its results take the place of the quick ones.
 */
const searchEverywhere = (text, n) => {
	if (!text || !text.trim()) {
		unifiedSearching('');
		return;
	}
	clearTimeout(timer);                            // not every minute: a search is asked again only when it changes
	unifiedSearching('busy');
	rl.pluginRemoteRequest((iError, oData) => {
		if (n !== seq) return;
		const r = oData?.Result;
		if (iError || !r || r.error) {
			unifiedSearching('failed');
			return;
		}
		unifiedMessages((r.messages || []).map(m => item({ ...m, everywhere: true })));
		unifiedMore(false);
		unifiedFound((r.messages || []).length);
		unifiedFailed(r.failed || []);
		unifiedSearching('done');
	}, 'QuokkaUnifiedSearch', { text }, 95000);
};

const countDown = (msg, by) => {
	unifiedHere(Math.max(0, unifiedHere() - by));
	if ('inbox' !== msg.role) return;              // the column counts the INBOXes
	const key = lower(msg.account), all = { ...unifiedUnread() };
	all[key] = Math.max(0, (all[key] || 0) - by);
	unifiedUnread(all);
};

/** seen | unseen | flag | unflag | archive | trash | opened, on one email of the list */
export const unifiedAct = (msg, action, done) => {
	if ('seen' === action || 'opened' === action) {
		msg.unseen() && countDown(msg, 1);
		msg.unseen(false);
	} else if ('unseen' === action) {
		msg.unseen() || countDown(msg, -1);
		msg.unseen(true);
	} else if ('flag' === action || 'unflag' === action) {
		msg.flagged('flag' === action);
	} else {
		msg.unseen() && countDown(msg, 1);
		unifiedMessages.remove(msg);
	}
	rl.pluginRemoteRequest((iError, oData) => {
		const error = iError || oData?.Result?.error;
		if (error && 'opened' !== action) {
			loadUnified();                              // as it really is
			alert(i18n('no_folder' === error ? 'QUOKKA/UNIFIED_NO_FOLDER' : 'QUOKKA/UNIFIED_FAILED'));
		}
		done?.();
	}, 'QuokkaUnifiedAct', { account: msg.account, role: msg.role, in: msg.role ? '' : msg.folder, uids: [msg.uid], action });
};

/** To another of the person's mailboxes, then that address (the page starts again there) */
/**
 * To another of the person's mailboxes without reloading the page: the server switches, then here its settings,
 * folders, identities and contacts take the place of the other mailbox's, and `then` runs (e.g. open an email).
 * Anything kept from the other mailbox (open email, cached bodies: uids repeat across mailboxes) goes.
 * Should something fail half way, the page starts again in the new mailbox, as before.
 */
const switchTo = (email, then) => {
	AccountUserStore.loading(true);
	Remote.request('AccountSwitch', (iError, oData) => {
		if (iError) {
			AccountUserStore.loading(false);
			alert(getNotification(iError).replace('%EMAIL%', email));
			return;
		}
		try {
			const { QuokkaToken, ...settings } = oData?.Result || {};
			QuokkaToken && rl.settings.setToken(QuokkaToken);
			Object.entries(settings).forEach(([key, value]) => rl.settings.set(key, value));
			AccountUserStore.email(rl.settings.get('Email') || email);
			MessageUserStore.message(null);
			[...(MessageUserStore.bodiesDom()?.children || [])].forEach(el => el.remove());
			MessagelistUserStore([]);
			resetSystemFolders();
			SettingsUserStore.init();
			ContactUserStore.init();
		} catch (e) {
			console.error(e);
			location.reload();
			return;
		}
		loadFolders((ok, error) => {
			AccountUserStore.loading(false);
			if (!ok) {
				console.error('Quokka: the folders of ' + email + ' did not load', error);
				location.reload();
				return;
			}
			loadAccountsAndIdentities();
			setTimeout(then, 1);       // out of loadFolders' promise: a failure there is not a failure of the folders
		});
	}, { Email: email });
};

/** Go to a mailbox address; when it is the one already shown (another mailbox now), the list is read again */
const go = hash => {
	const bare = h => String(h || '').replace(/^[#/]+/, '');
	if (bare(location.hash) === bare(hash)) {
		MessagelistUserStore.reload(true, true);
	}
	hasher.setHash(hash);
};

const inboxHash = () => getFolderHashFromCacheList(getFolderInboxName());

/** An email of the list: opened in its mailbox (switching to it when it is another one) */
export const openUnified = msg => {
	// in its own folder (that mailbox's Trash, Sent…): its hash is known once the folders of that mailbox are
	const open = () => {
			const folder = getFolderFromCacheList('INBOX' === msg.folder.toUpperCase() ? getFolderInboxName() : msg.folder);
			// a folder Quokka does not show (not subscribed): said, never another email with the same number
			if (!folder) {
				alert(i18n('QUOKKA/SEARCH_FOLDER_HIDDEN', { FOLDER: msg.folder }));
				return;
			}
			go(mailBox(folder.fullNameHash, 1, MessagelistUserStore.listSearch(), 0, msg.uid));
		},
		start = () => lower(msg.account) === lower(AccountUserStore.email()) ? open() : switchTo(msg.account, open);
	msg.unseen() ? unifiedAct(msg, 'opened', start) : start();
};

/** "All mailboxes" in the column: their Inbox, Sent, Drafts, Spam, Trash, Archive (those some mailbox has) */
export const unifiedFolders = ko.computed(() => {
	translateTrigger();
	FolderUserStore.folderList();
	const all = Object.values(boxFolders()).flat();
	return Object.keys(ROLE_ICONS).filter(role => 'inbox' === role || roleFolder(role) || all.some(f => f.role === role))
		.map(role => ({
			role, label: roleLabel(role), icon: ROLE_ICONS[role],
			unread: 'inbox' === role ? unifiedTotal() : all.filter(f => f.role === role).reduce((n, f) => n + (f.unseen || 0), 0),
			selected: unifiedOn() && currentRole() === role && !/keyword=/.test(MessagelistUserStore.listSearch())
		}));
}, null, deferred);

/** One of the folders of "All mailboxes": shown in the mailbox shown if it has one, else in one that has */
export const openUnifiedRole = role => {
	unifiedOn(true);
	const here = roleFolder(role);
	if (here) {
		go(mailBox(getFolderHashFromCacheList(here)));
		loadUnified();
		return;
	}
	const other = mailboxes().find(b => (boxFolders()[lower(b.email)] || []).some(f => f.role === role));
	other && switchTo(other.email, () => go(mailBox(getFolderHashFromCacheList(roleFolder(role) || getFolderInboxName()))));
};

/** A folder of a mailbox in the column: that mailbox alone, in that folder */
export const openBoxFolder = (box, folder) => {
	unifiedOn(false);
	const hash = () => getFolderHashFromCacheList('inbox' === folder.role ? getFolderInboxName()
		: (folder.role && roleFolder(folder.role)) || folder.name) || inboxHash();
	box.current() ? go(mailBox(hash())) : switchTo(box.email, () => go(mailBox(hash())));
};

// the list follows the search and the filters; the counts in the column come with it (every minute)
ko.computed(() => {
	MessagelistUserStore.listSearch();
	unifiedView();
	currentRole();                                 // another folder: Trash of all the mailboxes, Sent…
	hasMailboxes() && setTimeout(() => loadUnified(), 1);
}).extend({ throttle: 100 });

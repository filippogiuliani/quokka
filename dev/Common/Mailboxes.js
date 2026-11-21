// Quokka: a person's mailboxes ("Caselle" in the column) and "All mailboxes": the INBOX of every one of them in one
// list, from the sorter's index (sorter/inbox.py, through plugins/quokka-extras). One mailbox chosen: Quokka as ever.
import ko from 'ko';

import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore } from 'Stores/User/Folder';
import { MessagelistUserStore } from 'Stores/User/Messagelist';
import { CATEGORIES } from 'Common/Categories';
import { mailBox } from 'Common/Links';
import { getFolderHashFromCacheList, getFolderInboxName } from 'Common/Cache';
import { i18n, getNotification } from 'Common/Translator';

import Remote from 'Remote/User/Fetch';

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
export const unifiedUnread = ko.observable({});         // {mailbox (lower case): unread}
export const unifiedLoading = ko.observable(false);
export const unifiedAvailable = ko.observable(true);    // false: the sorter does not answer
export const unifiedMore = ko.observable(false);
export const unifiedPending = ko.observable([]);        // mailboxes the sorter has not read yet

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
			selected: ko.computed(() => !unifiedOn() && lower(AccountUserStore.email()) === lower(a.email))
		};
	});
}, null, deferred);

export const hasMailboxes = ko.computed(() => 1 < mailboxes().length, null, deferred);

export const unifiedTotal = ko.computed(() => Object.values(unifiedUnread()).reduce((s, n) => s + n, 0), null, deferred);

/** The list shows all the mailboxes: chosen, more than one, in the inbox */
export const unifiedView = ko.computed(() => unifiedOn() && hasMailboxes()
	&& !!FolderUserStore.currentFolder()?.isInbox?.() && !MessagelistUserStore.threadUid(), null, deferred);

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
		threadsLen: () => 0
	};
};

let seq = 0, timer = 0;

/** (Re)load the list; more: the next page after the ones shown */
export const loadUnified = more => {
	if (!hasMailboxes()) return;
	clearTimeout(timer);
	timer = setTimeout(() => loadUnified(), REFRESH);
	const n = ++seq,
		params = { ...filterOf(MessagelistUserStore.listSearch()), offset: more ? unifiedMessages().length : 0 };
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
		unifiedPending(r.pending || []);
		unifiedMore(!!r.more);
		const items = (r.messages || []).map(item);
		more ? unifiedMessages.push(...items) : unifiedMessages(items);
	}, 'QuokkaUnifiedList', params);
};

const countDown = (msg, by) => {
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
	}, 'QuokkaUnifiedAct', { account: msg.account, uids: [msg.uid], action });
};

/** To another of the person's mailboxes, then that address (the page starts again there) */
const switchTo = (email, hash) => {
	AccountUserStore.loading(true);
	Remote.request('AccountSwitch', iError => {
		if (iError) {
			AccountUserStore.loading(false);
			alert(getNotification(iError).replace('%EMAIL%', email));
		} else {
			location.hash = hash;
			location.reload();
		}
	}, { Email: email });
};

const inboxHash = () => getFolderHashFromCacheList(getFolderInboxName());

/** An email of the list: opened in its mailbox (switching to it when it is another one) */
export const openUnified = msg => {
	const go = () => lower(msg.account) === lower(AccountUserStore.email())
		? hasher.setHash(mailBox(inboxHash(), 1, MessagelistUserStore.listSearch(), 0, msg.uid))
		: switchTo(msg.account, '#/mailbox/INBOX/m' + msg.uid);
	msg.unseen() ? unifiedAct(msg, 'opened', go) : go();
};

/** "All mailboxes" (no box) or one of them, from the column */
export const selectMailbox = box => {
	if (!box) {
		unifiedOn(true);
		hasher.setHash(mailBox(inboxHash()));
		loadUnified();
	} else {
		unifiedOn(false);
		box.current() ? hasher.setHash(mailBox(inboxHash())) : switchTo(box.email, '#/mailbox/INBOX');
	}
};

// the list follows the search and the filters; the counts in the column come with it (every minute)
ko.computed(() => {
	MessagelistUserStore.listSearch();
	unifiedView();
	hasMailboxes() && setTimeout(() => loadUnified(), 1);
}).extend({ throttle: 100 });

/**
 * Quokka: Settings → Accounts, each mailbox with its own things, without switching to it: its identities, its
 * folders (new, rename, show or hide in the column, delete) and which of them are Sent, Drafts, Spam, Trash and
 * Archive. Every request names the mailbox in QuokkaAccount (Actions/UserAuth.php getAccountFromToken).
 */
import ko from 'ko';

import Remote from 'Remote/User/Fetch';
import { getNotification, i18n } from 'Common/Translator';
import { Notifications } from 'Common/Enums';
import { showScreenPopup } from 'Knoin/Knoin';
import { IdentityModel } from 'Model/Identity';
import { IdentityPopupView } from 'View/Popup/Identity';
import { loadFolders, quokkaRoleByName } from 'Model/FolderCollection';
import { FolderUserStore } from 'Stores/User/Folder';
import { loadAccountsAndIdentities } from 'Common/UtilsUser';
import { loadUnified } from 'Common/Mailboxes';

const
	lower = s => (s || '').toLowerCase(),
	ROLES = ['sent', 'drafts', 'junk', 'trash', 'archive'],
	STORE = { sent: 'sentFolder', drafts: 'draftsFolder', junk: 'spamFolder', trash: 'trashFolder', archive: 'archiveFolder' },
	isOpen = email => lower(email) === lower(rl.settings.get('Email')),
	request = (action, email, params) => new Promise(resolve =>
		Remote.request(action, (iError, oData) => resolve([iError, oData?.Result]), { ...params, QuokkaAccount: email })),
	plugin = (action, email, params) => new Promise(resolve =>
		rl.pluginRemoteRequest((iError, oData) => resolve([iError, oData?.Result]), action, { ...params, QuokkaAccount: email }));

const boxes = new Map();

/** The panel of one mailbox (kept while the page lives, so an open one stays open) */
export const boxOf = account => {
	const key = lower(account.email);
	if (boxes.has(key)) {
		boxes.get(key).account = account;
		return boxes.get(key);
	}
	const box = {
		account,
		email: account.email,
		open: ko.observable(false),
		loading: ko.observable(false),
		busy: ko.observable(false),
		error: ko.observable(''),
		identities: ko.observableArray([]),
		folders: ko.observableArray([]),
		newName: ko.observable(''),
		newParent: ko.observable(''),
		system: Object.fromEntries(ROLES.map(role => [role, ko.observable('')])),
		loaded: false
	};
	// where a new folder can go: at the top or inside any folder
	box.parents = ko.computed(() => [{ id: '', name: i18n('QUOKKA/BOX_TOP_LEVEL') },
		...box.folders().map(f => ({ id: f.fullName, name: '  '.repeat(f.depth) + f.name }))]);
	// the folders a system role can be
	box.choices = ko.computed(() => [{ id: '', name: i18n('POPUPS_SYSTEM_FOLDERS/SELECT_CHOOSE_ONE') },
		{ id: '__UNUSE__', name: i18n('POPUPS_SYSTEM_FOLDERS/SELECT_UNUSE_NAME') },
		...box.folders().filter(f => 'INBOX' !== f.fullName.toUpperCase()).map(f => ({ id: f.fullName, name: f.fullName }))]);
	ROLES.forEach(role => box.system[role].subscribe(() => box.loaded && !box.settingSystem && saveSystem(box)));
	boxes.set(key, box);
	return box;
};

const SPECIAL_USE = ['\\sent', '\\drafts', '\\junk', '\\trash', '\\archive', '\\all', '\\flagged', '\\important'];

const folderRow = (box, json, system) => {
	const delim = json.delimiter || '', parts = delim ? json.fullName.split(delim) : [json.fullName],
		attrs = (json.attributes || []).map(lower), name = parts[parts.length - 1];
	return {
		fullName: json.fullName,
		name,
		parts,
		parent: parts.slice(0, -1).join(delim),
		delim,
		depth: parts.length - 1,
		selectable: !attrs.includes('\\noselect') && !attrs.includes('\\nonexistent'),
		subscribed: ko.observable(attrs.includes('\\subscribed')),
		// the INBOX, the chosen system folders and those the server or their name says are (not renamed or deleted here)
		system: 'INBOX' === json.fullName.toUpperCase() || Object.values(system).includes(json.fullName)
			|| attrs.some(a => SPECIAL_USE.includes(a)) || !!quokkaRoleByName({ fullName: json.fullName, name }),
		renaming: ko.observable(false),
		rename: ko.observable(''),
		askDelete: ko.observable(false)
	};
};

const loadFoldersOf = async box => {
	const [iError, result] = await request('Folders', box.email);
	if (iError) {
		box.error(getNotification(iError));
		return;
	}
	const system = Object.fromEntries(ROLES.map(role => [role, box.system[role]()]));
	const rows = (result?.['@Collection'] || []).map(json => folderRow(box, json, system)),
		key = f => 'INBOX' === f.fullName.toUpperCase() ? [''] : f.parts.map(p => p.toLocaleLowerCase());
	rows.sort((a, b) => {             // as a tree: INBOX, then each folder followed by the ones inside it
		const ka = key(a), kb = key(b);
		for (let i = 0; i < Math.min(ka.length, kb.length); ++i) {
			const c = ka[i].localeCompare(kb[i]);
			if (c) return c;
		}
		return ka.length - kb.length;
	});
	box.folders(rows);
};

/** Everything of this mailbox, from its own server and settings */
export const loadBox = async box => {
	box.loading(true);
	box.error('');
	const [[e1, ids], [e2, info]] = await Promise.all([
		request('AccountsAndIdentities', box.email), plugin('QuokkaBoxInfo', box.email)]);
	if (e1 || e2) {
		box.error(getNotification(e1 || e2));
	} else {
		box.identities((ids?.Identities || []).map(json => IdentityModel.reviveFromJson(json)).filter(v => v));
		box.settingSystem = true;
		ROLES.forEach(role => box.system[role](info?.[role] || ''));
		box.settingSystem = false;
	}
	await loadFoldersOf(box);
	box.loaded = true;
	box.loading(false);
};

export const toggleBox = box => {
	box.open(!box.open());
	box.open() && !box.loaded && loadBox(box);
};

/** After a change of its folders: here, in the column (the sorter reads them again) and, for the open mailbox, its tree */
const foldersChanged = async box => {
	await loadFoldersOf(box);
	isOpen(box.email) && loadFolders();
	await plugin('QuokkaBoxRefresh', box.email);
	loadUnified();
};

/**
 * Some mail servers answer oddly right after a change (the folder is there, the answer says it failed): what
 * counts is how the folders are afterwards
 */
const folderAction = async (box, action, params, done) => {
	box.busy(true);
	box.error('');
	const [iError] = await request(action, box.email, params);
	await foldersChanged(box);
	box.busy(false);
	if (iError && !done(box.folders())) {
		box.error(getNotification(iError, '', Notifications.UnknownError));
	}
};

export const createFolder = box => {
	const name = box.newName().trim(), parent = box.newParent();
	if (!name || box.busy()) return;
	const inside = box.folders().find(f => f.fullName === parent),
		full = parent ? parent + (inside?.delim || box.folders().find(f => f.delim)?.delim || '.') + name : name;
	folderAction(box, 'FolderCreate', { folder: name, parent, subscribe: 1 },
		folders => folders.some(f => f.fullName === full))
		.then(() => box.folders().some(f => f.fullName === full) && box.newName(''));
};

export const startRename = (box, folder) => {
	box.folders().forEach(f => f.renaming(false));
	folder.rename(folder.name);
	folder.renaming(true);
};

export const renameFolder = (box, folder) => {
	const name = folder.rename().trim();
	folder.renaming(false);
	if (!name || name === folder.name) return;
	const full = (folder.parent ? folder.parent + folder.delim : '') + name;
	folderAction(box, 'FolderRename', { oldName: folder.fullName, newName: full, subscribe: folder.subscribed() ? 1 : 0 },
		folders => folders.some(f => f.fullName === full));
};

export const deleteFolder = (box, folder) => {
	if (!folder.askDelete()) {
		box.folders().forEach(f => f.askDelete(false));
		folder.askDelete(true);
		return;
	}
	folderAction(box, 'FolderDelete', { folder: folder.fullName },
		folders => !folders.some(f => f.fullName === folder.fullName));
};

export const toggleSubscribed = async (box, folder) => {
	const subscribe = !folder.subscribed();
	folder.subscribed(subscribe);
	const [iError] = await request('FolderSubscribe', box.email, { folder: folder.fullName, subscribe: subscribe ? 1 : 0 });
	if (iError) {
		folder.subscribed(!subscribe);
		box.error(getNotification(iError));
		return;
	}
	isOpen(box.email) && loadFolders();
	await plugin('QuokkaBoxRefresh', box.email);
	loadUnified();
};

const saveSystem = box => {
	const folders = Object.fromEntries(ROLES.map(role => [role, box.system[role]()]));
	request('SystemFoldersUpdate', box.email, folders).then(([iError]) => {
		if (iError) {
			box.error(getNotification(iError));
		} else if (isOpen(box.email)) {
			// the open mailbox uses them at once
			ROLES.forEach(role => {
				rl.settings.set(role + 'Folder', folders[role]);
				FolderUserStore[STORE[role]](folders[role]);
			});
		}
		loadFoldersOf(box);
	});
};

export const editIdentity = (box, identity) =>
	showScreenPopup(IdentityPopupView, [identity || null, box.email, () => loadBox(box)]);

export const deleteIdentity = async (box, identity) => {
	if (!identity.askDelete()) {
		box.identities().forEach(i => i.askDelete(false));
		identity.askDelete(true);
		return;
	}
	box.identities.remove(identity);
	const [iError] = await request('IdentityDelete', box.email, { idToDelete: identity.id() });
	iError && box.error(getNotification(iError));
	loadAccountsAndIdentities();
	loadBox(box);
};

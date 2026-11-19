import ko from 'ko';
import { addObservablesTo, addComputablesTo, addSubscribablesTo } from 'External/ko';

import { UNUSED_OPTION_VALUE } from 'Common/Consts';
import { ScopeFolderList, ScopeMessageList, ScopeMessageView } from 'Common/Enums';

import {
	ComposeType,
	ClientSideKeyNameMessageHeaderFullInfo,
	ClientSideKeyNameMessageAttachmentControls,
	FolderType,
	MessageSetAction
} from 'Common/EnumsUser';
import { RFC822 } from 'Common/File';
import {
	mailRoute,
	elementById,
	keyScopeReal,
	Settings,
	SettingsCapa,
	fireEvent,
	stopEvent,
	addShortcut,
	registerShortcut,
	formFieldFocused
} from 'Common/Globals';
import { encodeHtml } from 'Common/Html';
import { mailBox } from 'Common/Links';
import { arrayLength } from 'Common/Utils';
import { download, downloadZip, mailToHelper, showMessageComposer, moveAction } from 'Common/UtilsUser';
import { isFullscreen, exitFullscreen, toggleFullscreen } from 'Common/Fullscreen';

import { SMAudio } from 'Common/Audio';

import { i18n } from 'Common/Translator';

import { AppUserStore } from 'Stores/User/App';
import { SettingsUserStore } from 'Stores/User/Settings';
import { AccountUserStore } from 'Stores/User/Account';
import { FolderUserStore, isAllowedKeyword } from 'Stores/User/Folder';
import { MessageUserStore } from 'Stores/User/Message';
import { MessagelistUserStore } from 'Stores/User/Messagelist';

import * as Local from 'Storage/Client';

import Remote from 'Remote/User/Fetch';

import { decorateKoCommands } from 'Knoin/Knoin';
import { AbstractViewRight } from 'Knoin/AbstractViews';

import { PgpUserStore } from 'Stores/User/Pgp';

import { MimeToMessage } from 'Mime/Utils';

import { MessageModel } from 'Model/Message';
import { CATEGORIES } from 'Common/Categories';
import { readBill, euros, dayMonth } from 'Common/Bill';

import { showScreenPopup } from 'Knoin/Knoin';
import { OpenPgpImportPopupView } from 'View/Popup/OpenPgpImport';
import { AskPopupView } from 'View/Popup/Ask';
import { suspiciousReasons } from 'Common/Suspicious';
import { GnuPGUserStore } from 'Stores/User/GnuPG';
import { OpenPGPUserStore } from 'Stores/User/OpenPGP';

const
	oMessageScrollerDom = () => elementById('messageItem') || {},

	currentMessage = MessageUserStore.message,

	setAction = action => {
		const message = currentMessage();
		message && MessagelistUserStore.setAction(message.folder, action, [message]);
	},

	fetchRaw = url => rl.fetch(url).then(response => response.ok && response.text()),

	/*
	 * The smime.p7m is the envelope the message arrived in rather than something
	 * somebody attached, so listing it is noise, and before decryption it is the
	 * only thing in the list. Conditional on the message being encrypted, so a
	 * .p7m genuinely attached to ordinary mail is still shown.
	 */
	attachmentListed = item =>
		(SettingsUserStore.listInlineAttachments() || !item.isLinked())
		&& !(currentMessage()?.smimeEncrypted() && (item.mimeType || '').toLowerCase().includes('pkcs7'));

/** Quokka: an error of the quokka-extras plugin ({error: 'ERR_…', app, status}) in the person's language */
const appError = r => i18n('QUOKKA_EXTRAS/' + r.error, { APP: r.app || '', STATUS: r.status || '' });

export class MailMessageView extends AbstractViewRight {
	constructor() {
		super();

		const
			/**
			 * @param {Function} fExecute
			 * @param {Function} fCanExecute = true
			 * @returns {Function}
			 */
			createCommand = (fExecute, fCanExecute) => {
				let fResult = () => {
						fCanExecute() && fExecute.call(null);
						return false;
					};
				fResult.canExecute = fCanExecute;
				return fResult;
			},

			createCommandReplyHelper = type =>
				createCommand(() => this.replyOrforward(type), this.canBeRepliedOrForwarded),

			createCommandActionHelper = (folderType, bDelete) =>
				createCommand(() => {
					const message = currentMessage();
					if (message) {
						currentMessage(null);
						rl.app.moveMessagesToFolderType(folderType, message.folder, new Set([message.uid]), bDelete);
					}
				}, this.messageVisible),
			createCommandSetHelper = action =>
				createCommand(() => setAction(action), this.messageVisible),
			createCommandDeleteHelper = () =>
				createCommand(() => {
					/**
					 * When FolderUserStore.trashFolder is set to "Do not use",
					 * flag as \Deleted for removal by later EXPUNGE
					 */
					if (UNUSED_OPTION_VALUE === FolderUserStore.trashFolder()) {
						setAction(MessageSetAction.SetDeleted);
					} else {
						const message = currentMessage();
						if (message) {
							currentMessage(null);
							rl.app.moveMessagesToFolderType(FolderType.Trash, message.folder, new Set([message.uid]));
						}
					}
				}, this.messageVisible);

		this.msgDefaultAction = SettingsUserStore.msgDefaultAction;
		this.simpleAttachmentsList = SettingsUserStore.simpleAttachmentsList;

		addObservablesTo(this, {
			showAttachmentControls: !!Local.get(ClientSideKeyNameMessageAttachmentControls),
			downloadAsZipLoading: false,
			showFullInfo: '1' === Local.get(ClientSideKeyNameMessageHeaderFullInfo),
			// bootstrap dropdown
			actionsMenu: null,
			// viewer
			viewFromShort: '',
			dkimData: ['none', '', ''],
			spfData: ['none', '', ''],
			dmarcData: ['none', '', ''],
			localDelivery: false,
			nowTracking: false
		});

		this.moveAction = moveAction;

		const attachmentsActions = Settings.app('attachmentsActions');
		this.attachmentsActions = ko.observableArray(arrayLength(attachmentsActions) ? attachmentsActions : []);

		this.hasCheckedMessages = MessagelistUserStore.hasChecked;
		this.archiveAllowed = MessagelistUserStore.archiveAllowed;
		this.canMarkAsSpam = MessagelistUserStore.canMarkAsSpam;
		this.isDraftFolder = MessagelistUserStore.isDraftFolder;
		this.isSpamFolder = MessagelistUserStore.isSpamFolder;

		this.message = currentMessage;
		this.messageLoadingThrottle = MessageUserStore.loading;
		this.messageError = MessageUserStore.error;

		this.fullScreenMode = isFullscreen;
		this.toggleFullScreen = toggleFullscreen;

		this.downloadAsZipError = ko.observable(false).extend({ falseTimeout: 7000 });

		this.messageDomFocused = ko.observable(false).extend({ rateLimit: 0 });

		// viewer
		this.viewHash = '';

		// Quokka: the email's category, to change in one step (the sorter learns from it)
		this.categoryChoices = ko.computed(() => [{ key: '', label: i18n('QUOKKA/CATEGORY_NONE') }].concat(CATEGORIES().map(([key, label]) => ({ key, label }))));
		// Quokka: a bill's amount, due date and period, and the way to Pelican and Kooka
		this.billStatus = ko.observable('');
		this.billBusy = ko.observable(false);
		this.bill = ko.computed(() => {
			const message = currentMessage();
			if (!message || 'bills' !== message.categoryKey()) {
				return null;
			}
			const text = message.plain() || (message.html() || '').replace(/<(style|script)[\s\S]*?<\/\1>|<[^>]+>/gi, ' ')
				.replace(/&nbsp;/g, ' ').replace(/&euro;/g, '€').replace(/&#?\w+;/g, ' ');
			const found = readBill(text),
				pdf = message.attachments().find(a => /pdf$/i.test(a.mimeType) || /\.pdf$/i.test(a.fileName));
			return {
				amount: found.amount ? euros(found.amount) : '',
				due: found.due ? dayMonth(found.due) : '',
				dueIso: found.due,
				period: found.period || '',
				pdf: pdf || null,
				what: pdf ? pdf.fileName : i18n('QUOKKA/BILL_WHOLE_EMAIL'),
				pelicanTitle: i18n('QUOKKA/BILL_PELICAN_TITLE', { WHAT: pdf ? pdf.fileName : i18n('QUOKKA/BILL_WHOLE_EMAIL') }),
				kookaTitle: found.due ? '' : i18n('QUOKKA/BILL_NO_DUE')
			};
		});
		this.bill.subscribe(() => this.billStatus(''));
		this.billToPelican = () => {
			const message = currentMessage(), bill = this.bill();
			if (!message || !bill || this.billBusy()) return;
			this.billBusy(true);
			this.billStatus(i18n('QUOKKA/BILL_SENDING_PELICAN'));
			rl.pluginRemoteRequest((iError, oData) => {
				this.billBusy(false);
				const r = oData?.Result;
				this.billStatus(iError ? i18n('QUOKKA/BILL_PELICAN_DOWN') : r?.error ? appError(r) : i18n('duplicate' === r?.status ? 'QUOKKA/BILL_PELICAN_DUPLICATE' : 'QUOKKA/BILL_PELICAN_DONE'));
			}, 'QuokkaExtrasToPelican', {
				folder: message.folder,
				uid: message.uid,
				mimeIndex: bill.pdf ? bill.pdf.mimeIndex : '',
				fileName: bill.pdf ? bill.pdf.fileName : (message.subject() || 'email') + '.eml',
				title: [message.from?.[0]?.name || message.from?.[0]?.email, bill.period || message.subject()].filter(Boolean).join(' – ')
			}, 60000);
		};
		this.billToKooka = () => {
			const message = currentMessage(), bill = this.bill();
			if (!message || !bill?.dueIso || this.billBusy()) return;
			this.billBusy(true);
			this.billStatus(i18n('QUOKKA/BILL_SENDING_KOOKA'));
			rl.pluginRemoteRequest((iError, oData) => {
				this.billBusy(false);
				const r = oData?.Result;
				this.billStatus(iError ? i18n('QUOKKA/BILL_KOOKA_DOWN') : r?.error ? appError(r) : i18n('QUOKKA/BILL_KOOKA_DONE'));
			}, 'QuokkaExtrasToKooka', {
				title: i18n('QUOKKA/BILL_PAY', { WHAT: [message.from?.[0]?.name || message.from?.[0]?.email, bill.period].filter(Boolean).join(' – ')
					+ (bill.amount ? ' (' + bill.amount + ')' : '') }),
				dueDate: bill.dueIso,
				notes: message.subject()
			});
		};
		this.notABill = () => currentMessage()?.setCategory('');

		// Quokka: an email that may be a scam (Common/Suspicious.js); "Mi fido" is remembered on this device
		const trustedKey = 'quokka-trusted-senders',
			readTrusted = () => { try { return JSON.parse(localStorage.getItem(trustedKey) || '[]'); } catch (e) { return []; } };
		this.trustedSenders = ko.observableArray(readTrusted());
		this.suspicious = ko.computed(() => {
			const message = currentMessage();
			if (!message || this.isSpamFolder?.()) return [];
			message.html(); message.plain();          // again once the body has arrived
			const email = (message.from?.[0]?.email || '').toLowerCase();
			return this.trustedSenders().includes(email) ? [] : suspiciousReasons(message);
		});
		this.trustSender = () => {
			const email = (currentMessage()?.from?.[0]?.email || '').toLowerCase();
			if (!email) return;
			const list = readTrusted().filter(e => e !== email).concat(email).slice(-200);
			try { localStorage.setItem(trustedKey, JSON.stringify(list)); } catch (e) {}
			this.trustedSenders(list);
		};

		// Quokka: "Annulla iscrizione" on newsletters (List-Unsubscribe); the server reads the header again and,
		// when the sender allows it (one-click), unsubscribes by itself
		this.unsubscribeStatus = ko.observable('');
		this.unsubscribeBusy = ko.observable(false);
		this.canUnsubscribe = ko.computed(() => {
			const message = currentMessage();
			return !!message && /<\s*(https?|mailto):/i.test(message.headers().valueByName('List-Unsubscribe') || '');
		});
		currentMessage.subscribe(() => this.unsubscribeStatus(''));
		this.unsubscribe = () => {
			const message = currentMessage();
			if (!message || this.unsubscribeBusy()) return;
			const who = message.from?.[0]?.name || message.from?.[0]?.email || i18n('QUOKKA/UNSUBSCRIBE_THIS_SENDER');
			AskPopupView.showModal([
				i18n('QUOKKA/UNSUBSCRIBE_ASK', { WHO: who }),
				() => {
					this.unsubscribeBusy(true);
					this.unsubscribeStatus(i18n('QUOKKA/UNSUBSCRIBING'));
					rl.pluginRemoteRequest((iError, oData) => {
						this.unsubscribeBusy(false);
						const r = oData?.Result;
						if (iError || !r) {
							this.unsubscribeStatus(i18n('QUOKKA/UNSUBSCRIBE_FAILED'));
						} else if (r.done) {
							this.unsubscribeStatus(i18n('QUOKKA/UNSUBSCRIBE_DONE', { WHO: who }));
						} else if (r.mailto) {
							this.unsubscribeStatus(i18n('QUOKKA/UNSUBSCRIBE_MAILTO'));
							mailToHelper(r.mailto);
						} else if (r.url) {
							this.unsubscribeStatus(i18n('QUOKKA/UNSUBSCRIBE_PAGE'));
							window.open(r.url, '_blank', 'noopener,noreferrer');
						} else {
							this.unsubscribeStatus(r.error ? appError(r) : i18n('QUOKKA/UNSUBSCRIBE_FAILED'));
						}
					}, 'QuokkaExtrasUnsubscribe', { folder: message.folder, uid: message.uid }, 30000);
				},
				null, true, 0, 'QUOKKA/UNSUBSCRIBE'
			]);
		};

		// Quokka: the sender's initial, in a circle next to the name
		this.senderInitial = ko.computed(() => {
			const from = currentMessage()?.from?.[0];
			return ((from?.name || from?.email || '?').trim().charAt(0) || '?').toUpperCase();
		});
		this.messageCategory = ko.computed({
			read: () => currentMessage()?.categoryKey() || '',
			write: key => currentMessage()?.setCategory(key)
		});

		addComputablesTo(this, {
			allowAttachmentControls: () => arrayLength(attachmentsActions) && SettingsCapa('AttachmentsActions'),

			downloadAsZipAllowed: () => this.attachmentsActions.includes('zip')
				&& (currentMessage()?.attachments || [])
					.filter(item => item?.checked() && item?.download /*&& !item?.isLinked()*/)
					.length,

			tagsAllowed: () => FolderUserStore.currentFolder()?.tagsAllowed(),

			messageVisible: () => !MessageUserStore.loading() && !!currentMessage(),

			tagsToHTML: () => currentMessage()?.flags().map(value =>
					isAllowedKeyword(value)
					? '<span class="focused msgflag-'+encodeHtml(value)+'">'
						+ encodeHtml(i18n('MESSAGE_TAGS/'+value,0,value)) + '</span>'
					: ''
				).join(' '),

			askReadReceipt: () => currentMessage()?.readReceipt
				&& !(MessagelistUserStore.isDraftFolder() || MessagelistUserStore.isSentFolder())
				&& !currentMessage()?.flags().includes('$mdnsent')
				&& !currentMessage()?.flags().includes('\\answered'),

			listAttachments: () => currentMessage()?.attachments().filter(attachmentListed),
//			hasAttachments: () => currentMessage()?.attachments()?.length,
			hasAttachments: () => currentMessage()?.attachments().some(attachmentListed),
//			listInline: () => currentMessage()?.attachments().filter(item => item.isLinked()),
//			hasInline: () => currentMessage()?.attachments().some(item => item.isLinked()),

			canBeRepliedOrForwarded: () => !MessagelistUserStore.isDraftFolder() && this.messageVisible(),

			dkimIcon: () => {
				switch (this.dkimData()[0]) {
					case 'none':
						return '︎'; // 🚫
					case 'pass':
						return '✔';
					default:
						return '✖';
				}
			},
			dkimIconClass: () => 'pass' === this.dkimData()[0] ? 'iconcolor-green' : 'iconcolor-red',
			dkimTitle: () => {
				const dkim = this.dkimData();
				return dkim[0] ? dkim[2] || 'DKIM: ' + dkim[0] : '';
			},

			spfIcon: () => {
				switch (this.spfData()[0]) {
					case 'none':
						return '︎'; // 🚫
					case 'pass':
						return '✔';
					default:
						return '✖';
				}
			},
			spfIconClass: () => 'pass' === this.spfData()[0] ? 'iconcolor-green' : 'iconcolor-red',
			spfTitle:() => {
				const spf = this.spfData();
				return spf[0] ? spf[2] || 'SPF: ' + spf[0] : '';
			},

			dmarcIcon: () => {
				switch (this.dmarcData()[0]) {
					case 'none':
						return '︎'; // 🚫
					case 'pass':
						return '✔';
					default:
						return '✖';
				}
			},
			dmarcIconClass: () => 'pass' === this.dmarcData()[0] ? 'iconcolor-green' : 'iconcolor-red',
			dmarcTitle:() => {
				const dmarc = this.dmarcData();
				return dmarc[0] ? dmarc[2] || 'DMARC: ' + dmarc[0] : '';
			},

			showWhitelistOptions: () => 'match' === SettingsUserStore.viewImages(),

			firstUnsubsribeLink: () => currentMessage()?.unsubsribeLinks()[0] || '',

			pgpSupported: () => currentMessage() && PgpUserStore.isSupported(),

			canBeUndeleted: () => currentMessage()?.isDeleted(),

			messageListOrViewLoading:
				() => MessagelistUserStore.isLoading() | MessageUserStore.loading()
		});

		addSubscribablesTo(this, {
			message: message => {
				if (message) {
					if (this.viewHash !== message.hash) {
						this.scrollMessageToTop();
					}
					this.viewHash = message.hash;
					// TODO: make first param a user setting #683
					this.viewFromShort(message.from.toString(false, true));
					this.dkimData(message.dkim[0] || ['none', '', '']);
					this.spfData(message.spf[0] || ['none', '', '']);
					this.dmarcData(message.dmarc[0] || ['none', '', '']);
					this.localDelivery(message.headers().isLocalDelivery());
					this.nowTracking(false);
				} else {
					this.viewHash = '';

					this.scrollMessageToTop();
				}
			},

			showFullInfo: value => Local.set(ClientSideKeyNameMessageHeaderFullInfo, value ? '1' : '0')
		});

		// commands
		this.replyCommand = createCommandReplyHelper(ComposeType.Reply);
		this.replyAllCommand = createCommandReplyHelper(ComposeType.ReplyAll);
		this.forwardCommand = createCommandReplyHelper(ComposeType.Forward);
		this.forwardAsAttachmentCommand = createCommandReplyHelper(ComposeType.ForwardAsAttachment);
		this.editAsNewCommand = createCommandReplyHelper(ComposeType.EditAsNew);

//		this.deleteCommand = createCommandActionHelper(FolderType.Trash);
		// User setting hideDeleted || immediatelyMoveToTrash ??
		this.deleteCommand = createCommandDeleteHelper();
		// User setting !hideDeleted && !immediatelyMoveToTrash ??
		this.undeleteCommand = createCommandSetHelper(MessageSetAction.UnsetDeleted);
		this.deleteWithoutMoveCommand = createCommandActionHelper(FolderType.Trash, true);
		this.archiveCommand = createCommandActionHelper(FolderType.Archive);
		this.spamCommand = createCommandActionHelper(FolderType.Junk);
		this.notSpamCommand = createCommandActionHelper(FolderType.Inbox);

		decorateKoCommands(this, {
			editCommand: self => self.messageVisible(),
			moveCommand: self => self.messageVisible(),
			copyCommand: self => self.messageVisible(),
			goUpCommand: self => !self.messageListOrViewLoading(),
			goDownCommand: self => !self.messageListOrViewLoading()
		});
	}

	toggleFullInfo() {
		this.showFullInfo(!this.showFullInfo());
	}

	closeMessage() {
		currentMessage(null);
		// The address bar still named the message that just closed, so a reload
		// or a shared link would have reopened it. Replace rather than push: the
		// entry this is undoing was pushed when the message opened, so Back still
		// goes back to wherever the reader came from.
		const folder = FolderUserStore.currentFolder();
		folder && hasher.replaceHash(mailBox(
			folder.fullNameHash,
			mailRoute.page(),
			mailRoute.search(),
			mailRoute.threadUid()
		));
	}

	editCommand() {
		currentMessage() && showMessageComposer([ComposeType.Draft, currentMessage()]);
	}

	moveOrCopy(vm, event, mode) {
		if (vm && event?.preventDefault) {
			stopEvent(event);
		}
		this.actionsMenu().ddBtn.hide();
		AppUserStore.focusedState(ScopeFolderList);
		moveAction(mode);
	}

	moveCommand(vm, event) {
		this.moveOrCopy(vm, event, 1);
	}

	copyCommand(vm, event) {
		this.moveOrCopy(vm, event, 2);
	}

	setUnseen() {
		setAction(MessageSetAction.UnsetSeen);
		currentMessage(null);
	}

	goUpCommand() {
		fireEvent('mailbox.message-list.selector.go-up',
			!!currentMessage() // bForceSelect
		);
	}

	goDownCommand() {
		fireEvent('mailbox.message-list.selector.go-down',
			!!currentMessage() // bForceSelect
		);
	}

	/**
	 * @param {string} sType
	 * @returns {void}
	 */
	replyOrforward(sType) {
		showMessageComposer([sType, currentMessage()]);
	}

	onBuild(dom) {
		const eqs = (ev, s) => ev.target.closestWithin(s, dom);
		dom.addEventListener('click', event => {
			let el = eqs(event, 'a');
			if (el && 0 === event.button && mailToHelper(el.href)) {
				stopEvent(event);
				return;
			}

			el = eqs(event, '.attachmentsPlace .showPreview');
			if (el) {
				const attachment = ko.dataFor(el), url = attachment?.linkDownload();
//				if (url && FileType.Eml === attachment.fileType) {
				if (url && RFC822 == attachment.mimeType) {
					stopEvent(event);
					fetchRaw(url).then(text => {
						const oMessage = new MessageModel();
						MimeToMessage(text, oMessage);
						// cleanHTML
						oMessage.popupMessage();
					});
				}
				return;
			}

			el = eqs(event, '.attachmentsPlace .showPreplay');
			if (el) {
				stopEvent(event);
				const attachment = ko.dataFor(el);
				if (attachment && SMAudio.supported) {
					switch (true) {
						case SMAudio.supportedMp3 && attachment.isMp3():
							SMAudio.playMp3(attachment.linkDownload(), attachment.fileName);
							break;
						case SMAudio.supportedOgg && attachment.isOgg():
							SMAudio.playOgg(attachment.linkDownload(), attachment.fileName);
							break;
						case SMAudio.supportedWav && attachment.isWav():
							SMAudio.playWav(attachment.linkDownload(), attachment.fileName);
							break;
						// no default
					}
				}
				return;
			}

			el = eqs(event, '.attachmentItem');
			if (el) {
				const attachment = ko.dataFor(el), url = attachment?.linkDownload();
				if (url) {
					if ('application/pgp-keys' == attachment.mimeType
					 && (OpenPGPUserStore.isSupported() || GnuPGUserStore.isSupported())) {
						fetchRaw(url).then(text =>
							showScreenPopup(OpenPgpImportPopupView, [text])
						);
					} else {
						download(url, attachment.fileName);
					}
				}
			}

			if (eqs(event, '.messageItemHeader .subjectParent .flagParent')) {
				setAction(currentMessage()?.isFlagged() ? MessageSetAction.UnsetFlag : MessageSetAction.SetFlag);
			}
		});

		keyScopeReal.subscribe(value => this.messageDomFocused(ScopeMessageView === value));

		// initShortcuts

		// exit fullscreen, back
		addShortcut('escape', '', ScopeMessageView, () => {
			if (!this.viewModelDom.hidden && currentMessage()) {
				const preview = SettingsUserStore.usePreviewPane();
				if (isFullscreen()) {
					exitFullscreen();
					if (preview) {
						AppUserStore.focusedState(ScopeMessageList);
					}
				} else if (!preview) {
					currentMessage(null);
				} else {
					AppUserStore.focusedState(ScopeMessageList);
				}

				return false;
			}
		});

		// fullscreen
		addShortcut('enter,open', '', ScopeMessageView, () => {
			isFullscreen() || toggleFullscreen();
			return false;
		});

		// reply
		registerShortcut('r,mailreply', '', [ScopeMessageList, ScopeMessageView], () => {
			if (currentMessage()) {
				this.replyCommand();
				return false;
			}
			return true;
		});

		// replyAll
		registerShortcut('a', '', [ScopeMessageList, ScopeMessageView], () => {
			if (currentMessage()) {
				this.replyAllCommand();
				return false;
			}
		});
		registerShortcut('mailreply', 'shift', [ScopeMessageList, ScopeMessageView], () => {
			if (currentMessage()) {
				this.replyAllCommand();
				return false;
			}
		});

		// forward
		registerShortcut('f,mailforward', '', [ScopeMessageList, ScopeMessageView], () => {
			if (currentMessage()) {
				this.forwardCommand();
				return false;
			}
		});

		// message information
		registerShortcut('i', 'meta', [ScopeMessageList, ScopeMessageView], () => {
			currentMessage() && this.toggleFullInfo();
			return false;
		});

		// toggle message blockquotes
		registerShortcut('b', '', [ScopeMessageList, ScopeMessageView], () => {
			const message = currentMessage();
			if (message?.body) {
				message.body.querySelectorAll('details').forEach(node => node.open = !node.open);
				return false;
			}
		});

		addShortcut('b', 'shift', [ScopeMessageList, ScopeMessageView], () => {
			if (!formFieldFocused()) {
				currentMessage()?.swapColors?.();
				return false;
			}
		});

		addShortcut('arrowup,arrowleft', 'meta', [ScopeMessageList, ScopeMessageView], () => {
			this.goUpCommand();
			return false;
		});

		addShortcut('arrowdown,arrowright', 'meta', [ScopeMessageList, ScopeMessageView], () => {
			this.goDownCommand();
			return false;
		});

		// delete
		addShortcut('delete', '', ScopeMessageView, () => {
			this.deleteCommand();
			return false;
		});
		addShortcut('delete', 'shift', ScopeMessageView, () => {
			this.deleteWithoutMoveCommand();
			return false;
		});

		// change focused state
		addShortcut('arrowleft', '', ScopeMessageView, () => {
			if (!isFullscreen() && currentMessage() && SettingsUserStore.usePreviewPane()
			 && !oMessageScrollerDom().scrollLeft) {
				AppUserStore.focusedState(ScopeMessageList);
				return false;
			}
		});
		addShortcut('tab', 'shift', ScopeMessageView, () => {
			if (!isFullscreen() && currentMessage() && SettingsUserStore.usePreviewPane()) {
				AppUserStore.focusedState(ScopeMessageList);
			}
			return false;
		});

		MessageUserStore.bodiesDom(dom.querySelector('.bodyText'));
	}

	scrollMessageToTop() {
		oMessageScrollerDom().scrollTop = 0;
	}

	scrollMessageToLeft() {
		oMessageScrollerDom().scrollLeft = 0;
	}

	toggleAttachmentControls() {
		const b = !this.showAttachmentControls();
		this.showAttachmentControls(b);
		Local.set(ClientSideKeyNameMessageAttachmentControls, b);
	}

	downloadAsZip() {
		const hashes = (currentMessage()?.attachments || [])
			.map(item => item?.checked() /*&& !item?.isLinked()*/ ? item.download : '')
			.filter(v => v);
		downloadZip(
			currentMessage().subject(),
			hashes,
			() => this.downloadAsZipError(true),
			this.downloadAsZipLoading
		);
	}

	/**
	 * @param {MessageModel} oMessage
	 * @returns {void}
	 */
	showImages() {
		currentMessage().showExternalImages();
	}

	showTracking() {
		const msg = currentMessage(), body = msg?.body;
		if (body && msg.hasTracking()) {
			let attr = 'data-x-href-tracking';
			body.querySelectorAll('a['+attr+']').forEach(node => node.href = node.getAttribute(attr));
//			attr = 'data-x-src-tracking';
//			body.querySelectorAll('img['+attr+']').forEach(node => node.src = node.getAttribute(attr));
			this.nowTracking(true);
		}
	}

	whitelistText(txt) {
		let value = (SettingsUserStore.viewImagesWhitelist().trim() + '\n' + txt).trim();
/*
		if ('pass' === currentMessage().spf[0]?.[0]) value += '+spf';
		if ('pass' === currentMessage().dkim[0]?.[0]) value += '+dkim';
		if ('pass' === currentMessage().dmarc[0]?.[0]) value += '+dmarc';
*/
		SettingsUserStore.viewImagesWhitelist(value);
		Remote.saveSetting('ViewImagesWhitelist', value);
		currentMessage().showExternalImages(1);
	}

	/**
	 * @returns {string}
	 */
	printableCheckedMessageCount() {
		const cnt = MessagelistUserStore.listCheckedOrSelectedUidsWithSubMails().size;
		return 0 < cnt ? (100 > cnt ? cnt : '99+') : '';
	}

	/**
	 * @param {MessageModel} oMessage
	 * @returns {void}
	 */
	readReceipt() {
		let oMessage = currentMessage();
		if (oMessage.readReceipt) {
			oMessage.flags.push('$mdnsent');
			Remote.request('SendReadReceiptMessage',
				iError => iError && oMessage.flags.remove('$mdnsent'),
				{
					messageFolder: oMessage.folder,
					messageUid: oMessage.uid,
					readReceipt: oMessage.readReceipt,
					subject: i18n('READ_RECEIPT/SUBJECT', { SUBJECT: oMessage.subject() }),
					plain: i18n('READ_RECEIPT/BODY', { 'READ-RECEIPT': AccountUserStore.email() })
				}
			);
		}
	}

	newTag() {
		let message = currentMessage();
		if (message) {
			let keyword = prompt(i18n('MESSAGE/NEW_TAG'), '')?.replace(/[\s\\]+/g, '');
			if (keyword.length && isAllowedKeyword(keyword)) {
				message.toggleTag(keyword);
				FolderUserStore.currentFolder().permanentFlags.push(keyword);
			}
		}
	}

	pgpDecrypt() {
		currentMessage().decrypt();
	}

	pgpVerify(/*self, event*/) {
		currentMessage().pgpVerify();
	}

	async smimeDecrypt() {
		currentMessage().decrypt();
	}

	smimeVerify(/*self, event*/) {
		currentMessage().smimeVerify();
	}

}

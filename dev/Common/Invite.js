// Quokka: an invitation in an email (.ics): what it is, what is already on at that time in Kooka, and the answer
// (plugins/quokka-extras: QuokkaInvite, QuokkaInviteAnswer; Kooka is the calendar)
import ko from 'ko';
import { i18n } from 'Common/Translator';

const
	isIcs = a => /^(text\/calendar|application\/ics)$/i.test(a.mimeType || '') || /\.ics$/i.test(a.fileName || ''),
	lang = () => document.documentElement.lang || 'it',
	day = (iso, opts) => new Intl.DateTimeFormat(lang(), { timeZone: 'UTC', ...opts }).format(new Date(iso + 'T12:00:00Z')),
	longDay = iso => day(iso, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
	shortDay = iso => day(iso, { weekday: 'short', day: 'numeric', month: 'short' }),
	hours = (from, to) => from ? from + (to ? ' – ' + to : '') : '';

/** When, in words: "giovedì 3 dicembre 2026 · 15:00 – 16:00", over days "gio 3 dic 15:00 – sab 5 dic 10:00" */
export const inviteWhen = e => {
	if (e.start.date === e.end.date) {
		return longDay(e.start.date) + ' · ' + (e.allDay ? i18n('QUOKKA/INVITE_ALL_DAY') : hours(e.start.time, e.end.time));
	}
	return shortDay(e.start.date) + (e.start.time ? ' ' + e.start.time : '') + ' – '
		+ shortDay(e.end.date) + (e.end.time ? ' ' + e.end.time : '');
};

const busyText = b => (b.all_day ? (b.date !== b.end_date ? shortDay(b.date) + ' – ' + shortDay(b.end_date) : i18n('QUOKKA/INVITE_ALL_DAY'))
	: hours(b.time, b.end_time)) + '  ' + b.title;

/** The card for the email shown: null without an invitation. Follows the message (folder, uid). */
export const inviteCard = currentMessage => {
	const card = ko.observable(null);
	let shown = '';
	ko.computed(() => {
		const message = currentMessage(),
			key = message && message.attachments().some(isIcs) ? message.folder + '/' + message.uid : '';
		if (key === shown) return;
		shown = key;
		card(null);
		key && rl.pluginRemoteRequest((iError, oData) => {
			const r = oData?.Result;
			if (shown !== key || iError || !r?.event) return;
			const e = r.event,
				cancelled = 'CANCEL' === e.method || 'CANCELLED' === e.status,
				saved = ko.observable(r.saved),
				busy = ko.observable(false),
				status = ko.observable(''),
				answer = what => {
					if (busy()) return;
					busy(true);
					status(i18n('QUOKKA/INVITE_SENDING'));
					rl.pluginRemoteRequest((iError, oData) => {
						busy(false);
						const a = oData?.Result;
						if (iError || !a) return status(i18n('QUOKKA/INVITE_DOWN'));
						('decline' === what || 'remove' === what) ? saved(null) : a.saved && saved(a.saved);
						status(a.error
							? i18n('QUOKKA_EXTRAS/' + a.error) + (a.replied ? ' ' + i18n('QUOKKA/INVITE_REPLY_SENT') : '')
							: i18n('QUOKKA/INVITE_DONE_' + what.toUpperCase()));
					}, 'QuokkaInviteAnswer', { folder: message.folder, uid: message.uid, answer: what, lang: lang() }, 30000);
				};
			card({
				title: e.summary || i18n('QUOKKA/INVITE_EVENT'),
				when: inviteWhen(e),
				where: e.location,
				by: e.organizer ? (e.organizer.name || e.organizer.email) : '',
				recurring: e.recurring,
				cancelled, reply: 'REPLY' === e.method,
				canReply: !!r.canReply && !cancelled,
				canSave: !r.canReply && !cancelled && 'REPLY' !== e.method && r.kooka,
				kooka: !!r.kooka,
				conflicts: (r.conflicts || []).map(b => ({ text: busyText(b), url: b.url })),
				sameDay: (r.sameDay || []).map(b => ({ text: busyText(b), url: b.url })),
				free: r.kooka && !e.allDay && !(r.conflicts || []).length,
				saved, busy, status,
				texts: Object.fromEntries(['INVITE_TITLE', 'INVITE_BY', 'INVITE_CONFLICT', 'INVITE_FREE', 'INVITE_SAME_DAY',
					'INVITE_NO_KOOKA', 'INVITE_ACCEPT', 'INVITE_TENTATIVE', 'INVITE_DECLINE', 'INVITE_SAVE', 'INVITE_SAVED',
					'INVITE_OPEN', 'INVITE_REMOVE', 'INVITE_CANCELLED', 'INVITE_REPLY_INFO', 'INVITE_RECURRING']
					.map(k => [k, i18n('QUOKKA/' + k)])),
				accept: () => answer('accept'), tentative: () => answer('tentative'), decline: () => answer('decline'),
				save: () => answer('save'), remove: () => answer('remove')
			});
		}, 'QuokkaInvite', { folder: message.folder, uid: message.uid }, 30000);
	});
	return card;
};

// Quokka: the message list under a finger (phone, tablet).
//   - hold an email half a second: it is selected (a short vibration); from then on a tap selects or deselects
//     the others, instead of opening them; the selection mode ends when nothing is selected;
//   - swipe an email left: to the bin; right: to the archive. Past a third of the row it goes, otherwise it comes back.
// The drag of an email onto a folder (SnappyMail's long press) stays for the mouse.
import ko from 'ko';
import { i18n } from 'Common/Translator';
import { FolderType } from 'Common/EnumsUser';
import { MessagelistUserStore } from 'Stores/User/Messagelist';

const LONG_PRESS = 450,         // ms
	SLOP = 10,                  // px a finger may wander before it is a scroll or a swipe
	ARM = 0.33;                 // of the row's width

const selecting = () => 0 < MessagelistUserStore.listChecked().length;

/** The message and its thread, as moveMessagesToFolderType wants them */
const uidsOf = message => {
	const uids = new Set([message.uid]);
	1 < message.threadsLen() && message.threads().forEach(uids.add, uids);
	uids.folder = message.folder;
	return uids;
};

export const initTouchList = list => {
	let row = null, message = null, start = null, mode = '', dx = 0, timer = 0, under = null, eatUntil = 0;

	const reset = () => {
		clearTimeout(timer);
		row = message = start = null;
		mode = '';
		dx = 0;
	};

	const drawSwipe = () => {
		if (!under) {
			under = document.createElement('div');
			under.className = 'quokka-swipe-under';
			const archive = document.createElement('span'), del = document.createElement('span');
			archive.className = 'quokka-swipe-archive';
			archive.textContent = i18n('QUOKKA/SWIPE_ARCHIVE');
			del.className = 'quokka-swipe-delete';
			del.textContent = i18n('QUOKKA/SWIPE_DELETE');
			under.append(archive, del);
			row.parentNode.insertBefore(under, row);
		}
		under.style.top = row.offsetTop + 'px';
		under.style.height = row.offsetHeight + 'px';
		under.classList.toggle('to-delete', dx < 0);
		under.classList.toggle('to-archive', dx > 0);
		under.classList.toggle('armed', Math.abs(dx) > row.offsetWidth * ARM);
		row.classList.add('quokka-swiping');
		row.style.transform = 'translateX(' + dx + 'px)';
	};

	const endSwipe = () => {
		const el = row, msg = message, width = el.offsetWidth, go = Math.abs(dx) > width * ARM, toDelete = dx < 0,
			layer = under;
		under = null;
		el.style.transition = 'transform .18s ease-out';
		el.style.transform = go ? 'translateX(' + (toDelete ? -width : width) + 'px)' : '';
		setTimeout(() => {
			el.style.transition = '';
			el.classList.remove('quokka-swiping');
			layer?.remove();
			if (go && msg) {
				el.style.transform = '';
				rl.app.moveMessagesToFolderType(toDelete ? FolderType.Trash : FolderType.Archive, msg.folder, uidsOf(msg));
			}
		}, 190);
	};

	list.addEventListener('touchstart', e => {
		if (1 !== e.touches.length) return reset();
		const el = e.target.closest('.messageListItem');
		if (!el || e.target.closest('.flagParent')) return reset();
		el.removeAttribute('draggable');       // no drag-to-folder under a finger: it is the long press now
		row = el;
		message = ko.dataFor(el);
		start = { x: e.touches[0].clientX, y: e.touches[0].clientY };
		mode = '';
		dx = 0;
		clearTimeout(timer);
		timer = setTimeout(() => {
			if (!mode && message) {
				mode = 'press';
				message.checked(true);
				navigator.vibrate?.(15);
			}
		}, LONG_PRESS);
	}, { passive: true });

	list.addEventListener('touchmove', e => {
		if (!row || !start) return;
		const x = e.touches[0].clientX - start.x, y = e.touches[0].clientY - start.y;
		if (!mode) {
			if (Math.abs(x) > SLOP && Math.abs(x) > 1.5 * Math.abs(y) && !selecting()) {
				mode = 'swipe';
			} else if (Math.abs(x) > SLOP || Math.abs(y) > SLOP) {
				mode = 'scroll';
			}
			mode && clearTimeout(timer);
		}
		if ('swipe' === mode) {
			e.preventDefault();
			dx = x;
			drawSwipe();
		}
	}, { passive: false });

	const end = () => {
		clearTimeout(timer);
		// the click that may follow a long press must not open the email (some browsers send none: it expires)
		'press' === mode && (eatUntil = Date.now() + 600);
		'swipe' === mode && row && endSwipe();
		reset();
	};
	list.addEventListener('touchend', end);
	list.addEventListener('touchcancel', end);

	// no text-selection menu on a long press
	list.addEventListener('contextmenu', e => {
		e.target.closest('.messageListItem') && matchMedia('(pointer: coarse)').matches && e.preventDefault();
	});

	// In selection mode a tap selects instead of opening; the click after a long press does nothing
	list.addEventListener('click', e => {
		const el = e.target.closest('.messageListItem');
		if (!el) return;
		if (Date.now() < eatUntil) {
			eatUntil = 0;
			e.preventDefault();
			e.stopImmediatePropagation();
			return;
		}
		if (selecting() && matchMedia('(pointer: coarse)').matches && !e.target.closest('.flagParent')) {
			e.preventDefault();
			e.stopImmediatePropagation();
			const msg = ko.dataFor(el);
			msg?.checked(!msg.checked());
		}
	}, true);
};

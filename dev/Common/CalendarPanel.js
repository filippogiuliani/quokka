// Quokka: Kooka's calendar inside Quokka, in a panel over the mail; the column and the phone's bottom menu stay, and
// "Posta" goes back to the mail. Kooka is shown with ?embed=1 (no menu of its own) and must allow it: KOOKA_EMBED_FROM.
// Links to Kooka anywhere in Quokka (an invitation's "Apri in Kooka", what is on at that time) open here too.
import { SettingsGet, leftPanelDisabled } from 'Common/Globals';
import { i18n } from 'Common/Translator';
import { ThemeStore } from 'Stores/Theme';

let panel, frame;

const base = () => String(SettingsGet('QuokkaCalendarUrl') || '').split('#')[0];

const embedded = hash => {
	const url = new URL(base(), location.href);
	url.searchParams.set('embed', '1');
	return url.toString() + (hash || '#/calendar');
};

// on a computer it starts where the column ends; on a phone it leaves the bottom menu in view (styles.css)
const place = () => {
	if (!panel) return;
	const left = document.getElementById('rl-left');
	panel.style.left = !ThemeStore.isMobile() && left && !leftPanelDisabled() ? left.getBoundingClientRect().right + 'px' : '';
};

const build = () => {
	panel = document.createElement('section');
	panel.id = 'quokka-calendar';
	panel.hidden = true;
	panel.setAttribute('aria-label', i18n('QUOKKA/CALENDAR'));
	panel.innerHTML = '<div class="qc-bar"><button type="button" class="qc-back"><svg viewBox="0 0 24 24" aria-hidden="true">'
		+ '<path d="m15 18-6-6 6-6"></path></svg><span></span></button><b class="qc-title"></b>'
		+ '<a class="qc-out" target="_blank" rel="noopener"></a></div><iframe title=""></iframe>';
	frame = panel.querySelector('iframe');
	panel.querySelector('.qc-back span').textContent = i18n('QUOKKA/TAB_MAIL');
	panel.querySelector('.qc-title').textContent = frame.title = i18n('QUOKKA/CALENDAR');
	panel.querySelector('.qc-out').textContent = i18n('QUOKKA/CALENDAR_OPEN') + ' ↗';
	panel.querySelector('.qc-back').addEventListener('click', closeCalendar);
	document.body.append(panel);
	addEventListener('resize', place);
	leftPanelDisabled.subscribe(place);
};

export const openCalendar = hash => {
	if (!base()) return false;
	panel || build();
	const src = embedded(hash);
	if (frame.dataset.src !== src) {
		frame.src = frame.dataset.src = src;
	}
	panel.querySelector('.qc-out').href = base() + (hash || '#/calendar');
	place();
	panel.hidden = false;
	document.documentElement.classList.add('quokka-calendar-open');
	ThemeStore.isMobile() && leftPanelDisabled(true);
	return true;
};

export const closeCalendar = () => {
	if (panel && !panel.hidden) {
		panel.hidden = true;
		document.documentElement.classList.remove('quokka-calendar-open');
	}
};

// the calendar links (column, bottom menu) and any link to Kooka open the panel; the rest of Quokka closes it
document.addEventListener('click', event => {
	const a = event.target.closest?.('a');
	if (!a || !base()) return;
	if (a.matches('.quokka-calendar-link, .quokka-tabbar .tab-calendar')) {
		event.preventDefault();
		openCalendar('#/calendar');
	} else if (a.href && a.href.startsWith(base()) && !a.closest('#quokka-calendar')) {
		event.preventDefault();
		openCalendar('#' + (a.href.split('#')[1] || '/calendar'));
	} else if (a.closest('.quokka-tabbar, #rl-left .b-content') && !a.closest('.quokka-box-head')) {
		closeCalendar();
	}
}, true);
addEventListener('hashchange', closeCalendar);

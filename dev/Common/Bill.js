// Quokka: what a bill says, read from its text: the amount, the due date, the period. The patterns read Italian bills
// (the words and month names below are what Italian utilities write); the results are shown in the interface's language.

const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre',
	'ottobre', 'novembre', 'dicembre'];
const MONTH = MONTHS.join('|');

const pad = n => String(n).padStart(2, '0');

/** "84,30" / "1.234,56" / "84.30" → 84.3 */
const number = s => {
	s = s.replace(/\s/g, '');
	if (/,\d{1,2}$/.test(s)) s = s.replace(/\./g, '').replace(',', '.');
	else s = s.replace(/,/g, '');
	const n = parseFloat(s);
	return isFinite(n) ? n : null;
};

/** The amount to pay: the euros near "importo", "totale", "da pagare", else the first sum in euros. */
export const billAmount = text => {
	const money = '(\\d{1,3}(?:[.\\s]\\d{3})*(?:,\\d{1,2})?|\\d+(?:[.,]\\d{1,2})?)';
	const near = new RegExp('(?:importo|totale|da pagare|ammontare|addebit\\w*)[^\\d€]{0,40}(?:€|eur(?:o)?)?\\s*' + money + '\\s*(?:€|eur(?:o)?)?', 'i');
	const any = new RegExp('(?:€\\s*' + money + ')|(?:' + money + '\\s*(?:€|eur(?:o)?\\b))', 'i');
	let m = text.match(near);
	let n = m && number(m[1]);
	if (n === null || n === undefined) {
		m = text.match(any);
		n = m && number(m[1] || m[2]);
	}
	return n ? n : null;
};

/** A date as YYYY-MM-DD from "05/12/2026", "5-12-26", "5 dicembre 2026", "5 dicembre" (this year or the next). */
const parseDate = (s, today = new Date()) => {
	let m = s.match(/(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})/);
	if (m) {
		let y = +m[3];
		if (y < 100) y += 2000;
		const d = +m[1], mo = +m[2];
		return (d >= 1 && d <= 31 && mo >= 1 && mo <= 12) ? `${y}-${pad(mo)}-${pad(d)}` : null;
	}
	m = s.match(new RegExp('(\\d{1,2})\\s+(' + MONTH + ')(?:\\s+(\\d{4}))?', 'i'));
	if (m) {
		const mo = MONTHS.indexOf(m[2].toLowerCase()) + 1;
		let y = m[3] ? +m[3] : today.getFullYear();
		// "5 gennaio" read in December is next year's
		if (!m[3] && new Date(y, mo - 1, +m[1]) < new Date(today.getFullYear(), today.getMonth(), today.getDate() - 31)) y++;
		return `${y}-${pad(mo)}-${pad(+m[1])}`;
	}
	return null;
};

/** The due date: the date after "scadenza", "entro il", "da pagare entro"… */
export const billDue = (text, today) => {
	const m = text.match(new RegExp('(?:scadenza|scade(?:nte)?|entro (?:il|e non oltre il)?|pagare entro|data di pagamento)[^\\d]{0,30}'
		+ '((?:\\d{1,2}[/.-]\\d{1,2}[/.-]\\d{2,4})|(?:\\d{1,2}\\s+(?:' + MONTH + ')(?:\\s+\\d{4})?))', 'i'));
	return m ? parseDate(m[1], today) : null;
};

/** The period: "novembre 2026", "periodo ottobre-novembre 2026"… */
export const billPeriod = text => {
	const m = text.match(new RegExp('(?:periodo|relativa al|di|bolletta di|fattura di)\\s+(?:(?:' + MONTH + ')\\s*[-–/]\\s*)?((?:' + MONTH + ')\\s+\\d{4})', 'i'));
	return m ? m[1].charAt(0).toUpperCase() + m[1].slice(1).toLowerCase() : null;
};

/** {amount, due, period} for a bill's text (each null when not found) */
export const readBill = (text, today) => {
	text = (text || '').replace(/ /g, ' ');
	return { amount: billAmount(text), due: billDue(text, today), period: billPeriod(text) };
};

const lang = () => document.documentElement.lang || undefined;

/** 84.3 → "84,30 €" (as the interface's language writes euros) */
export const euros = n => n.toLocaleString(lang(), { style: 'currency', currency: 'EUR' });

/** "2026-12-05" → "5 December" / "5 dicembre" (with the year when it is not this year's) */
export const dayMonth = (iso, today = new Date()) => {
	const [y, mo, d] = iso.split('-').map(Number);
	return new Date(y, mo - 1, d).toLocaleDateString(lang(),
		y !== today.getFullYear() ? { day: 'numeric', month: 'long', year: 'numeric' } : { day: 'numeric', month: 'long' });
};

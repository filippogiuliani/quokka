import { i18n } from 'Common/Translator';

// Quokka: why an email may be a scam (phishing). Signs only, never a verdict: the page says "may be".
// The list of brands is the one Italian families meet most; add yours.
//   - the mail server checked the sender and it failed (DMARC; or SPF "fail" without a good DKIM);
//   - the name says a bank, Poste, a courier… but the address is from another domain;
//   - the name is itself an address, different from the real one ("info@banca.it" <x@altro.com>);
//   - a link shows the address of one of those companies but leads elsewhere, or to a bare IP address.

/** Who is often impersonated, and the domains they really write from */
const BRANDS = [
	['Poste Italiane|Poste|PostePay|BancoPosta', 'poste.it posteitaliane.it postepay.it'],
	['Intesa Sanpaolo|Intesa', 'intesasanpaolo.com intesasanpaolo.it'],
	['UniCredit', 'unicredit.it unicredit.eu unicreditgroup.eu'],
	['BPER', 'bper.it'],
	['Banco BPM', 'bancobpm.it'],
	['Fineco', 'finecobank.com fineco.it'],
	['Mediolanum', 'bancamediolanum.it mediolanum.it'],
	['BNL', 'bnl.it'],
	['Crédit Agricole|Credit Agricole', 'credit-agricole.it'],
	['ING', 'ing.it ing.com'],
	['N26', 'n26.com'],
	['Revolut', 'revolut.com'],
	['Nexi', 'nexi.it'],
	['Satispay', 'satispay.com'],
	['PayPal', 'paypal.com paypal.it'],
	['Amazon', 'amazon.it amazon.com amazon.de amazon.fr amazon.es amazon.co.uk primevideo.com'],
	['Apple|iCloud', 'apple.com icloud.com'],
	['Microsoft|Outlook|Office 365', 'microsoft.com outlook.com live.com office.com'],
	['Netflix', 'netflix.com'],
	['Agenzia delle Entrate|Agenzia Entrate', 'agenziaentrate.it agenziaentrate.gov.it'],
	['INPS', 'inps.it inps.gov.it'],
	['Aruba', 'aruba.it'],
	['Enel', 'enel.it enel.com'],
	['Telepass', 'telepass.com telepass.it'],
	['DHL', 'dhl.com dhl.it'],
	['BRT|Bartolini', 'brt.it'],
	['GLS', 'gls-italy.com gls-group.eu gls-group.com'],
	['SDA', 'sda.it'],
	['UPS', 'ups.com'],
	['FedEx', 'fedex.com'],
	['Vodafone', 'vodafone.it vodafone.com'],
	['TIM', 'tim.it telecomitalia.it'],
	['WindTre|Wind Tre', 'windtre.it'],
	['Iliad', 'iliad.it'],
	['Facebook|Meta', 'facebookmail.com facebook.com meta.com'],
	['Instagram', 'instagram.com mail.instagram.com'],
	['WhatsApp', 'whatsapp.com']
].map(([names, domains]) => ({
	label: names.split('|')[0],
	re: new RegExp('(^|[^\\p{L}\\p{N}])(' + names.replace(/ /g, '\\s+') + ')($|[^\\p{L}\\p{N}])', 'iu'),
	domains: domains.split(' ')
}));

const hostOf = address => (address || '').split('@').pop().toLowerCase().replace(/\.$/, '');

const within = (host, domain) => host === domain || host.endsWith('.' + domain);

/** "a.b.poste.it" → "poste.it"; "x.gov.it" style second levels keep one more part */
const baseDomain = host => {
	const parts = host.split('.');
	const n = /^(gov|co|com|org|net|edu|ac)$/.test(parts[parts.length - 2] || '') ? 3 : 2;
	return parts.slice(-n).join('.');
};

const brandOfHost = host => BRANDS.find(b => b.domains.some(d => within(host, d)));

const urlHost = href => {
	try {
		return new URL(href).hostname.toLowerCase();
	} catch (e) {
		return '';
	}
};

/** The reasons (sentences in the interface's language) why this email looks like a scam; [] when nothing is odd */
export const suspiciousReasons = message => {
	const reasons = [],
		from = message.from?.[0],
		email = (from?.email || '').toLowerCase(),
		host = hostOf(email),
		name = (from?.name || '').trim();
	if (!host) {
		return reasons;
	}

	// 1. what the mail server checked when the email arrived
	const dmarc = message.dmarc?.[0]?.[0], dkim = message.dkim?.[0]?.[0], spf = message.spf?.[0]?.[0];
	if ('fail' === dmarc) {
		reasons.push(i18n('QUOKKA/SCAM_DMARC', { HOST: host }));
	} else if ('fail' === spf && 'pass' !== dkim) {
		reasons.push(i18n('QUOKKA/SCAM_SPF', { HOST: host }));
	}

	// 2. a name that is an address, not the real one
	const nameAddress = name.match(/[\w.+-]+@([\w-]+\.)+[a-z]{2,}/i)?.[0]?.toLowerCase();
	if (nameAddress && nameAddress !== email && baseDomain(hostOf(nameAddress)) !== baseDomain(host)) {
		reasons.push(i18n('QUOKKA/SCAM_NAME_ADDRESS', { NAME: nameAddress, EMAIL: email }));
	}

	// 3. a known name, a domain that isn't theirs
	const brand = BRANDS.find(b => b.re.test(name));
	if (brand && !brand.domains.some(d => within(host, d))) {
		reasons.push(i18n('QUOKKA/SCAM_BRAND', { BRAND: brand.label, HOST: host }).replace('%BRAND%', brand.label));
	}

	// 4. links that show one address and lead to another
	const html = message.html?.() || '';
	if (html) {
		const doc = new DOMParser().parseFromString(html, 'text/html');
		const seen = new Set();
		for (const a of doc.querySelectorAll('a[href]')) {
			const href = a.getAttribute('href') || '';
			if (!/^https?:/i.test(href)) continue;
			const to = urlHost(href);
			if (!to) continue;
			if (/^\d{1,3}(\.\d{1,3}){3}$/.test(to) && !seen.has(to)) {
				seen.add(to);
				reasons.push(i18n('QUOKKA/SCAM_IP_LINK', { HOST: to }));
				continue;
			}
			const shown = (a.textContent || '').trim().match(/^(https?:\/\/)?((?:[a-z0-9-]+\.)+[a-z]{2,})(?=$|[\/?#:\s])/i)?.[2]?.toLowerCase();
			const brandShown = shown && brandOfHost(shown);
			if (brandShown && baseDomain(shown) !== baseDomain(to) && !brandShown.domains.some(d => within(to, d)) && !seen.has(shown)) {
				seen.add(shown);
				reasons.push(i18n('QUOKKA/SCAM_LINK', { SHOWN: shown, TO: to }));
			}
		}
	}
	return reasons;
};

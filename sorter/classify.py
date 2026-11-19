"""Which category an email belongs to: rules that know Italian family mail, plus what the family taught by correcting.

Three voices, strongest first:
  1. the sender: when someone moved emails from an address (or a whole domain) to a category, the next ones go there;
  2. learned words: a small naive Bayes over sender, subject and the start of the text, trained on the corrections;
  3. the rules below: words and headers that mean "bill", "school", "bank", "parcel", "account", "social", "advertising".
Nothing is sent anywhere: it all runs here, on what the mailbox already holds."""
import math
import re
import unicodedata
from dataclasses import dataclass, field

# IMAP keywords (lower case, no spaces): what Quokka shows as the email's category (named in its translations)
CATEGORIES = {
    'bills': 'Bills',
    'school': 'School',
    'bank': 'Bank',
    'shipping': 'Shipping',
    'account': 'Accounts',
    'social': 'Social',
    'promotions': 'Promotions',
}
NONE = ''                       # "no category": also something people teach

# word → weight, per category; matched on whole words of the subject (x2) and the start of the text.
# The words are Italian: the mail these families get. For another language, add its words to each table.
RULES = {
    'bills': {
        'bolletta': 3, 'bollette': 3, 'fattura': 3, 'fatture': 2, 'fornitura': 2, 'importo': 1, 'scadenza': 1,
        'pagamento': 1, 'addebito': 1, 'luce': 1, 'gas': 1, 'energia': 1, 'acqua': 1, 'canone': 2, 'tari': 2,
        'condominio': 2, 'rata': 1, 'utenza': 2, 'pod': 1, 'pdr': 2, 'kwh': 2, 'smc': 2, 'telefonia': 1,
    },
    'school': {
        'scuola': 3, 'scolastico': 2, 'scolastica': 2, 'classe': 1, 'docente': 2, 'docenti': 2, 'maestra': 2,
        'maestre': 2, 'alunno': 2, 'alunni': 2, 'circolare': 2, 'registro': 1, 'elettronico': 1, 'colloqui': 2,
        'uscita': 1, 'didattica': 2, 'gita': 2, 'mensa': 1, 'nido': 2, 'materna': 2, 'primaria': 2, 'istituto': 1,
        'comprensivo': 3, 'dirigente': 1, 'genitori': 1, 'famiglie': 1, 'iscrizione': 1, 'iscrizioni': 1,
    },
    'bank': {
        'banca': 2, 'bancario': 2, 'estratto': 2, 'conto': 1, 'bonifico': 3, 'bonifici': 2, 'carta': 1, 'iban': 2,
        'movimento': 1, 'movimenti': 1, 'saldo': 1, 'mutuo': 2, 'prestito': 1, 'investimenti': 1, 'titoli': 1,
        'banking': 2, 'rendiconto': 2, 'f24': 2, 'addebitato': 1, 'accredito': 2, 'prelievo': 2,
    },
    'shipping': {
        'spedizione': 3, 'spedito': 2, 'spedita': 2, 'pacco': 3, 'pacchi': 2, 'consegna': 2, 'consegnato': 2,
        'corriere': 3, 'tracking': 3, 'ordine': 1, 'ritiro': 1, 'locker': 2, 'brt': 3, 'gls': 3, 'sda': 3,
        'dhl': 3, 'ups': 3, 'fedex': 3, 'tnt': 2, 'poste': 1, 'raccomandata': 1, 'arrivo': 1,
    },
    'account': {     # the life of an account: sign-up, codes, passwords, sign-ins
        'verifica': 2, 'verification': 2, 'verify': 2, 'otp': 3, 'accesso': 2, 'login': 2, 'password': 3,
        'account': 2, 'registrazione': 3, 'registrato': 2, 'registrata': 2, 'benvenuto': 1, 'benvenuta': 1,
        'conferma': 2, 'confermare': 2, 'attivazione': 3, 'attiva': 1, 'reimposta': 3, 'reimpostare': 3,
        'recupero': 2, 'recupera': 2, 'dimenticato': 2, 'dispositivo': 1, 'autenticazione': 3, 'sicurezza': 2,
        'credenziali': 3, 'username': 2, 'profilo': 1, 'pin': 1,
    },
    'social': {
        'facebook': 3, 'instagram': 3, 'linkedin': 3, 'tiktok': 3, 'pinterest': 3, 'reddit': 3, 'twitter': 3,
        'threads': 2, 'snapchat': 3, 'whatsapp': 2, 'telegram': 2, 'strava': 3, 'follower': 3, 'followers': 3,
        'taggato': 3, 'tag': 1, 'commentato': 2, 'commento': 1, 'amicizia': 2, 'post': 1, 'storia': 1, 'like': 1,
        'menzionato': 3, 'collegamento': 1, 'invito': 1,
    },
    'promotions': {
        'offerta': 2, 'offerte': 2, 'sconto': 2, 'sconti': 2, 'promo': 2, 'promozione': 2, 'saldi': 2, 'coupon': 2,
        'codice': 1, 'black': 1, 'friday': 2, 'newsletter': 3, 'imperdibile': 3, 'gratis': 1, 'regalo': 1,
        'novita': 1, 'scopri': 1, 'acquista': 2, 'approfitta': 3, 'esclusiva': 2, 'disiscriviti': 2,
    },
}
MIN_RULE_SCORE = 3              # below this the rules stay silent
# Who sends social notifications: the sender alone says "social" (they come with List-Unsubscribe too)
SOCIAL_DOMAINS = ('facebookmail.com', 'facebook.com', 'instagram.com', 'mail.instagram.com', 'linkedin.com',
                  'x.com', 'twitter.com', 'tiktok.com', 'pinterest.com', 'reddit.com', 'redditmail.com',
                  'threads.net', 'snapchat.com', 'whatsapp.com', 'telegram.org', 'strava.com', 'nextdoor.com')
MIN_LEARNED_EXAMPLES = 6        # the learned words speak only once there is something to learn from
LEARNED_MARGIN = 2.0            # …and only when clearly sure (log-odds between the best two)


def norm(text):
    """Lower case, no accents: «Novità» → «novita»."""
    text = unicodedata.normalize('NFKD', (text or '').lower())
    return ''.join(c for c in text if not unicodedata.combining(c))


def words(text):
    return re.findall(r'[a-z0-9]+', norm(text))


def address(sender):
    m = re.search(r'[\w.+-]+@[\w.-]+', sender or '')
    return m.group(0).lower() if m else ''


def domain(addr):
    """mario@news.shop.example.it → shop.example.it (the last three parts at most: subdomains of one sender)."""
    host = addr.rpartition('@')[2]
    parts = host.split('.')
    return '.'.join(parts[-3:]) if len(parts) > 3 else host


@dataclass
class Email:
    sender: str = ''
    subject: str = ''
    text: str = ''                       # the start of the body, as plain text
    headers: dict = field(default_factory=dict)   # lower-case names: list-unsubscribe, precedence, …

    def features(self):
        """What the learned part looks at: who sent it, and the words of subject and text."""
        addr = address(self.sender)
        out = [f'from:{addr}', f'dom:{domain(addr)}'] if addr else []
        out += [f's:{w}' for w in words(self.subject)]
        out += [f't:{w}' for w in words(self.text)[:200]]
        return out


def by_rules(mail):
    """(category, score) from the rules, or (NONE, 0)."""
    subject, text = set(words(mail.subject)), set(words(mail.text[:3000]))
    sender = words(mail.sender)
    scores = {}
    for cat, table in RULES.items():
        s = 0
        for w, weight in table.items():
            if w in subject:
                s += 2 * weight
            elif w in text or w in sender:
                s += weight
        scores[cat] = s
    # Mailing lists and bulk senders are advertising unless something else is clearly stronger
    if mail.headers.get('list-unsubscribe') or norm(mail.headers.get('precedence', '')) in ('bulk', 'list'):
        scores['promotions'] += 4
    addr = address(mail.sender)
    host = addr.rpartition('@')[2]
    if any(host == d or host.endswith('.' + d) for d in SOCIAL_DOMAINS):
        scores['social'] += 10
    # "noreply" and "security" senders often send account notices (a code, "your password…")
    if re.match(r'(no-?reply|notifiche|notifications?|alert|account|security|sicurezza)[\w.+-]*@', addr):
        scores['account'] += 1
    cat = max(scores, key=scores.get)
    best = scores[cat]
    if best < MIN_RULE_SCORE:
        return NONE, 0
    # a bill from a list (most utilities send them with List-Unsubscribe) stays a bill
    if cat == 'promotions' and scores['bills'] >= MIN_RULE_SCORE + 2:
        cat, best = 'bills', scores['bills']
    return cat, best


class Learned:
    """Naive Bayes over the features of corrected emails. counts: {category: {feature: n}}, docs: {category: n}."""

    def __init__(self, counts=None, docs=None):
        self.counts = counts or {}
        self.docs = docs or {}

    def add(self, cat, feats):
        self.docs[cat] = self.docs.get(cat, 0) + 1
        c = self.counts.setdefault(cat, {})
        for f in set(feats):
            c[f] = c.get(f, 0) + 1

    def guess(self, feats):
        """(category, margin) or (None, 0) when there is not enough to say."""
        total = sum(self.docs.values())
        if total < MIN_LEARNED_EXAMPLES or len(self.docs) < 2:
            return None, 0
        vocab = len({f for c in self.counts.values() for f in c}) or 1
        scores = {}
        for cat, n in self.docs.items():
            c = self.counts.get(cat, {})
            s = math.log(n / total)
            for f in set(feats):
                s += math.log((c.get(f, 0) + 1) / (n + 2))
            scores[cat] = s
        ranked = sorted(scores, key=scores.get, reverse=True)
        return ranked[0], scores[ranked[0]] - scores[ranked[1]]


def classify(mail, senders, learned):
    """(category, why). senders: {address or domain: category} from the corrections."""
    addr = address(mail.sender)
    if addr in senders:
        return senders[addr], 'sender'
    dom = domain(addr)
    if dom and dom in senders:
        return senders[dom], 'domain'
    cat, margin = learned.guess(mail.features())
    if cat is not None and margin >= LEARNED_MARGIN:
        return cat, 'learned'
    cat, _ = by_rules(mail)
    return cat, ('rules' if cat else '')

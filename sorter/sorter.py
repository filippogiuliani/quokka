"""Sorter: puts a category keyword (bills, school, bank, shipping, account, social, promotions) on the new emails of the
family's mailboxes, and learns from what people change in Quokka.

Every minute, for each mailbox:
  - the new emails of INBOX get a category (classify.py) as an IMAP keyword: Quokka shows it, and so does any client
    that shows keywords; the email itself is never moved or changed;
  - the emails it handled in the last 30 days are looked at again: when someone put another category on one (or took
    it off), that is a correction: the sender is remembered and the words are learned.

Settings
  The mailboxes: Quokka passes on every mailbox someone signs in to (main and added accounts), its password sealed with
  QUOKKA_SECRET: nothing to write by hand. Optionally, for mailboxes nobody opens in Quokka:
  /config/accounts.json   [{"user": "mario@example.it", "password": "enc:v1:…"}, …]  (read-only, one entry per
                          mailbox; "host", "port", "security" per entry override the defaults below). The passwords
                          are encrypted with QUOKKA_SECRET (secret.py): `… encrypt` makes an entry. Plain passwords
                          are refused, unless ALLOW_PLAIN_PASSWORDS=1 (test servers only)
  QUOKKA_SECRET            the key of those passwords (the same as Quokka's)
  IMAP_HOST / IMAP_PORT / IMAP_SECURITY   the mail provider's IMAP server (required) / 993 / ssl  (ssl | starttls | none)
  FIRST_DAYS              at the first start, sort the emails of the last N days too (default 30)
  INTERVAL                seconds between rounds (default 30)
  DATA                    where its memory lives (default /data)
  PUSH_PORT               the notifications service for Quokka (default 8025, see push.py)
  INDEX_DAYS              how far back "All mailboxes" in Quokka goes (default 60, see inbox.py)

It also sends the notifications of new emails to the devices that asked for them in Quokka (push.py), and keeps the
index of every INBOX that Quokka shows as "All mailboxes" (inbox.py)."""
import datetime
import email
import email.policy
import email.utils
import html
import imaplib
import json
import logging
import os
import re
import sqlite3
import ssl
import time

import classify
import inbox
import push
import secret

log = logging.getLogger('sorter')
DATA = os.environ.get('DATA', '/data')
ACCOUNTS = os.environ.get('ACCOUNTS', '/config/accounts.json')
INTERVAL = int(os.environ.get('INTERVAL', '30'))
FIRST_DAYS = int(os.environ.get('FIRST_DAYS', '30'))
WATCH_DAYS = 30                 # corrections are looked for on the emails of the last 30 days
TEXT_BYTES = 20000              # enough of the body to understand it
DOMAIN_AFTER = 2                # two addresses of one domain taught the same: the whole domain goes there

SCHEMA = '''
CREATE TABLE IF NOT EXISTS mailbox (account TEXT PRIMARY KEY, uidvalidity INTEGER, last_uid INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS handled (
    account TEXT NOT NULL, uidvalidity INTEGER NOT NULL, uid INTEGER NOT NULL,
    sender TEXT NOT NULL, category TEXT NOT NULL, why TEXT NOT NULL, features TEXT NOT NULL,
    at TEXT NOT NULL DEFAULT (datetime('now')), settled INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (account, uidvalidity, uid));
CREATE TABLE IF NOT EXISTS example (account TEXT NOT NULL, category TEXT NOT NULL, features TEXT NOT NULL,
    at TEXT NOT NULL DEFAULT (datetime('now')));
CREATE TABLE IF NOT EXISTS sender (account TEXT NOT NULL, key TEXT NOT NULL, category TEXT NOT NULL,
    at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (account, key));
-- the mailboxes Quokka passed on when someone signed in (password sealed like in accounts.json); after
-- FAILURES_OFF refused sign-ins in a row one is left alone until Quokka passes it on again (a new password)
CREATE TABLE IF NOT EXISTS checked (account TEXT PRIMARY KEY, at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS from_quokka (account TEXT PRIMARY KEY, password TEXT NOT NULL,
    at TEXT NOT NULL DEFAULT (datetime('now')), failures INTEGER NOT NULL DEFAULT 0);
'''
FAILURES_OFF = 5


def db(path=None):
    conn = sqlite3.connect(path or os.path.join(DATA, 'sorter.db'))
    conn.row_factory = sqlite3.Row
    conn.executescript(SCHEMA)
    push.init(conn)
    inbox.init(conn)
    return conn


# ---------- reading an email ----------

def _text(msg):
    """The plain text of a (possibly cut short) message: text/plain, else the HTML without tags."""
    plain = html_part = None
    for part in msg.walk() if msg.is_multipart() else [msg]:
        ctype = part.get_content_type()
        if part.get_content_disposition() == 'attachment' or ctype not in ('text/plain', 'text/html'):
            continue
        try:
            body = part.get_content()
        except Exception:                         # cut in the middle, odd charset: what can be read
            raw = part.get_payload(decode=True) or b''
            body = raw.decode(part.get_content_charset() or 'utf-8', 'replace')
        if ctype == 'text/plain' and plain is None:
            plain = body
        elif ctype == 'text/html' and html_part is None:
            html_part = body
    if plain:
        return plain
    if html_part:
        no_tags = re.sub(r'<(script|style)\b.*?</\1>|<[^>]+>', ' ', html_part, flags=re.S | re.I)
        return re.sub(r'\s+', ' ', html.unescape(no_tags))
    return ''


def parse(raw):
    msg = email.message_from_bytes(raw, policy=email.policy.default)
    headers = {k.lower(): str(v) for k, v in msg.items() if k.lower() in ('list-unsubscribe', 'precedence', 'list-id')}
    return classify.Email(sender=str(msg.get('From', '')), subject=str(msg.get('Subject', '')),
                          text=_text(msg)[:5000], headers=headers)


# ---------- one mailbox ----------

class Mailbox:
    """The IMAP side, kept small so tests can stand in for it."""

    def __init__(self, conn):
        self.imap = conn

    def select(self):
        typ, data = self.imap.select('INBOX')
        if typ != 'OK':
            raise RuntimeError(f'INBOX: {data}')
        uidvalidity = int(self.imap.response('UIDVALIDITY')[1][0])
        perm = (self.imap.response('PERMANENTFLAGS')[1] or [b''])[0] or b''
        return uidvalidity, b'\\*' in perm

    def uids_after(self, last_uid):
        typ, data = self.imap.uid('SEARCH', None, f'UID {last_uid + 1}:*')
        return [u for u in map(int, (data[0] or b'').split()) if u > last_uid]

    def uids_since(self, days):
        since = (datetime.date.today() - datetime.timedelta(days=days)).strftime('%d-%b-%Y')
        typ, data = self.imap.uid('SEARCH', None, f'SINCE {since}')
        return list(map(int, (data[0] or b'').split()))

    def fetch(self, uid):
        """(raw bytes of headers + start of text, set of keywords) or (None, None) if it is gone."""
        typ, data = self.imap.uid('FETCH', str(uid), f'(FLAGS BODY.PEEK[HEADER] BODY.PEEK[TEXT]<0.{TEXT_BYTES}>)')
        parts = [d for d in data if isinstance(d, tuple)]
        rest = b' '.join(d for d in data if isinstance(d, bytes))     # FLAGS may come after the last literal
        if typ != 'OK' or not parts:
            return None, None
        # headers first, whatever order the server answers in (some send the text first: then sender and subject
        # were read as empty, and nothing was learned by sender)
        parts.sort(key=lambda p: 0 if b'HEADER' in p[0].upper() else 1)
        raw = b''.join(p[1] for p in parts)
        return raw, _flags(b' '.join(p[0] for p in parts) + b' ' + rest)

    def flags(self, uids):
        """{uid: set of keywords} for these emails (the ones gone are missing)."""
        out = {}
        for i in range(0, len(uids), 200):
            chunk = ','.join(map(str, uids[i:i + 200]))
            typ, data = self.imap.uid('FETCH', chunk, '(UID FLAGS)')
            for line in data or []:
                line = line[0] if isinstance(line, tuple) else line
                m = re.search(rb'UID (\d+)', line or b'')
                if m:
                    out[int(m.group(1))] = _flags(line)
        return out

    def tag(self, uid, keyword):
        self.imap.uid('STORE', str(uid), '+FLAGS', f'({keyword})')


def _flags(line):
    m = re.search(rb'FLAGS \(([^)]*)\)', line or b'')
    return {f.decode('utf-8', 'replace').lower() for f in (m.group(1).split() if m else [])}


def _categories(flags):
    return {f for f in flags if f in classify.CATEGORIES}


class Brain:
    """What one mailbox has been taught: remembered senders and learned words (rebuilt from the examples)."""

    def __init__(self, conn, account):
        self.conn, self.account = conn, account
        self.senders = {r['key']: r['category'] for r in conn.execute('SELECT key, category FROM sender WHERE account = ?', (account,))}
        self.learned = classify.Learned()
        for r in conn.execute('SELECT category, features FROM example WHERE account = ?', (account,)):
            self.learned.add(r['category'], json.loads(r['features']))

    def teach(self, sender, category, feats):
        addr = classify.address(sender)
        self.conn.execute('INSERT INTO example (account, category, features) VALUES (?,?,?)', (self.account, category, json.dumps(feats)))
        self.learned.add(category, feats)
        if not addr:
            return
        self._remember(addr, category)
        dom = classify.domain(addr)
        same = sum(1 for k, c in self.senders.items() if '@' in k and classify.domain(k) == dom and c == category)
        if same >= DOMAIN_AFTER:
            self._remember(dom, category)

    def _remember(self, key, category):
        self.senders[key] = category
        self.conn.execute('INSERT INTO sender (account, key, category) VALUES (?,?,?) '
                          'ON CONFLICT (account, key) DO UPDATE SET category = excluded.category, at = datetime(\'now\')',
                          (self.account, key, category))


def sort_round(conn, account, box, first_days=FIRST_DAYS):
    """One round on one mailbox: corrections first (they change what comes next), then the new emails.
    Returns (sorted, corrections)."""
    brain = Brain(conn, account)
    uidvalidity, keywords_ok = box.select()
    if not keywords_ok:
        # no categories then, but the new emails are still seen (for the notifications)
        log.warning('%s: the server does not keep custom keywords (no \\* in PERMANENTFLAGS): nothing can be tagged', account)
    row = conn.execute('SELECT uidvalidity, last_uid FROM mailbox WHERE account = ?', (account,)).fetchone()
    if row and row['uidvalidity'] != uidvalidity:      # the mailbox was rebuilt: its uids mean other emails now
        conn.execute('DELETE FROM handled WHERE account = ?', (account,))
        row = None

    # 1. corrections: what people changed on the emails handled lately
    corrections = 0
    watched = conn.execute(f"""SELECT uid, sender, category, features FROM handled WHERE account = ? AND uidvalidity = ?
                               AND settled = 0 AND at >= datetime('now', '-{WATCH_DAYS} days')""", (account, uidvalidity)).fetchall()
    watched = watched if keywords_ok else []
    if watched:
        now = box.flags([r['uid'] for r in watched])
        for r in watched:
            if r['uid'] not in now:
                continue                                  # moved or deleted: nothing to learn from that
            cats = _categories(now[r['uid']])
            ours = {r['category']} if r['category'] else set()
            if cats == ours:
                continue
            new = sorted(cats - ours)[0] if cats - ours else classify.NONE
            brain.teach(r['sender'], new, json.loads(r['features']))
            conn.execute('UPDATE handled SET settled = 1 WHERE account = ? AND uidvalidity = ? AND uid = ?', (account, uidvalidity, r['uid']))
            corrections += 1
            log.info('%s: uid %s corrected %s → %s', account, r['uid'], r['category'] or '—', new or '—')

    # 2. the new emails (at the first start: those of the last days, then on from the newest there is)
    if row:
        uids, last = box.uids_after(row['last_uid']), row['last_uid']
    else:
        uids = box.uids_since(first_days) if first_days else []
        last = max(box.uids_after(0), default=0)
    done = 0
    for uid in sorted(uids):
        raw, flags = box.fetch(uid)
        last = max(last, uid)
        if raw is None:
            continue
        mail = parse(raw)
        if row and '\\seen' not in flags:                 # new and unread (not at the first start): a notification
            name, addr = email.utils.parseaddr(mail.sender)
            head = raw.split(b'\r\n\r\n', 1)[0].lower()
            signed = b'dkim=pass' in head                 # the receiving server checked the sender's domain signature
            push.queue(conn, account, uid, name or addr or mail.sender, mail.subject, addr, signed)
        if not keywords_ok:
            cat, why = classify.NONE, 'no keywords'
        elif _categories(flags):                             # someone already chose: learn nothing, touch nothing
            cat, why = sorted(_categories(flags))[0], 'already'
        else:
            cat, why = classify.classify(mail, brain.senders, brain.learned)
            if cat:
                box.tag(uid, cat)
        conn.execute('INSERT OR REPLACE INTO handled (account, uidvalidity, uid, sender, category, why, features) VALUES (?,?,?,?,?,?,?)',
                     (account, uidvalidity, uid, classify.address(mail.sender), cat, why, json.dumps(mail.features())))
        inbox.add(conn, account, uid, raw, mail, set(flags or ()) | ({cat} if cat else set()), classify.CATEGORIES)
        done += 1
    conn.execute('INSERT INTO mailbox (account, uidvalidity, last_uid) VALUES (?,?,?) ON CONFLICT (account) '
                 'DO UPDATE SET uidvalidity = excluded.uidvalidity, last_uid = excluded.last_uid', (account, uidvalidity, last))
    # 3. the index of INBOX for Quokka's "All mailboxes" (inbox.py): a failure there never undoes the sorting
    try:
        inbox.sync(conn, account, uidvalidity, box, parse, classify.CATEGORIES)
    except Exception as e:
        log.warning('%s: index of INBOX: %s: %s', account, type(e).__name__, e)
    # what was handled long ago is not watched any more: keep the table small
    conn.execute(f"DELETE FROM handled WHERE account = ? AND at < datetime('now', '-{WATCH_DAYS + 5} days')", (account,))
    conn.commit()
    return done, corrections


# ---------- the service ----------

def connect(acc):
    host = acc.get('host') or os.environ.get('IMAP_HOST')
    if not host:
        raise ValueError('IMAP_HOST is not set (the mail provider\'s IMAP server)')
    port = int(acc.get('port') or os.environ.get('IMAP_PORT', '993'))
    security = acc.get('security') or os.environ.get('IMAP_SECURITY') or ('ssl' if port == 993 else 'starttls')
    if security == 'ssl':
        imap = imaplib.IMAP4_SSL(host, port, ssl_context=ssl.create_default_context(), timeout=60)
    else:
        imap = imaplib.IMAP4(host, port, timeout=60)
        if security == 'starttls':
            imap.starttls(ssl_context=ssl.create_default_context())
    imap.login(acc['user'], password_of(acc))
    return imap


def password_of(acc):
    value = acc['password']
    if secret.is_sealed(value):
        return secret.open_(value, acc['user'])
    if os.environ.get('ALLOW_PLAIN_PASSWORDS') == '1':
        return value
    raise ValueError('plain password in accounts.json: encrypt it (sorter encrypt) or, on a test server only, '
                     'set ALLOW_PLAIN_PASSWORDS=1')


def encrypt_entry():
    """Asks for an address and its password, prints the line for accounts.json."""
    import getpass
    user = input('Mailbox address: ').strip()
    password = getpass.getpass('Password (not shown): ')
    print(json.dumps({'user': user, 'password': secret.seal(password, user)}))


def accounts(path=None):
    """The mailboxes to read: those Quokka passed on (every mailbox someone signed in to, see push.py /register) and
    those written in accounts.json (optional, for mailboxes nobody opens in Quokka). The file wins for the same one."""
    out = []
    if os.path.isfile(ACCOUNTS) and os.path.getsize(ACCOUNTS):        # optional (/dev/null when not given)
        with open(ACCOUNTS, encoding='utf-8') as f:
            out = [a for a in json.load(f) if a.get('user') and a.get('password')]
    seen = {a['user'].lower() for a in out}
    try:
        with sqlite3.connect(path or os.path.join(DATA, 'sorter.db'), timeout=15) as conn:
            conn.executescript(SCHEMA)
            for user, password in conn.execute('SELECT account, password FROM from_quokka WHERE failures < ?', (FAILURES_OFF,)):
                if user.lower() not in seen:
                    out.append({'user': user, 'password': password, 'from_quokka': True})
    except sqlite3.Error as e:
        log.warning('mailboxes from Quokka not read: %s', e)
    return out


def _checked(conn, account):
    """When the mailbox was last read (shown in Quokka, Settings → Notifications: proof the sorter reads it)."""
    conn.execute("INSERT INTO checked (account, at) VALUES (?, datetime('now')) ON CONFLICT (account) DO UPDATE SET at = excluded.at",
                 (account,))
    conn.commit()


def _signed_in(conn, acc, ok):
    """Count the refused sign-ins of a mailbox Quokka passed on (a password changed since)."""
    if acc.get('from_quokka'):
        conn.execute('UPDATE from_quokka SET failures = ' + ('0' if ok else 'failures + 1') + ' WHERE account = ?', (acc['user'],))
        conn.commit()


def main():
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    os.makedirs(DATA, exist_ok=True)
    conn = db()
    log.info('sorter: categories %s, every %ss', ', '.join(classify.CATEGORIES), INTERVAL)
    secret_key = os.environ.get('QUOKKA_SECRET', '')
    if len(secret_key) >= 32:
        push.serve(push.Service(os.path.join(DATA, 'sorter.db'), DATA, accounts, secret_key, connect=connect),
                   int(os.environ.get('PUSH_PORT', '8025')))
    else:
        log.warning('notifications off: QUOKKA_SECRET is missing or shorter than 32 characters')
    while True:
        for acc in accounts():
            try:
                try:
                    imap = connect(acc)
                except imaplib.IMAP4.error:
                    _signed_in(conn, acc, False)
                    raise
                _signed_in(conn, acc, True)
                _checked(conn, acc['user'])
                try:
                    done, fixed = sort_round(conn, acc['user'], Mailbox(imap))
                    if done or fixed:
                        log.info('%s: %d sorted, %d corrections learned', acc['user'], done, fixed)
                    # Sent, Drafts, Trash, Spam, Archive for "All mailboxes" (inbox.py): only read
                    try:
                        inbox.sync_folders(conn, acc['user'], imap, Mailbox(imap), parse, classify.CATEGORIES)
                        conn.commit()
                    except Exception as e:
                        conn.rollback()
                        log.warning('%s: index of the system folders: %s: %s', acc['user'], type(e).__name__, e)
                finally:
                    try:
                        imap.logout()
                    except Exception:
                        pass
            except Exception as e:                         # one mailbox down never stops the others
                conn.rollback()
                log.warning('%s: %s: %s', acc['user'], type(e).__name__, e)
        try:
            n = push.flush(conn, DATA)
            if n:
                log.info('notifications sent: %d', n)
        except Exception as e:
            conn.rollback()
            log.warning('notifications: %s: %s', type(e).__name__, e)
        time.sleep(INTERVAL)


if __name__ == '__main__':
    import sys
    encrypt_entry() if sys.argv[1:] == ['encrypt'] else main()

"""Notifications of new emails on people's devices, also with Quokka closed (Web Push).

The sorter already looks at every mailbox each minute: the new unread emails of INBOX are kept here and sent as one
notification per email (like Gmail), sent at most every N minutes as chosen for that mailbox in Quokka (0 = as soon as they arrive).
Quokka talks to it over the internal network (the stack's "quokka" network), signing each call with QUOKKA_SECRET:

  GET  /key                                   the VAPID public key, for PushManager.subscribe
  POST /register    {account, password}       a mailbox someone signed in to in Quokka: read from now on
  POST /status      {account, endpoint?}      {watched, every, subscribed}
  POST /subscribe   {account, subscription, url, lang}
  POST /unsubscribe {endpoint, account?}     that mailbox on that device (no account: every mailbox)
  POST /every       {account, every}          minutes, 0 (as soon as an email arrives) to 1440
  POST /test        {account, endpoint}       a notification to that device now
  POST /inbox, /act                           "All mailboxes": see inbox.py

Settings: PUSH_PORT (8025). The VAPID key is made once and kept in DATA/vapid_private.pem."""
import base64
import hashlib
import hmac
import json
import logging
import os
import sqlite3
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

log = logging.getLogger('sorter.push')
MAX_EVERY = 1440                # minutes: any number from 0 (as soon as one arrives) to a day
DEFAULT_EVERY = 0               # as soon as an email arrives (Quokka shows no interval: one notification per email, at once)
MAX_SKEW = 300                  # a signed call older than this is refused
SCHEMA = '''
CREATE TABLE IF NOT EXISTS push_sub (endpoint TEXT NOT NULL, account TEXT NOT NULL, keys TEXT NOT NULL, url TEXT NOT NULL,
    lang TEXT NOT NULL DEFAULT 'it', at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (endpoint, account));
CREATE TABLE IF NOT EXISTS push_setting (account TEXT PRIMARY KEY, every INTEGER NOT NULL DEFAULT 0, last_sent REAL NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS push_pending (account TEXT NOT NULL, uid INTEGER NOT NULL, sender TEXT NOT NULL, subject TEXT NOT NULL,
    at REAL NOT NULL, PRIMARY KEY (account, uid));
'''
TEXTS = {
    'it': {'more': 'Altre {n} nuove email', 'test': 'Le notifiche funzionano', 'test_body': 'Le nuove email di {account} arriveranno qui.',
           'no_subject': '(senza oggetto)'},
    'en': {'more': '{n} more new emails', 'test': 'Notifications work', 'test_body': 'New emails of {account} will show up here.',
           'no_subject': '(no subject)'},
}

_vapid = None
_lock = threading.Lock()


def init(conn):
    conn.executescript(SCHEMA)
    cols = {r[1] for r in conn.execute('PRAGMA table_info(push_pending)')}
    if 'address' not in cols:
        conn.execute("ALTER TABLE push_pending ADD COLUMN address TEXT NOT NULL DEFAULT ''")
        conn.execute('ALTER TABLE push_pending ADD COLUMN signed INTEGER NOT NULL DEFAULT 0')


def _t(lang, key, **kw):
    return TEXTS.get(lang, TEXTS['it'])[key].format(**kw)


# ---------- keys ----------

def vapid(data_dir):
    """The VAPID key pair, made once and kept in the data folder."""
    global _vapid
    if _vapid is None:
        from py_vapid import Vapid01
        path = os.path.join(data_dir, 'vapid_private.pem')
        if not os.path.exists(path):
            v = Vapid01()
            v.generate_keys()
            v.save_key(path)
            os.chmod(path, 0o600)
        _vapid = Vapid01.from_file(path)
    return _vapid


def public_key(data_dir):
    from cryptography.hazmat.primitives import serialization
    raw = vapid(data_dir).public_key.public_bytes(serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint)
    return base64.urlsafe_b64encode(raw).decode().rstrip('=')


# ---------- what to send ----------

def every_of(conn, account):
    r = conn.execute('SELECT every FROM push_setting WHERE account = ?', (account,)).fetchone()
    return r['every'] if r else DEFAULT_EVERY


def queue(conn, account, uid, sender, subject, address='', signed=False):
    """A new unread email of INBOX: kept for the next notification, only if some device of that mailbox wants them.
    address and signed (its domain's DKIM passed): for the sender's picture, as Quokka shows it."""
    if conn.execute('SELECT 1 FROM push_sub WHERE account = ? LIMIT 1', (account,)).fetchone():
        conn.execute('INSERT OR IGNORE INTO push_pending (account, uid, sender, subject, at, address, signed) VALUES (?,?,?,?,?,?,?)',
                     (account, uid, sender[:200], subject[:300], time.time(), address[:200], int(bool(signed))))


def picture_url(url, address, signed):
    """The sender's picture from Quokka's avatars (contact photo, brand logo, site icon), the quokka when none."""
    base = url.split('#')[0].split('?')[0]
    if '@' not in (address or ''):
        return base + 'quokka-icon.png'
    encoded = base64.urlsafe_b64encode(address.lower().encode()).decode().rstrip('=')
    return f"{base}?Avatar/{1 if signed else 0}/{encoded}/q"


def due(conn, now=None):
    """{account: [pending rows]} whose time has come (every minutes since the last notification)."""
    now = now or time.time()
    out = {}
    for account in [r[0] for r in conn.execute('SELECT DISTINCT account FROM push_pending')]:
        s = conn.execute('SELECT every, last_sent FROM push_setting WHERE account = ?', (account,)).fetchone()
        every, last = (s['every'], s['last_sent']) if s else (DEFAULT_EVERY, 0)
        if now - last >= every * 60 - 20:           # a round lasts about a minute: do not wait one more for 20 s
            out[account] = conn.execute('SELECT * FROM push_pending WHERE account = ? ORDER BY uid', (account,)).fetchall()
    return out


def open_url(url, account, uid=None):
    """Where a tap goes: that mailbox (Quokka switches to it if needed), and that email when there is one."""
    from urllib.parse import quote
    base = url.split('#')[0].split('?')[0]
    return f"{base}?quokka-account={quote(account)}#/mailbox/INBOX" + (f'/m{uid}' if uid else '')


MAX_EACH = 10                   # one notification per email, like Gmail; past this many at once, one for the rest


def payloads(rows, lang, url, account):
    """One notification per email (its own tag: they do not replace each other), opening that email; a long burst
    ends with one "N more emails" opening the inbox."""
    out = [{'title': r['sender'], 'body': r['subject'] or _t(lang, 'no_subject'), 'url': open_url(url, account, r['uid']),
            'tag': f"mail:{account}:{r['uid']}", 'icon': picture_url(url, r['address'], r['signed'])} for r in rows[:MAX_EACH]]
    rest = rows[MAX_EACH:]
    if rest:
        out.append({'title': _t(lang, 'more', n=len(rest)), 'body': ', '.join(dict.fromkeys(r['sender'] for r in rest[:4])),
                    'url': open_url(url, account), 'tag': f'mail:{account}:more'})
    return out


def flush(conn, data_dir, now=None, send=None):
    """Send what is due. Returns how many notifications went out."""
    send = send or _webpush
    sent = 0
    for account, rows in due(conn, now).items():
        subs = conn.execute('SELECT * FROM push_sub WHERE account = ?', (account,)).fetchall()
        for s in subs:
            for data in payloads(rows, s['lang'], s['url'], account):
                if not _deliver(conn, data_dir, s, data, send):
                    break                                  # device gone or service down: not the rest of them
                sent += 1
        conn.execute('DELETE FROM push_pending WHERE account = ?', (account,))
        conn.execute('INSERT INTO push_setting (account, every, last_sent) VALUES (?, ?, ?) '
                     'ON CONFLICT (account) DO UPDATE SET last_sent = excluded.last_sent',
                     (account, DEFAULT_EVERY, now or time.time()))
    conn.commit()
    return sent


def _deliver(conn, data_dir, sub, data, send):
    status = send({'endpoint': sub['endpoint'], 'keys': json.loads(sub['keys'])}, data, data_dir, sub['url'])
    if status in (404, 410):                       # the browser dropped the subscription
        conn.execute('DELETE FROM push_sub WHERE endpoint = ?', (sub['endpoint'],))
        log.info('%s: a device unsubscribed', sub['account'])
        return 0
    return 1 if status and status < 300 else 0


last_error = ''                 # why the last push failed (shown in Quokka when the test notification does not go out)


def _webpush(subscription, data, data_dir, subject):
    """HTTP status of the push service (0 = not reached)."""
    global last_error
    from pywebpush import WebPushException, webpush
    # VAPID "sub": a mailto: or the bare https://host (py_vapid refuses a path, even a trailing "/": every push failed)
    from urllib.parse import urlsplit
    parts = urlsplit(subject)
    sub = subject if subject.startswith('mailto:') else (
        f'https://{parts.netloc}' if parts.scheme == 'https' and parts.netloc else 'mailto:noreply@example.com')
    try:
        r = webpush(subscription, json.dumps(data), vapid_private_key=vapid(data_dir), vapid_claims={'sub': sub},
                    ttl=86400, timeout=10, headers={'Urgency': 'high'})   # high: a phone asleep gets it at once
        return getattr(r, 'status_code', 201)
    except WebPushException as e:
        status = getattr(e.response, 'status_code', 0)
        body = (getattr(e.response, 'text', '') or '')[:200]
        last_error = f'{status} {body or e}'[:300]
        log.warning('push failed (%s): %s %s', status, e, body)
        return status
    except Exception as e:
        last_error = f'{type(e).__name__}: {e}'[:300]
        log.warning('push failed: %s: %s', type(e).__name__, e)
        return 0


# ---------- the service Quokka talks to ----------

def _key(secret):
    return hashlib.sha256(b'quokka-push|' + secret.encode()).digest()


def sign(secret, ts, path, body):
    """The signature Quokka puts on a call: HMAC-SHA256 of time, path and body."""
    return hmac.new(_key(secret), f'{ts}\n{path}\n'.encode() + body, hashlib.sha256).hexdigest()


class Service:
    """The handlers, apart from HTTP so tests can call them."""

    def __init__(self, db_path, data_dir, accounts, secret, send=None, connect=None, parse=None, categories=()):
        self.db_path, self.data_dir, self.accounts, self.secret, self.send = db_path, data_dir, accounts, secret, send
        self.connect = connect              # sorter.connect: an IMAP session on a mailbox (for /act, /search)
        self.parse, self.categories = parse, categories     # sorter.parse, classify.CATEGORIES (for /search)

    def conn(self):
        c = sqlite3.connect(self.db_path, timeout=15)
        c.row_factory = sqlite3.Row
        init(c)
        c.execute('CREATE TABLE IF NOT EXISTS from_quokka (account TEXT PRIMARY KEY, password TEXT NOT NULL, '
                  "at TEXT NOT NULL DEFAULT (datetime('now')), failures INTEGER NOT NULL DEFAULT 0)")
        return c

    def watched(self, account):
        """The mailbox as written in accounts.json (only those are read), or None."""
        a = str(account or '').strip().lower()
        return next((x['user'] for x in self.accounts() if x['user'].lower() == a), None)

    def handle(self, path, data):
        if path == '/key':
            return 200, {'key': public_key(self.data_dir)}
        # "All mailboxes" (inbox.py): reading needs no lock, and an action talks to the mail server for a moment
        if path == '/inbox':
            import inbox
            return inbox.handle_list(self, data)
        if path == '/act':
            import inbox
            return inbox.handle_act(self, data, self.connect)
        if path == '/folders':
            import inbox
            return inbox.handle_refresh(self, data, self.connect)
        if path == '/search':
            import inbox
            return inbox.handle_search(self, data, self.connect, self.parse, self.categories)
        with _lock, self.conn() as c:
            if path == '/register':
                return self.register(c, data)
            account = self.watched(data.get('account'))
            if path == '/unsubscribe':
                endpoint = str(data.get('endpoint') or '')
                if data.get('account'):
                    c.execute('DELETE FROM push_sub WHERE endpoint = ? AND account = ?', (endpoint, account or ''))
                else:
                    c.execute('DELETE FROM push_sub WHERE endpoint = ?', (endpoint,))
                return 200, {'ok': True}
            if path == '/status':
                if not account:
                    return 200, {'watched': False}
                sub = c.execute('SELECT 1 FROM push_sub WHERE endpoint = ? AND account = ?',
                                (str(data.get('endpoint') or ''), account)).fetchone()
                seen = c.execute("SELECT at FROM checked WHERE account = ?", (account,)).fetchone() \
                    if c.execute("SELECT 1 FROM sqlite_master WHERE name = 'checked'").fetchone() else None
                return 200, {'watched': True, 'every': every_of(c, account), 'subscribed': bool(sub),
                             'checked_at': (seen['at'].replace(' ', 'T') + 'Z') if seen else None}
            if not account:
                return 404, {'error': 'not_watched'}
            if path == '/subscribe':
                sub = data.get('subscription') or {}
                endpoint, keys = str(sub.get('endpoint') or ''), sub.get('keys') or {}
                url = str(data.get('url') or '')
                if not endpoint.startswith('https://') or not keys.get('p256dh') or not keys.get('auth') or not url.startswith('http'):
                    return 400, {'error': 'invalid subscription'}
                lang = 'en' if str(data.get('lang') or '').startswith('en') else 'it'
                c.execute('INSERT INTO push_sub (endpoint, account, keys, url, lang) VALUES (?,?,?,?,?) ON CONFLICT (endpoint, account) '
                          'DO UPDATE SET keys = excluded.keys, url = excluded.url, lang = excluded.lang',
                          (endpoint, account, json.dumps({'p256dh': keys['p256dh'], 'auth': keys['auth']}), url, lang))
                if data.get('test') is False:
                    return 200, {'ok': True, 'every': every_of(c, account)}
                # a first notification at once: proof it works on this device
                label = str(data.get('label') or account)[:300]
                test = {'title': _t(lang, 'test'), 'body': _t(lang, 'test_body', account=label), 'url': url, 'tag': 'test'}
                sub_row = c.execute('SELECT * FROM push_sub WHERE endpoint = ? AND account = ?', (endpoint, account)).fetchone()
                sent = _deliver(c, self.data_dir, sub_row, test, self.send or _webpush)
                return 200, {'ok': True, 'every': every_of(c, account), 'test_sent': bool(sent),
                             'test_error': '' if sent else last_error}
            if path == '/every':
                try:
                    every = int(data.get('every'))
                except (TypeError, ValueError):
                    every = -1
                if not 0 <= every <= MAX_EVERY:
                    return 400, {'error': f'every: 0 to {MAX_EVERY} minutes'}
                c.execute('INSERT INTO push_setting (account, every) VALUES (?, ?) '
                          'ON CONFLICT (account) DO UPDATE SET every = excluded.every', (account, every))
                return 200, {'ok': True, 'every': every}
            if path == '/test':
                s = c.execute('SELECT * FROM push_sub WHERE endpoint = ? AND account = ?',
                              (str(data.get('endpoint') or ''), account)).fetchone()
                if not s:
                    return 404, {'error': 'not_subscribed'}
                data = {'title': _t(s['lang'], 'test'), 'body': _t(s['lang'], 'test_body', account=account),
                        'url': s['url'], 'tag': 'test'}
                ok = _deliver(c, self.data_dir, s, data, self.send or _webpush)
                return (200, {'ok': True}) if ok else (502, {'error': 'push_failed'})
        return 404, {'error': 'unknown'}

    def register(self, c, data):
        import re
        import secret
        account = str(data.get('account') or '').strip()
        password = str(data.get('password') or '')
        if not re.fullmatch(r'[^@\s]{1,200}@[^@\s]{1,200}', account) or not password or len(password) > 500:
            return 400, {'error': 'invalid mailbox'}
        sealed = secret.seal(password, account, self.secret)
        c.execute('INSERT INTO from_quokka (account, password) VALUES (?, ?) ON CONFLICT (account) '
                  "DO UPDATE SET password = excluded.password, failures = 0, at = datetime('now')", (account, sealed))
        return 200, {'ok': True}

    def authorised(self, path, body, ts, signature):
        try:
            fresh = abs(time.time() - int(ts)) <= MAX_SKEW
        except (TypeError, ValueError):
            return False
        return fresh and len(self.secret) >= 32 and hmac.compare_digest(sign(self.secret, ts, path, body), str(signature or ''))


def serve(service, port):
    class Handler(BaseHTTPRequestHandler):
        def _answer(self, status, data):
            out = json.dumps(data).encode()
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(out)))
            self.end_headers()
            self.wfile.write(out)

        def _run(self, body):
            if not service.authorised(self.path, body, self.headers.get('X-Quokka-Time'), self.headers.get('X-Quokka-Sign')):
                return self._answer(403, {'error': 'not signed'})
            try:
                data = json.loads(body or b'{}')
                status, out = service.handle(self.path, data if isinstance(data, dict) else {})
            except Exception as e:
                log.warning('push service %s: %s: %s', self.path, type(e).__name__, e)
                status, out = 500, {'error': 'failed'}
            self._answer(status, out)

        def do_GET(self):
            self._run(b'')

        def do_POST(self):
            n = min(int(self.headers.get('Content-Length') or 0), 20000)
            self._run(self.rfile.read(n))

        def log_message(self, *args):
            pass

    server = ThreadingHTTPServer(('0.0.0.0', port), Handler)
    threading.Thread(target=server.serve_forever, daemon=True, name='push-service').start()
    log.info('notifications: service on port %s', port)
    return server

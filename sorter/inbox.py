"""All the mailboxes in one list: an index of every mailbox's INBOX, for Quokka's "All mailboxes".

The sorter already reads each mailbox every round: what it sees of INBOX (sender, subject, the start of the text,
read or not, starred, category) is kept here for the last INDEX_DAYS days, so Quokka can show the emails of all of
a person's mailboxes together without signing in to each one at every look. Each round also brings the index up to
date with what changed in the mailbox (read elsewhere, moved, deleted), and fills in what it does not have yet,
newest first, a few at a time.

Quokka asks over the same signed internal service as the notifications (push.py):

  POST /inbox {accounts, category?, unseen?, attachment?, text?, offset?, limit?}
        → {messages: [...], unread: {account: n}, more, missing: [not read by the sorter], pending: [not indexed yet]}
  POST /act   {account, uids, action, folder?}
        action: seen | unseen | flag | unflag (on the server and in the index), opened (the index only: Quokka marks
        it read itself when it opens it), archive | trash (moved to `folder`, else the server's special-use folder)

Quokka only ever passes the mailboxes of the person signed in (main and added accounts).

Settings: INDEX_DAYS (60)."""
import email.utils
import logging
import os
import re
import time

log = logging.getLogger('sorter.inbox')
DAYS = int(os.environ.get('INDEX_DAYS', '60'))
BACKFILL = 150                  # emails not in the index yet, per mailbox per round (the newest first)
PREVIEW = 200                   # characters of text under the subject
MAX_LIMIT = 100
MAX_UIDS = 500

SCHEMA = '''
CREATE TABLE IF NOT EXISTS inbox (account TEXT NOT NULL, uid INTEGER NOT NULL, at REAL NOT NULL,
    sender TEXT NOT NULL, address TEXT NOT NULL, subject TEXT NOT NULL, preview TEXT NOT NULL,
    seen INTEGER NOT NULL DEFAULT 0, flagged INTEGER NOT NULL DEFAULT 0, category TEXT NOT NULL DEFAULT '',
    attachment INTEGER NOT NULL DEFAULT 0, signed INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (account, uid));
CREATE INDEX IF NOT EXISTS inbox_at ON inbox (at DESC);
CREATE TABLE IF NOT EXISTS inbox_state (account TEXT PRIMARY KEY, uidvalidity INTEGER NOT NULL,
    at TEXT NOT NULL DEFAULT (datetime('now')));
'''


def init(conn):
    conn.executescript(SCHEMA)


# ---------- keeping it up to date (from the sorter's round) ----------

def _category(flags, categories):
    return next((f for f in sorted(flags or ()) if f in categories), '')


def add(conn, account, uid, raw, mail, flags, categories):
    """An email of INBOX into the index. raw: its headers and the start of its text; mail: classify.Email."""
    head = raw.split(b'\r\n\r\n', 1)[0] if b'\r\n\r\n' in raw else raw.split(b'\n\n', 1)[0]
    head_l = head.lower()
    date = re.search(rb'^date:[ \t]*(.+(?:\r?\n[ \t].+)*)', head, re.I | re.M)
    try:
        at = email.utils.parsedate_to_datetime(date.group(1).decode('ascii', 'replace').strip()).timestamp() if date else time.time()
    except (TypeError, ValueError, IndexError):
        at = time.time()
    name, addr = email.utils.parseaddr(mail.sender)
    ctype = re.search(rb'^content-type:[ \t]*([^;\r\n]+)', head_l, re.M)
    attachment = bool(ctype and ctype.group(1).strip() == b'multipart/mixed')
    flags = flags or set()
    conn.execute('INSERT OR REPLACE INTO inbox (account, uid, at, sender, address, subject, preview, seen, flagged, category, '
                 'attachment, signed) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                 (account, uid, min(at, time.time() + 86400), (name or addr or mail.sender)[:200], addr[:200],
                  mail.subject[:300], ' '.join(mail.text.split())[:PREVIEW], int('\\seen' in flags),
                  int('\\flagged' in flags), _category(flags, categories), int(attachment), int(b'dkim=pass' in head_l)))


def sync(conn, account, uidvalidity, box, parse, categories):
    """After a round: the index of this mailbox as INBOX is now (read, starred, category, gone), plus up to BACKFILL
    emails it does not have yet. parse: raw → classify.Email."""
    row = conn.execute('SELECT uidvalidity FROM inbox_state WHERE account = ?', (account,)).fetchone()
    if row and row[0] != uidvalidity:                 # the mailbox was rebuilt: its uids mean other emails now
        conn.execute('DELETE FROM inbox WHERE account = ?', (account,))
    conn.execute("INSERT INTO inbox_state (account, uidvalidity) VALUES (?, ?) ON CONFLICT (account) "
                 "DO UPDATE SET uidvalidity = excluded.uidvalidity, at = datetime('now')", (account, uidvalidity))
    now = set(box.uids_since(DAYS))
    known = {r[0] for r in conn.execute('SELECT uid FROM inbox WHERE account = ?', (account,))}
    gone = known - now
    for uid in gone:
        conn.execute('DELETE FROM inbox WHERE account = ? AND uid = ?', (account, uid))
    kept = sorted(known & now)
    if kept:
        for uid, flags in box.flags(kept).items():
            conn.execute('UPDATE inbox SET seen = ?, flagged = ?, category = ? WHERE account = ? AND uid = ?',
                         (int('\\seen' in flags), int('\\flagged' in flags), _category(flags, categories), account, uid))
    added = 0
    for uid in sorted(now - known, reverse=True)[:BACKFILL]:
        raw, flags = box.fetch(uid)
        if raw is not None:
            add(conn, account, uid, raw, parse(raw), flags, categories)
            added += 1
    return added, len(gone)


# ---------- what Quokka asks ----------

def listing(conn, accounts, category='', unseen=False, attachment=False, text='', offset=0, limit=50):
    """The emails of these mailboxes (as the index writes them), newest first, and the unread of each."""
    if not accounts:
        return [], {}, False
    marks = ','.join('?' * len(accounts))
    where, args = [f'account IN ({marks})'], list(accounts)
    if category:
        where.append('category = ?')
        args.append(category)
    if unseen:
        where.append('seen = 0')
    if attachment:
        where.append('attachment = 1')
    for word in text.split()[:5]:
        like = '%' + word.replace('\\', '\\\\').replace('%', '\\%').replace('_', '\\_') + '%'
        where.append("(sender LIKE ? ESCAPE '\\' OR address LIKE ? ESCAPE '\\' OR subject LIKE ? ESCAPE '\\' "
                     "OR preview LIKE ? ESCAPE '\\')")
        args += [like] * 4
    limit = max(1, min(int(limit), MAX_LIMIT))
    rows = conn.execute(f"SELECT * FROM inbox WHERE {' AND '.join(where)} ORDER BY at DESC, uid DESC LIMIT ? OFFSET ?",
                        args + [limit + 1, max(0, int(offset))]).fetchall()
    unread = {r[0]: r[1] for r in conn.execute(
        f'SELECT account, COUNT(*) FROM inbox WHERE account IN ({marks}) AND seen = 0 GROUP BY account', list(accounts))}
    return rows[:limit], unread, len(rows) > limit


def handle_list(service, data):
    asked = [str(a) for a in (data.get('accounts') or [])][:20]
    watched = {a: service.watched(a) for a in asked}             # as Quokka writes it → as the sorter does
    back = {v: k for k, v in watched.items() if v}
    with service.conn() as c:
        init(c)
        indexed = {r[0] for r in c.execute('SELECT account FROM inbox_state')}
        rows, unread, more = listing(
            c, list(back), category=str(data.get('category') or '')[:30], unseen=bool(data.get('unseen')),
            attachment=bool(data.get('attachment')), text=str(data.get('text') or '')[:200],
            offset=_int(data.get('offset'), 0), limit=_int(data.get('limit'), 50))
    return 200, {
        'messages': [{'account': back[r['account']], 'uid': r['uid'], 'at': int(r['at']), 'from': r['sender'],
                      'address': r['address'], 'subject': r['subject'], 'preview': r['preview'], 'seen': bool(r['seen']),
                      'flagged': bool(r['flagged']), 'category': r['category'], 'attachment': bool(r['attachment']),
                      'signed': bool(r['signed'])} for r in rows],
        'unread': {back[a]: n for a, n in unread.items()},
        'more': more,
        'missing': [a for a in asked if not watched[a]],
        'pending': [a for a in asked if watched[a] and watched[a] not in indexed],
    }


def _int(value, default):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


FLAG_ACTIONS = {'seen': ('+FLAGS', '\\Seen', 'seen', 1), 'unseen': ('-FLAGS', '\\Seen', 'seen', 0),
                'flag': ('+FLAGS', '\\Flagged', 'flagged', 1), 'unflag': ('-FLAGS', '\\Flagged', 'flagged', 0)}
SPECIAL = {'trash': ('\\trash', ('trash', 'cestino', 'deleted items', 'deleted messages', 'inbox.trash', 'inbox.cestino')),
           'archive': ('\\archive', ('archive', 'archivio', 'inbox.archive', 'inbox.archivio'))}


def handle_act(service, data, connect):
    """One action on emails of one mailbox. The IMAP part runs outside the service's lock (it takes a moment)."""
    account = service.watched(data.get('account'))
    action = str(data.get('action') or '')
    try:
        uids = sorted({int(u) for u in (data.get('uids') or [])[:MAX_UIDS] if int(u) > 0})
    except (TypeError, ValueError):
        uids = []
    if not account:
        return 404, {'error': 'not_watched'}
    if not uids or action not in set(FLAG_ACTIONS) | set(SPECIAL) | {'opened'}:
        return 400, {'error': 'invalid'}
    if action == 'opened':               # Quokka marks it read when it opens it; the index shows it at once
        return _index(service, account, uids, 'seen', 1)
    acc = next((a for a in service.accounts() if a['user'] == account), None)
    if not acc or connect is None:
        return 404, {'error': 'not_watched'}
    try:
        imap = connect(acc)
    except Exception as e:
        log.warning('%s: %s: %s', account, type(e).__name__, e)
        return 502, {'error': 'imap'}
    try:
        typ, _ = imap.select('INBOX')
        if typ != 'OK':
            return 502, {'error': 'imap'}
        uidset = ','.join(map(str, uids))
        if action in FLAG_ACTIONS:
            op, flag, column, value = FLAG_ACTIONS[action]
            typ, _ = imap.uid('STORE', uidset, op, f'({flag})')
            if typ != 'OK':
                return 502, {'error': 'imap'}
            return _index(service, account, uids, column, value)
        target = _imap_name(str(data.get('folder') or '')) or special_folder(imap, action)
        if not target:
            return 409, {'error': 'no_folder'}
        if not move(imap, uidset, target):
            return 502, {'error': 'imap'}
        with service.conn() as c:
            c.executemany('DELETE FROM inbox WHERE account = ? AND uid = ?', [(account, u) for u in uids])
        return 200, {'ok': True}
    except Exception as e:
        log.warning('%s: %s %s: %s: %s', account, action, uids[:5], type(e).__name__, e)
        return 502, {'error': 'imap'}
    finally:
        try:
            imap.logout()
        except Exception:
            pass


def _index(service, account, uids, column, value):
    with service.conn() as c:
        init(c)
        c.executemany(f'UPDATE inbox SET {column} = ? WHERE account = ? AND uid = ?', [(value, account, u) for u in uids])
    return 200, {'ok': True}


def move(imap, uidset, target):
    caps = {str(c).upper() for c in (imap.capabilities or ())}
    if 'MOVE' in caps:
        return imap.uid('MOVE', uidset, target)[0] == 'OK'
    if 'UIDPLUS' not in caps:
        return False                      # without UID EXPUNGE other emails marked deleted would go too
    if imap.uid('COPY', uidset, target)[0] != 'OK':
        return False
    imap.uid('STORE', uidset, '+FLAGS', '(\\Deleted)')
    return imap.uid('EXPUNGE', uidset)[0] == 'OK'


_LIST = re.compile(rb'^\((?P<flags>[^)]*)\) (?:"(?:[^"\\]|\\.)*"|NIL) (?P<name>.+)$')


def special_folder(imap, action):
    """The server's Trash or Archive: by its special-use flag (RFC 6154), else by the usual names."""
    flag, names = SPECIAL[action]
    typ, data = imap.list()
    found = []
    for line in data or []:
        line = line[0] if isinstance(line, tuple) else line
        m = _LIST.match(line or b'')
        if m:
            found.append(({f.lower() for f in m.group('flags').decode('ascii', 'replace').split()},
                          m.group('name').decode('utf-8', 'replace').strip()))
    for flags, name in found:
        if flag in flags:
            return name if name.startswith('"') else _quote(name)
    by_name = {name.strip('"').lower(): name for _, name in found}
    for n in names:
        if n in by_name:
            name = by_name[n]
            return name if name.startswith('"') else _quote(name)
    return ''


def _quote(name):
    return '"' + name.replace('\\', '\\\\').replace('"', '\\"') + '"'


def _imap_name(name):
    """A folder name as Quokka writes it (UTF-8) → quoted, in IMAP's modified UTF-7 (RFC 3501 5.1.3)."""
    name = name.strip()
    if not name or len(name) > 300 or any(c in name for c in '\r\n\0'):
        return ''
    out, buf = [], []

    def flush():
        if buf:
            import base64
            b = base64.b64encode(''.join(buf).encode('utf-16-be')).decode().rstrip('=').replace('/', ',')
            out.append('&' + b + '-')
            buf.clear()
    for ch in name:
        if 0x20 <= ord(ch) <= 0x7e:
            flush()
            out.append('&-' if ch == '&' else ch)
        else:
            buf.append(ch)
    flush()
    return _quote(''.join(out))

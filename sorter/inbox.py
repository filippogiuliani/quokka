"""All the mailboxes in one list: an index of every mailbox's INBOX and system folders (Sent, Drafts, Trash, Spam,
Archive), for Quokka's "All mailboxes": one Trash for all the mailboxes, one Sent…

The sorter already reads each mailbox every round: what it sees of INBOX (sender, subject, the start of the text,
read or not, starred, category) is kept here for the last INDEX_DAYS days, so Quokka can show the emails of all of
a person's mailboxes together without signing in to each one at every look. Each round also brings the index up to
date with what changed in the mailbox (read elsewhere, moved, deleted), and fills in what it does not have yet,
newest first, a few at a time.

Quokka asks over the same signed internal service as the notifications (push.py):

  POST /inbox {accounts, role?, category?, unseen?, attachment?, text?, offset?, limit?}
        role: inbox (default) | sent | drafts | trash | junk | archive
        → {messages: [... with their folder], unread: {account: n} (INBOX), unread_here: n (that role), more,
           missing: [not read by the sorter], pending: [not indexed yet]}
  POST /act   {account, role?, uids, action, folder?}
        action: seen | unseen | flag | unflag (on the server and in the index), opened (the index only: Quokka marks
        it read itself when it opens it), archive | trash (moved to `folder`, else the server's special-use folder)

Quokka only ever passes the mailboxes of the person signed in (main and added accounts).

The system folders are found by their special-use flag (RFC 6154), else by the usual names, and only read
(EXAMINE): nothing in them is ever changed but by Quokka's actions.

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

ROLES = ('inbox', 'sent', 'drafts', 'trash', 'junk', 'archive')
SCHEMA = '''
CREATE TABLE IF NOT EXISTS inbox (account TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'inbox', uid INTEGER NOT NULL,
    at REAL NOT NULL, sender TEXT NOT NULL, address TEXT NOT NULL, subject TEXT NOT NULL, preview TEXT NOT NULL,
    seen INTEGER NOT NULL DEFAULT 0, flagged INTEGER NOT NULL DEFAULT 0, category TEXT NOT NULL DEFAULT '',
    attachment INTEGER NOT NULL DEFAULT 0, signed INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (account, role, uid));
CREATE INDEX IF NOT EXISTS inbox_at ON inbox (role, at DESC);
CREATE TABLE IF NOT EXISTS inbox_state (account TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'inbox',
    uidvalidity INTEGER NOT NULL, folder TEXT NOT NULL DEFAULT 'INBOX', raw TEXT NOT NULL DEFAULT 'INBOX',
    at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (account, role));
'''


def init(conn):
    # an index of INBOX alone (before the system folders): made again, it fills itself in the next rounds
    cols = {r[1] for r in conn.execute('PRAGMA table_info(inbox)')}
    if cols and 'role' not in cols:
        conn.executescript('DROP TABLE IF EXISTS inbox; DROP TABLE IF EXISTS inbox_state;')
    conn.executescript(SCHEMA)


# ---------- keeping it up to date (from the sorter's round) ----------

def _category(flags, categories):
    return next((f for f in sorted(flags or ()) if f in categories), '')


def add(conn, account, uid, raw, mail, flags, categories, role='inbox'):
    """An email of a folder into the index. raw: its headers and the start of its text; mail: classify.Email."""
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
    conn.execute('INSERT OR REPLACE INTO inbox (account, role, uid, at, sender, address, subject, preview, seen, flagged, '
                 'category, attachment, signed) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                 (account, role, uid, min(at, time.time() + 86400), (name or addr or mail.sender)[:200], addr[:200],
                  mail.subject[:300], ' '.join(mail.text.split())[:PREVIEW], int('\\seen' in flags),
                  int('\\flagged' in flags), _category(flags, categories), int(attachment), int(b'dkim=pass' in head_l)))


def sync(conn, account, uidvalidity, box, parse, categories, role='inbox', folder='INBOX', raw='INBOX'):
    """After a round: the index of this folder (selected in `box`) as it is now (read, starred, category, gone), plus
    up to BACKFILL emails it does not have yet. parse: raw → classify.Email. folder: its name as people read it
    (UTF-8), raw: as IMAP writes it."""
    row = conn.execute('SELECT uidvalidity FROM inbox_state WHERE account = ? AND role = ?', (account, role)).fetchone()
    if row and row[0] != uidvalidity:                 # the folder was rebuilt (or is another one): other emails now
        conn.execute('DELETE FROM inbox WHERE account = ? AND role = ?', (account, role))
    conn.execute("INSERT INTO inbox_state (account, role, uidvalidity, folder, raw) VALUES (?, ?, ?, ?, ?) "
                 "ON CONFLICT (account, role) DO UPDATE SET uidvalidity = excluded.uidvalidity, folder = excluded.folder, "
                 "raw = excluded.raw, at = datetime('now')", (account, role, uidvalidity, folder, raw))
    now = set(box.uids_since(DAYS))
    known = {r[0] for r in conn.execute('SELECT uid FROM inbox WHERE account = ? AND role = ?', (account, role))}
    gone = known - now
    for uid in gone:
        conn.execute('DELETE FROM inbox WHERE account = ? AND role = ? AND uid = ?', (account, role, uid))
    kept = sorted(known & now)
    if kept:
        for uid, flags in box.flags(kept).items():
            conn.execute('UPDATE inbox SET seen = ?, flagged = ?, category = ? WHERE account = ? AND role = ? AND uid = ?',
                         (int('\\seen' in flags), int('\\flagged' in flags), _category(flags, categories), account, role, uid))
    added = 0
    for uid in sorted(now - known, reverse=True)[:BACKFILL]:
        raw_mail, flags = box.fetch(uid)
        if raw_mail is not None:
            add(conn, account, uid, raw_mail, parse(raw_mail), flags, categories, role)
            added += 1
    return added, len(gone)


def sync_folders(conn, account, imap, box, parse, categories):
    """The system folders of a mailbox (after its INBOX): each one examined (read only) and its index brought up to
    date; a folder that is gone leaves the index. box: a Mailbox on that IMAP session (it reads what is selected)."""
    found = system_folders(imap)
    for role, (raw, name) in found.items():
        if role == 'inbox':
            continue
        typ, _ = imap.select(raw, readonly=True)
        if typ != 'OK':
            continue
        uidvalidity = int(imap.response('UIDVALIDITY')[1][0])
        sync(conn, account, uidvalidity, box, parse, categories, role, name, raw)
    for (role,) in conn.execute('SELECT role FROM inbox_state WHERE account = ? AND role != ?', (account, 'inbox')).fetchall():
        if role not in found:
            conn.execute('DELETE FROM inbox WHERE account = ? AND role = ?', (account, role))
            conn.execute('DELETE FROM inbox_state WHERE account = ? AND role = ?', (account, role))


# ---------- what Quokka asks ----------

def listing(conn, accounts, category='', unseen=False, attachment=False, text='', offset=0, limit=50, role='inbox'):
    """The emails of these mailboxes in one folder role (as the index writes them), newest first; the unread of each
    mailbox's INBOX (for the column) and of this role."""
    if not accounts:
        return [], {}, False, 0
    marks = ','.join('?' * len(accounts))
    where, args = [f'i.account IN ({marks})', 'i.role = ?'], list(accounts) + [role]
    if category:
        where.append('i.category = ?')
        args.append(category)
    if unseen:
        where.append('i.seen = 0')
    if attachment:
        where.append('i.attachment = 1')
    for word in text.split()[:5]:
        like = '%' + word.replace('\\', '\\\\').replace('%', '\\%').replace('_', '\\_') + '%'
        where.append("(i.sender LIKE ? ESCAPE '\\' OR i.address LIKE ? ESCAPE '\\' OR i.subject LIKE ? ESCAPE '\\' "
                     "OR i.preview LIKE ? ESCAPE '\\')")
        args += [like] * 4
    limit = max(1, min(int(limit), MAX_LIMIT))
    rows = conn.execute(f"SELECT i.*, s.folder FROM inbox i LEFT JOIN inbox_state s ON s.account = i.account AND s.role = i.role "
                        f"WHERE {' AND '.join(where)} ORDER BY i.at DESC, i.uid DESC LIMIT ? OFFSET ?",
                        args + [limit + 1, max(0, int(offset))]).fetchall()
    unread = {r[0]: r[1] for r in conn.execute(
        f"SELECT account, COUNT(*) FROM inbox WHERE account IN ({marks}) AND role = 'inbox' AND seen = 0 GROUP BY account",
        list(accounts))}
    here = conn.execute(f'SELECT COUNT(*) FROM inbox WHERE account IN ({marks}) AND role = ? AND seen = 0',
                        list(accounts) + [role]).fetchone()[0]
    return rows[:limit], unread, len(rows) > limit, here


def handle_list(service, data):
    asked = [str(a) for a in (data.get('accounts') or [])][:20]
    watched = {a: service.watched(a) for a in asked}             # as Quokka writes it → as the sorter does
    back = {v: k for k, v in watched.items() if v}
    role = str(data.get('role') or 'inbox')
    if role not in ROLES:
        return 400, {'error': 'invalid role'}
    with service.conn() as c:
        init(c)
        indexed = {r[0] for r in c.execute("SELECT account FROM inbox_state WHERE role = 'inbox'")}
        rows, unread, more, here = listing(
            c, list(back), category=str(data.get('category') or '')[:30], unseen=bool(data.get('unseen')),
            attachment=bool(data.get('attachment')), text=str(data.get('text') or '')[:200],
            offset=_int(data.get('offset'), 0), limit=_int(data.get('limit'), 50), role=role)
    return 200, {
        'messages': [{'account': back[r['account']], 'uid': r['uid'], 'at': int(r['at']), 'from': r['sender'],
                      'address': r['address'], 'subject': r['subject'], 'preview': r['preview'], 'seen': bool(r['seen']),
                      'flagged': bool(r['flagged']), 'category': r['category'], 'attachment': bool(r['attachment']),
                      'signed': bool(r['signed']), 'role': r['role'], 'folder': r['folder'] or 'INBOX'} for r in rows],
        'unread': {back[a]: n for a, n in unread.items()},
        'unread_here': here,
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
# the usual names, as Quokka knows them too (dev/Model/FolderCollection.js ROLE_NAMES)
SPECIAL = {'trash': ('\\trash', ('trash', 'cestino', 'deleted', 'deleted items', 'deleted messages', 'bin', 'posta eliminata',
                                  'elementi eliminati', 'inbox.trash', 'inbox.cestino')),
           'archive': ('\\archive', ('archive', 'archives', 'archivio', 'inbox.archive', 'inbox.archivio'))}
ROLE_FOLDERS = dict(SPECIAL, **{
    'sent': ('\\sent', ('sent', 'sent items', 'sent messages', 'sent mail', 'inviata', 'inviate', 'posta inviata', 'inviati',
                         'elementi inviati', 'messaggi inviati',
                         'inbox.sent', 'inbox.inviata', 'inbox.posta inviata')),
    'drafts': ('\\drafts', ('drafts', 'draft', 'bozze', 'bozza', 'inbox.drafts', 'inbox.bozze')),
    'junk': ('\\junk', ('junk', 'spam', 'posta indesiderata', 'indesiderata', 'junk e-mail', 'junk email', 'bulk mail',
                         'inbox.spam', 'inbox.junk')),
})


def handle_act(service, data, connect):
    """One action on emails of one mailbox. The IMAP part runs outside the service's lock (it takes a moment)."""
    account = service.watched(data.get('account'))
    action = str(data.get('action') or '')
    role = str(data.get('role') or 'inbox')
    try:
        uids = sorted({int(u) for u in (data.get('uids') or [])[:MAX_UIDS] if int(u) > 0})
    except (TypeError, ValueError):
        uids = []
    if not account:
        return 404, {'error': 'not_watched'}
    if not uids or role not in ROLES or action not in set(FLAG_ACTIONS) | set(SPECIAL) | {'opened'} or action == role:
        return 400, {'error': 'invalid'}       # (to the Trash from the Trash, to the Archive from the Archive: no)
    if action == 'opened':               # Quokka marks it read when it opens it; the index shows it at once
        return _index(service, account, role, uids, 'seen', 1)
    with service.conn() as c:
        init(c)
        state = c.execute('SELECT raw FROM inbox_state WHERE account = ? AND role = ?', (account, role)).fetchone()
    if not state and role != 'inbox':
        return 404, {'error': 'no_folder'}
    source = state[0] if state else 'INBOX'
    acc = next((a for a in service.accounts() if a['user'] == account), None)
    if not acc or connect is None:
        return 404, {'error': 'not_watched'}
    try:
        imap = connect(acc)
    except Exception as e:
        log.warning('%s: %s: %s', account, type(e).__name__, e)
        return 502, {'error': 'imap'}
    try:
        typ, _ = imap.select(source)
        if typ != 'OK':
            return 502, {'error': 'imap'}
        uidset = ','.join(map(str, uids))
        if action in FLAG_ACTIONS:
            op, flag, column, value = FLAG_ACTIONS[action]
            typ, _ = imap.uid('STORE', uidset, op, f'({flag})')
            if typ != 'OK':
                return 502, {'error': 'imap'}
            return _index(service, account, role, uids, column, value)
        target = _imap_name(str(data.get('folder') or '')) or special_folder(imap, action)
        if not target:
            return 409, {'error': 'no_folder'}
        if not move(imap, uidset, target):
            return 502, {'error': 'imap'}
        with service.conn() as c:
            c.executemany('DELETE FROM inbox WHERE account = ? AND role = ? AND uid = ?', [(account, role, u) for u in uids])
        return 200, {'ok': True}
    except Exception as e:
        log.warning('%s: %s %s: %s: %s', account, action, uids[:5], type(e).__name__, e)
        return 502, {'error': 'imap'}
    finally:
        try:
            imap.logout()
        except Exception:
            pass


def _index(service, account, role, uids, column, value):
    with service.conn() as c:
        init(c)
        c.executemany(f'UPDATE inbox SET {column} = ? WHERE account = ? AND role = ? AND uid = ?',
                      [(value, account, role, u) for u in uids])
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


def _listing(imap):
    """[(special-use flags, name as IMAP writes it)] of every folder"""
    typ, data = imap.list()
    found = []
    for line in data or []:
        line = line[0] if isinstance(line, tuple) else line
        m = _LIST.match(line or b'')
        if m:
            found.append(({f.lower() for f in m.group('flags').decode('ascii', 'replace').split()},
                          m.group('name').decode('utf-8', 'replace').strip()))
    return found


def _pick(found, role):
    flag, names = ROLE_FOLDERS[role]
    for flags, name in found:
        if flag in flags and '\\noselect' not in flags:
            return name if name.startswith('"') else _quote(name)
    by_name = {_utf7_decode(name.strip('"')).lower(): name for flags, name in found if '\\noselect' not in flags}
    for n in names:
        if n in by_name:
            name = by_name[n]
            return name if name.startswith('"') else _quote(name)
    return ''


def special_folder(imap, action):
    """The server's Trash or Archive: by its special-use flag (RFC 6154), else by the usual names."""
    return _pick(_listing(imap), action)


def system_folders(imap):
    """{role: (name as IMAP writes it, quoted; name as people read it)} of the folders this mailbox has"""
    found = _listing(imap)
    out = {'inbox': ('INBOX', 'INBOX')}
    for role in ROLE_FOLDERS:
        raw = _pick(found, role)
        if raw:
            out[role] = (raw, _utf7_decode(raw[1:-1].replace('\\"', '"').replace('\\\\', '\\')))
    return out


def _utf7_decode(name):
    """IMAP's modified UTF-7 → text ("Gi&AOA- letti" → "Già letti")"""
    import base64
    def part(m):
        b = m.group(1)
        if not b:
            return '&'
        b = b.replace(',', '/')
        try:
            return base64.b64decode(b + '=' * (-len(b) % 4)).decode('utf-16-be')
        except Exception:
            return m.group(0)
    return re.sub(r'&([^-]*)-', part, name)


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

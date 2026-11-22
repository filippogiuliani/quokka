"""Tests: the rules on typical family mail, and the learning from corrections, with a mailbox in memory."""
from email.message import EmailMessage

import classify
import sorter


def mail(sender, subject, text='', **headers):
    return classify.Email(sender=sender, subject=subject, text=text, headers={k.replace('_', '-'): v for k, v in headers.items()})


def test_rules_on_family_mail():
    cases = [
        (mail('Luce e Gas <fatture@lucegas.example>', 'La tua bolletta di novembre è disponibile',
              'Importo 84,30 €. Scadenza 5 dicembre.', list_unsubscribe='<mailto:x>'), 'bills'),
        (mail('Segreteria <segreteria@ic.example>', 'Uscita didattica: autorizzazione', 'Gentili famiglie, gli alunni…'), 'school'),
        (mail('Corriere <noreply@corriere.example>', 'Il tuo pacco è in consegna oggi', 'Segui la spedizione'), 'shipping'),
        (mail('Banca <info@banca.example>', 'Estratto conto di ottobre', 'Movimenti e saldo del conto'), 'bank'),
        (mail('Negozio <offerte@shop.example>', 'Solo per oggi: -30% su tutto', 'Approfitta degli sconti', list_unsubscribe='<x>'), 'promotions'),
        (mail('Instagram <no-reply@mail.instagram.com>', 'Anna ha iniziato a seguirti', 'Guarda il profilo', list_unsubscribe='<x>'), 'social'),
        (mail('LinkedIn <messages-noreply@linkedin.com>', 'Hai un nuovo invito', 'Accetta il collegamento', list_unsubscribe='<x>'), 'social'),
        (mail('Servizio <no-reply@servizio.example>', 'Il tuo codice di verifica', 'Usa questo codice OTP per l\'accesso'), 'account'),
        (mail('Account <security@app.example>', 'Nuovo accesso al tuo account', 'Abbiamo rilevato un accesso da un nuovo dispositivo'), 'account'),
        (mail('Negozio <noreply@shop.example>', 'Reimposta la password', 'Clicca per reimpostare la password'), 'account'),
        (mail('Palestra <info@palestra.example>', 'Conferma la tua registrazione', 'Clicca per attivare l\'account'), 'account'),
        (mail('Mamma <mamma@example.it>', 'Foto di domenica', 'Ti mando quelle venute meglio'), ''),
    ]
    for m, want in cases:
        assert classify.classify(m, {}, classify.Learned())[0] == want, m.subject


def test_accents_and_case_do_not_matter():
    assert classify.norm('NOVITÀ Più') == 'novita piu'
    assert classify.domain('a@news.shop.example.it') == 'shop.example.it'


class FakeBox:
    """A mailbox in memory: {uid: [raw, flags]}."""

    def __init__(self, keywords=True):
        self.mails, self.keywords, self.next = {}, keywords, 1

    def add(self, sender, subject, text='', flags=()):
        m = EmailMessage()
        m['From'], m['Subject'] = sender, subject
        m.set_content(text or subject)
        self.mails[self.next] = [m.as_bytes(), set(flags)]
        self.next += 1
        return self.next - 1

    def select(self):
        return 42, self.keywords

    def uids_after(self, last):
        return [u for u in self.mails if u > last]

    def uids_since(self, days):
        return list(self.mails)

    def fetch(self, uid):
        raw, flags = self.mails.get(uid, (None, None))
        return raw, (set(flags) if flags is not None else None)

    def flags(self, uids):
        return {u: set(self.mails[u][1]) for u in uids if u in self.mails}

    def tag(self, uid, kw):
        self.mails[uid][1].add(kw)


def test_new_emails_are_tagged_and_corrections_are_learned(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    box = FakeBox()
    bill = box.add('Rete <avvisi@retegas.example>', 'Nuova offerta per te', 'Scopri le nostre promozioni', )
    family = box.add('Mamma <mamma@example.it>', 'Ciao', 'Come state?')
    assert sorter.sort_round(conn, 'me', box) == (2, 0)
    assert box.mails[bill][1] == {'promotions'} and box.mails[family][1] == set()

    # In Quokka: that was actually a bill; and mum's emails are school business (just to teach something)
    box.mails[bill][1] = {'bills'}
    box.mails[family][1] = {'school'}
    assert sorter.sort_round(conn, 'me', box) == (0, 2)

    # The next ones from the same senders follow what was taught
    nxt = box.add('Rete <avvisi@retegas.example>', 'Altra offerta imperdibile', 'Approfitta')
    mum = box.add('Mamma <mamma@example.it>', 'Pranzo', 'Domenica?')
    sorter.sort_round(conn, 'me', box)
    assert box.mails[nxt][1] == {'bills'} and box.mails[mum][1] == {'school'}


def test_taking_the_category_off_teaches_none(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    box = FakeBox()
    a = box.add('Club <news@club.example>', 'Newsletter di novembre', 'Le novità del mese', )
    sorter.sort_round(conn, 'me', box)
    assert box.mails[a][1] == {'promotions'}
    box.mails[a][1] = set()                            # "this is not advertising"
    sorter.sort_round(conn, 'me', box)
    b = box.add('Club <news@club.example>', 'Newsletter di dicembre', 'Le novità del mese')
    sorter.sort_round(conn, 'me', box)
    assert box.mails[b][1] == set()


def test_a_whole_domain_after_two_addresses(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    box = FakeBox()
    x = box.add('A <a@ic-roma.example>', 'Avviso', 'testo')
    y = box.add('B <b@ic-roma.example>', 'Comunicazione', 'testo')
    sorter.sort_round(conn, 'me', box)
    box.mails[x][1] = {'school'}
    box.mails[y][1] = {'school'}
    sorter.sort_round(conn, 'me', box)
    z = box.add('C <c@ic-roma.example>', 'Orari', 'testo')
    sorter.sort_round(conn, 'me', box)
    assert box.mails[z][1] == {'school'}


def test_what_people_chose_is_never_touched_and_no_keywords_no_work(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    box = FakeBox()
    a = box.add('Negozio <offerte@shop.example>', 'Sconto del 30%', 'Approfitta', flags={'bank'})
    sorter.sort_round(conn, 'me', box)
    assert box.mails[a][1] == {'bank'}
    plain = FakeBox(keywords=False)
    b = plain.add('Negozio <offerte@shop.example>', 'Sconto del 30%', 'Approfitta')
    sorter.sort_round(conn, 'other', plain)                 # read (for the notifications), never tagged
    assert plain.mails[b][1] == set()


def test_html_only_mail_is_read():
    m = EmailMessage()
    m['From'], m['Subject'] = 'Banca <x@banca.example>', 'Avviso'
    m.set_content('<p>Il tuo <b>estratto conto</b> &egrave; pronto</p><style>p{}</style>', subtype='html')
    got = sorter.parse(m.as_bytes())
    assert 'estratto conto' in got.text and 'è' in got.text and '<' not in got.text


def test_passwords_are_sealed_and_bound_to_the_address(monkeypatch):
    import secret
    import pytest
    key = 'k' * 40
    sealed = secret.seal('la-password', 'Mario@Example.it', key)
    assert sealed.startswith('enc:v1:') and 'la-password' not in sealed
    assert secret.open_(sealed, 'mario@example.it', key) == 'la-password'
    with pytest.raises(Exception):
        secret.open_(sealed, 'altro@example.it', key)            # copied onto another entry: does not open
    with pytest.raises(Exception):
        secret.open_(sealed, 'mario@example.it', 'x' * 40)       # another key: does not open
    monkeypatch.setenv('QUOKKA_SECRET', key)
    monkeypatch.delenv('ALLOW_PLAIN_PASSWORDS', raising=False)
    assert sorter.password_of({'user': 'mario@example.it', 'password': sealed}) == 'la-password'
    with pytest.raises(ValueError):
        sorter.password_of({'user': 'mario@example.it', 'password': 'in-chiaro'})
    monkeypatch.setenv('ALLOW_PLAIN_PASSWORDS', '1')
    assert sorter.password_of({'user': 'mario@example.it', 'password': 'in-chiaro'}) == 'in-chiaro'



# ---------- notifications (push.py) ----------

import base64
import json
import time

import push

SECRET = 's' * 40


def _subscribe(conn, account='me', endpoint='https://push.example/1'):
    conn.execute("INSERT INTO push_sub (endpoint, account, keys, url, lang) VALUES (?, ?, '{\"p256dh\": \"x\", \"auth\": \"y\"}', "
                 "'https://mail.example', 'it')", (endpoint, account))


def test_new_unread_emails_become_one_notification_per_mailbox(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    box = FakeBox()
    box.add('Vecchia <old@example.it>', 'Già qui')
    sorter.sort_round(conn, 'me', box)                      # first start: what is there is not news
    _subscribe(conn)
    box.add('Mamma <mamma@example.it>', 'Cena di domenica')
    box.add('Letta <x@example.it>', 'Già letta altrove', flags={'\\seen'})
    sorter.sort_round(conn, 'me', box)
    sent = []
    send = lambda sub, data, d, subject: sent.append((sub['endpoint'], data)) or 201     # noqa: E731
    assert push.flush(conn, str(tmp_path), now=time.time(), send=send) == 1
    assert sent == [('https://push.example/1', {'title': 'Mamma', 'body': 'Cena di domenica',
                                                'url': 'https://mail.example?quokka-account=me#/mailbox/INBOX/m2', 'tag': 'mail:me:2',
                                                'icon': 'https://mail.example?Avatar/0/bWFtbWFAZXhhbXBsZS5pdA/q'})]
    assert push.picture_url('https://mail.example/', 'X@b.it', True) == 'https://mail.example/?Avatar/1/eEBiLml0/q'
    assert push.picture_url('https://mail.example/', '', False) == 'https://mail.example/quokka-icon.png'
    assert push.flush(conn, str(tmp_path), now=time.time(), send=send) == 1 - 1     # nothing left


def test_every_n_minutes_one_notification_per_email(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    _subscribe(conn)
    conn.execute("INSERT INTO push_setting (account, every, last_sent) VALUES ('me', 10, ?)", (time.time(),))
    for uid, who in enumerate(['Anna', 'Luca', 'Anna'], 1):
        push.queue(conn, 'me', uid, who, 'ciao')
    sent = []
    send = lambda sub, data, d, subject: sent.append(data) or 201                            # noqa: E731
    assert push.flush(conn, str(tmp_path), now=time.time() + 5 * 60, send=send) == 0         # not yet: 10 minutes
    assert push.flush(conn, str(tmp_path), now=time.time() + 10 * 60, send=send) == 3            # three emails: three
    assert [(d['title'], d['tag'], d['url'].split('#')[1]) for d in sent] == [
        ('Anna', 'mail:me:1', '/mailbox/INBOX/m1'), ('Luca', 'mail:me:2', '/mailbox/INBOX/m2'), ('Anna', 'mail:me:3', '/mailbox/INBOX/m3')]
    # a long burst: the first ones each on their own, then one for the rest
    for uid in range(10, 25):
        push.queue(conn, 'me', uid, f'S{uid}', 'x')
    sent.clear()
    push.flush(conn, str(tmp_path), now=time.time() + 30 * 60, send=send)
    assert len(sent) == push.MAX_EACH + 1 and sent[-1]['title'] == 'Altre 5 nuove email'


def test_nobody_subscribed_nothing_kept_and_gone_devices_are_dropped(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    push.queue(conn, 'me', 1, 'Anna', 'ciao')
    assert conn.execute('SELECT COUNT(*) FROM push_pending').fetchone()[0] == 0
    _subscribe(conn)
    push.queue(conn, 'me', 1, 'Anna', 'ciao')
    assert push.flush(conn, str(tmp_path), now=time.time() + 3600, send=lambda *a: 410) == 0
    assert conn.execute('SELECT COUNT(*) FROM push_sub').fetchone()[0] == 0


def test_the_service_quokka_talks_to(tmp_path):
    db_path = str(tmp_path / 's.db')
    sorter.db(db_path).close()
    accounts = lambda: [{'user': 'Me@Example.it', 'password': 'x'}]                          # noqa: E731
    sent = []
    svc = push.Service(db_path, str(tmp_path), accounts, SECRET, send=lambda sub, data, d, s: sent.append(data) or 201)
    # signed calls only, and only fresh ones
    ts = str(int(time.time()))
    assert svc.authorised('/status', b'{}', ts, push.sign(SECRET, ts, '/status', b'{}'))
    assert not svc.authorised('/status', b'{}', ts, push.sign('x' * 40, ts, '/status', b'{}'))
    old = str(int(time.time()) - 3600)
    assert not svc.authorised('/status', b'{}', old, push.sign(SECRET, old, '/status', b'{}'))
    # a mailbox the sorter does not read cannot have notifications
    assert svc.handle('/status', {'account': 'other@example.it'}) == (200, {'watched': False})
    sub = {'endpoint': 'https://push.example/d1', 'keys': {'p256dh': 'k', 'auth': 'a'}}
    assert svc.handle('/subscribe', {'account': 'other@example.it', 'subscription': sub, 'url': 'https://m'})[0] == 404
    assert svc.handle('/subscribe', {'account': 'me@example.it', 'subscription': {'endpoint': 'http://x'}, 'url': 'https://m'})[0] == 400
    assert svc.handle('/subscribe', {'account': 'me@example.it', 'subscription': sub, 'url': 'https://m', 'lang': 'it'})[0] == 200
    assert svc.handle('/status', {'account': 'me@example.it', 'endpoint': 'https://push.example/d1'}) == \
        (200, {'watched': True, 'every': 0, 'subscribed': True, 'checked_at': None})      # 0: at once, by default
    assert svc.handle('/every', {'account': 'me@example.it', 'every': 2000})[0] == 400
    assert svc.handle('/every', {'account': 'me@example.it', 'every': 'x'})[0] == 400
    assert svc.handle('/every', {'account': 'me@example.it', 'every': 2}) == (200, {'ok': True, 'every': 2})
    assert svc.handle('/every', {'account': 'me@example.it', 'every': 30}) == (200, {'ok': True, 'every': 30})
    assert svc.handle('/test', {'account': 'me@example.it', 'endpoint': 'https://push.example/d1'}) == (200, {'ok': True})
    assert sent[0]['title'] == 'Le notifiche funzionano' and 'Me@Example.it' in sent[0]['body']   # sent when turned on
    c = sorter.db(db_path); sorter._checked(c, 'Me@Example.it'); c.close()
    assert svc.handle('/status', {'account': 'me@example.it'})[1]['checked_at'].endswith('Z')       # when it was last read
    assert svc.handle('/key', {})[0] == 200 and len(base64.urlsafe_b64decode(svc.handle('/key', {})[1]['key'] + '==')) == 65
    # the same device may follow a second mailbox: the first one stays
    accounts2 = lambda: [{'user': 'Me@Example.it', 'password': 'x'}, {'user': 'pluto@example.it', 'password': 'x'}]  # noqa: E731
    svc.accounts = accounts2
    assert svc.handle('/subscribe', {'account': 'pluto@example.it', 'subscription': sub, 'url': 'https://m'})[0] == 200
    assert svc.handle('/status', {'account': 'me@example.it', 'endpoint': 'https://push.example/d1'})[1]['subscribed'] is True
    assert svc.handle('/unsubscribe', {'endpoint': 'https://push.example/d1', 'account': 'pluto@example.it'}) == (200, {'ok': True})
    assert svc.handle('/status', {'account': 'pluto@example.it', 'endpoint': 'https://push.example/d1'})[1]['subscribed'] is False
    assert svc.handle('/unsubscribe', {'endpoint': 'https://push.example/d1'}) == (200, {'ok': True})
    assert svc.handle('/status', {'account': 'me@example.it', 'endpoint': 'https://push.example/d1'})[1]['subscribed'] is False


def test_a_real_push_message_is_encrypted_for_the_device(tmp_path, monkeypatch):
    """pywebpush end to end against a fake push service: the device's keys open what the sorter sent."""
    import http_ece
    from cryptography.hazmat.primitives import serialization
    from cryptography.hazmat.primitives.asymmetric import ec
    device = ec.generate_private_key(ec.SECP256R1())
    pub = device.public_key().public_bytes(serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint)
    auth = b'0123456789abcdef'
    b64 = lambda b: base64.urlsafe_b64encode(b).decode().rstrip('=')                        # noqa: E731
    got = {}

    class Resp:
        status_code = 201
        text = ''
        headers = {}

    def fake_post(url, data=None, headers=None, timeout=None, **kw):
        got.update(url=url, data=data, headers=headers)
        return Resp()
    import requests
    monkeypatch.setattr(requests, 'post', fake_post)
    status = push._webpush({'endpoint': 'https://push.example/real', 'keys': {'p256dh': b64(pub), 'auth': b64(auth)}},
                           {'title': 'Mamma', 'body': 'Cena'}, str(tmp_path), 'https://mail.example/')   # with the "/" Quokka sends
    assert status == 201 and got['headers']['Urgency'] == 'high' and got['headers']['Authorization'].startswith(('vapid ', 'WebPush '))
    plain = http_ece.decrypt(got['data'], private_key=device, auth_secret=auth, version='aes128gcm')
    assert json.loads(plain) == {'title': 'Mamma', 'body': 'Cena'}


def test_mailboxes_come_from_quokka_with_no_file(tmp_path, monkeypatch):
    """Quokka passes on every mailbox someone signs in to: no accounts.json needed; a password refused 5 times in a
    row is left alone until Quokka passes it on again."""
    monkeypatch.setattr(sorter, 'ACCOUNTS', str(tmp_path / 'none.json'))
    monkeypatch.setenv('QUOKKA_SECRET', SECRET)
    db_path = str(tmp_path / 's.db')
    sorter.db(db_path).close()
    svc = push.Service(db_path, str(tmp_path), lambda: sorter.accounts(db_path), SECRET)
    assert svc.handle('/status', {'account': 'pluto@example.it'}) == (200, {'watched': False})
    assert svc.handle('/register', {'account': 'not an address', 'password': 'x'})[0] == 400
    assert svc.handle('/register', {'account': 'Pluto@Example.it', 'password': 'segreta'}) == (200, {'ok': True})
    accs = sorter.accounts(db_path)
    assert [a['user'] for a in accs] == ['Pluto@Example.it'] and accs[0]['password'].startswith('enc:v1:')
    assert sorter.password_of(accs[0]) == 'segreta'                     # sealed, and it opens
    assert svc.handle('/status', {'account': 'pluto@example.it'})[1]['watched'] is True
    conn = sorter.db(db_path)
    for _ in range(sorter.FAILURES_OFF):
        sorter._signed_in(conn, accs[0], False)
    assert sorter.accounts(db_path) == []                               # refused 5 times: left alone
    svc.handle('/register', {'account': 'Pluto@Example.it', 'password': 'nuova'})
    assert sorter.password_of(sorter.accounts(db_path)[0]) == 'nuova'  # signed in again with the new one
    # a mailbox also in accounts.json: the file wins, listed once
    (tmp_path / 'a.json').write_text('[{"user": "pluto@example.it", "password": "enc:v1:x"}]')
    monkeypatch.setattr(sorter, 'ACCOUNTS', str(tmp_path / 'a.json'))
    assert [a.get('from_quokka', False) for a in sorter.accounts(db_path)] == [False]


# ---------- "All mailboxes": the index of every INBOX (inbox.py) ----------

import inbox


def test_the_index_follows_inbox(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    box = FakeBox()
    old = box.add('Banca <info@banca.example>', 'Estratto conto di ottobre', 'Movimenti e saldo del conto', flags={'\\seen'})
    sorter.sort_round(conn, 'me', box)                              # first start: the last days, already read
    row = conn.execute("SELECT * FROM inbox WHERE account = 'me' AND uid = ?", (old,)).fetchone()
    assert row['subject'] == 'Estratto conto di ottobre' and row['seen'] == 1 and row['category'] == 'bank'
    assert row['sender'] == 'Banca' and row['address'] == 'info@banca.example' and 'saldo' in row['preview']

    new = box.add('Mamma <mamma@example.it>', 'Pranzo', 'Domenica da noi?')
    sorter.sort_round(conn, 'me', box)
    assert conn.execute("SELECT seen FROM inbox WHERE uid = ?", (new,)).fetchone()['seen'] == 0
    # read and starred elsewhere, the old one moved away: the next round says so
    box.mails[new][1] |= {'\\seen', '\\flagged'}
    del box.mails[old]
    sorter.sort_round(conn, 'me', box)
    assert [tuple(r) for r in conn.execute("SELECT uid, seen, flagged FROM inbox")] == [(new, 1, 1)]
    # emails the index does not have (a sorter older than the index): filled in
    conn.execute('DELETE FROM inbox')
    sorter.sort_round(conn, 'me', box)
    assert conn.execute('SELECT COUNT(*) FROM inbox').fetchone()[0] == 1


def _row(conn, account, uid, at, subject, seen=0, category='', attachment=0, role='inbox'):
    conn.execute('INSERT INTO inbox (account, role, uid, at, sender, address, subject, preview, seen, category, attachment) '
                 'VALUES (?,?,?,?,?,?,?,?,?,?,?)', (account, role, uid, at, 'X', 'x@example.it', subject, 'testo', seen, category, attachment))


def test_one_list_for_all_the_mailboxes_of_one_person(tmp_path):
    db_path = str(tmp_path / 's.db')
    conn = sorter.db(db_path)
    _row(conn, 'Me@Example.it', 1, 100, 'Bolletta', category='bills')
    _row(conn, 'lavoro@example.it', 1, 300, 'Riunione 50%_off', seen=1)
    _row(conn, 'lavoro@example.it', 2, 200, 'Allegato', attachment=1)
    _row(conn, 'other@example.it', 1, 400, 'Not mine')
    for a in ('Me@Example.it', 'lavoro@example.it'):
        conn.execute('INSERT INTO inbox_state (account, uidvalidity) VALUES (?, 1)', (a,))
    conn.commit()
    accounts = lambda: [{'user': u, 'password': 'x'} for u in ('Me@Example.it', 'lavoro@example.it', 'other@example.it', 'new@example.it')]  # noqa: E731
    svc = push.Service(db_path, str(tmp_path), accounts, SECRET)
    status, out = svc.handle('/inbox', {'accounts': ['me@example.it', 'lavoro@example.it', 'new@example.it', 'nobody@example.it']})
    assert status == 200
    # newest first, only the mailboxes asked for, named as Quokka wrote them
    assert [(m['account'], m['subject']) for m in out['messages']] == [
        ('lavoro@example.it', 'Riunione 50%_off'), ('lavoro@example.it', 'Allegato'), ('me@example.it', 'Bolletta')]
    assert out['unread'] == {'me@example.it': 1, 'lavoro@example.it': 1}
    assert out['missing'] == ['nobody@example.it'] and out['pending'] == ['new@example.it'] and out['more'] is False
    pick = lambda **kw: [m['subject'] for m in svc.handle('/inbox', {'accounts': ['me@example.it', 'lavoro@example.it'], **kw})[1]['messages']]  # noqa: E731
    assert pick(category='bills') == ['Bolletta']
    assert pick(unseen=True) == ['Allegato', 'Bolletta']
    assert pick(attachment=True) == ['Allegato']
    assert pick(text='50%') == ['Riunione 50%_off'] and pick(text='%') == ['Riunione 50%_off'] and pick(text='_o') == ['Riunione 50%_off']
    out = svc.handle('/inbox', {'accounts': ['me@example.it', 'lavoro@example.it'], 'limit': 2})[1]
    assert len(out['messages']) == 2 and out['more'] is True
    assert svc.handle('/inbox', {'accounts': ['me@example.it', 'lavoro@example.it'], 'offset': 2})[1]['messages'][0]['subject'] == 'Bolletta'
    # opened in Quokka: read in the list at once
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': [1], 'action': 'opened'}) == (200, {'ok': True})
    assert svc.handle('/inbox', {'accounts': ['me@example.it']})[1]['unread'] == {}
    assert svc.handle('/act', {'account': 'nobody@example.it', 'uids': [1], 'action': 'seen'})[0] == 404
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': ['x'], 'action': 'seen'})[0] == 400
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': [1], 'action': 'explode'})[0] == 400


class FakeImap:
    def __init__(self, caps=('IMAP4REV1', 'MOVE'), folders=()):
        self.capabilities, self.calls, self.folders = caps, [], folders

    def select(self, name, readonly=False):
        self.selected = name
        return 'OK', [b'3']

    def uid(self, *args):
        self.calls.append(args)
        return 'OK', [None]

    def list(self):
        return 'OK', list(self.folders)

    def logout(self):
        pass


def test_actions_go_to_the_mail_server_and_the_index(tmp_path):
    db_path = str(tmp_path / 's.db')
    conn = sorter.db(db_path)
    for uid in (1, 2, 3):
        _row(conn, 'me@example.it', uid, uid, f'm{uid}')
    conn.commit()
    imap = FakeImap(folders=[b'(\\HasNoChildren) "." INBOX', b'(\\HasNoChildren \\Trash) "." "Cestino"',
                             b'(\\HasNoChildren) "." Archivio'])
    svc = push.Service(db_path, str(tmp_path), lambda: [{'user': 'me@example.it', 'password': 'x'}], SECRET,
                       connect=lambda acc: imap)
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': [1, 2], 'action': 'flag'}) == (200, {'ok': True})
    assert imap.calls[-1] == ('STORE', '1,2', '+FLAGS', '(\\Flagged)')
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': [1], 'action': 'trash'}) == (200, {'ok': True})
    assert imap.calls[-1] == ('MOVE', '1', '"Cestino"')                  # the special-use folder
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': [2], 'action': 'archive'}) == (200, {'ok': True})
    assert imap.calls[-1] == ('MOVE', '2', '"Archivio"')                 # by its usual name
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': [3], 'action': 'archive', 'folder': 'Già letti'})[0] == 200
    assert imap.calls[-1] == ('MOVE', '3', '"Gi&AOA- letti"')            # Quokka's folder, in IMAP's UTF-7
    left = [tuple(r) for r in sqlite3_rows(db_path, 'SELECT uid, flagged FROM inbox')]
    assert left == []
    # no MOVE and no UIDPLUS: refused rather than expunge other people's deleted emails
    svc.connect = lambda acc: FakeImap(caps=('IMAP4REV1',), folders=[b'(\\Trash) "." Trash'])
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': [9], 'action': 'trash'})[0] == 502
    svc.connect = lambda acc: FakeImap(folders=[b'(\\HasNoChildren) "." INBOX'])
    assert svc.handle('/act', {'account': 'me@example.it', 'uids': [9], 'action': 'trash'}) == (409, {'error': 'no_folder'})


def sqlite3_rows(path, sql):
    import sqlite3
    with sqlite3.connect(path) as c:
        return c.execute(sql).fetchall()


def test_headers_first_whatever_order_the_server_answers_in():
    class Imap:
        def uid(self, *args):
            return 'OK', [(b'1 (UID 1 BODY[TEXT]<0> {5}', b'Ciao\r\n'),
                          (b' BODY[HEADER] {40}', b'From: Anna <anna@example.it>\r\nSubject: Cena\r\n\r\n'), b' FLAGS (\\Seen))']
    raw, flags = sorter.Mailbox(Imap()).fetch(1)
    mail = sorter.parse(raw)
    assert raw.startswith(b'From:') and mail.subject == 'Cena' and 'anna@example.it' in mail.sender
    assert flags == {'\\seen'}


def test_the_system_folders_of_all_the_mailboxes(tmp_path):
    """One Trash, one Sent… for all the mailboxes: found by special-use or by name, only read, kept up to date."""
    db_path = str(tmp_path / 's.db')
    conn = sorter.db(db_path)

    class Imap(FakeImap):
        def __init__(self):
            super().__init__(folders=[b'(\\HasNoChildren) "." INBOX', b'(\\HasNoChildren \\Sent) "." "Posta inviata"',
                                      b'(\\HasNoChildren) "." Cestino', b'(\\HasNoChildren) "." "Gi&AOA- letti"',
                                      b'(\\Noselect) "." Spam'])
            self.readonly = []

        def select(self, name, readonly=False):
            self.readonly.append((name, readonly))
            return 'OK', [b'1']

        def response(self, what):
            return what, [b'7']
    imap = Imap()
    found = inbox.system_folders(imap)
    assert found == {'inbox': ('INBOX', 'INBOX'), 'sent': ('"Posta inviata"', 'Posta inviata'), 'trash': ('"Cestino"', 'Cestino')}
    box = FakeBox()
    box.add('Io <me@example.it>', 'Il preventivo', 'In allegato', flags={'\\seen'})
    inbox.sync_folders(conn, 'me@example.it', imap, box, sorter.parse, classify.CATEGORIES)
    assert all(ro for _, ro in imap.readonly)                       # only examined, never selected for writing
    rows = conn.execute("SELECT role, subject FROM inbox ORDER BY role").fetchall()
    assert [tuple(r) for r in rows] == [('sent', 'Il preventivo'), ('trash', 'Il preventivo')]
    conn.execute("INSERT INTO inbox_state (account, role, uidvalidity) VALUES ('me@example.it', 'inbox', 1)")
    _row(conn, 'me@example.it', 9, 10, 'In arrivo')
    conn.commit()
    svc = push.Service(db_path, str(tmp_path), lambda: [{'user': 'me@example.it', 'password': 'x'}], SECRET, connect=lambda acc: FakeImap())
    out = svc.handle('/inbox', {'accounts': ['me@example.it'], 'role': 'trash'})[1]
    assert [(m['subject'], m['role'], m['folder']) for m in out['messages']] == [('Il preventivo', 'trash', 'Cestino')]
    assert out['unread'] == {'me@example.it': 1} and out['unread_here'] == 0     # the column counts INBOX
    assert svc.handle('/inbox', {'accounts': ['me@example.it'], 'role': 'nope'})[0] == 400
    # the actions go to the folder of that list
    assert svc.handle('/act', {'account': 'me@example.it', 'role': 'trash', 'uids': [1], 'action': 'flag'}) == (200, {'ok': True})
    assert svc.handle('/act', {'account': 'me@example.it', 'role': 'trash', 'uids': [1], 'action': 'trash'})[0] == 400
    assert conn.execute("SELECT flagged FROM inbox WHERE role = 'trash'").fetchone()[0] == 1
    # a folder that is gone leaves the index
    imap.folders = [b'(\\HasNoChildren) "." INBOX', b'(\\Sent) "." "Posta inviata"']
    inbox.sync_folders(conn, 'me@example.it', imap, box, sorter.parse, classify.CATEGORIES)
    assert [r[0] for r in conn.execute("SELECT DISTINCT role FROM inbox ORDER BY role")] == ['inbox', 'sent']


def test_an_index_of_inbox_alone_is_made_again(tmp_path):
    import sqlite3
    path = str(tmp_path / 'old.db')
    with sqlite3.connect(path) as c:
        c.execute('CREATE TABLE inbox (account TEXT, uid INTEGER, at REAL, PRIMARY KEY (account, uid))')
        c.execute("INSERT INTO inbox VALUES ('a', 1, 0)")
    conn = sorter.db(path)
    assert 'role' in {r[1] for r in conn.execute('PRAGMA table_info(inbox)')}
    assert conn.execute('SELECT COUNT(*) FROM inbox').fetchone()[0] == 0


def test_search_everywhere_on_the_mail_servers(tmp_path):
    """Every folder of every mailbox, any date: what matches all the words, with its folder and the folder's role."""
    def raw(sender, subject, text):
        return f'From: {sender}\r\nSubject: {subject}\r\nDate: Mon, 02 Mar 2020 10:00:00 +0100\r\n\r\n'.encode(), f'{text}\r\n'.encode()
    boxes = {
        'INBOX': {7: raw('Anna <anna@example.it>', 'Preventivo cucina', 'Ecco il preventivo')},
        'Cestino': {3: raw('Shop <shop@example.it>', 'Il tuo preventivo', 'Scaduto')},
        'Gi&AOA- letti': {5: raw('Ufficio <u@example.it>', 'Preventivo città', 'Vecchio preventivo del 2020')},
    }

    class Imap:
        def __init__(self):
            self.sel, self.literal, self.searches = None, None, []

        def list(self):
            return 'OK', [b'(\\HasNoChildren) "." INBOX', b'(\\HasNoChildren \\Trash) "." Cestino',
                          b'(\\HasNoChildren) "." "Gi&AOA- letti"', b'(\\Noselect \\HasChildren) "." Vecchie']

        def select(self, name, readonly=False):
            assert readonly
            self.sel = name.strip('"')
            return 'OK', [b'1']

        def uid(self, cmd, *args):
            mails = boxes[self.sel]
            if cmd == 'SEARCH':
                self.searches.append((args, self.literal))
                self.literal = None
                return 'OK', [' '.join(map(str, mails)).encode()]
            out = []
            for uid in map(int, args[0].split(',')):
                head, text = mails[uid]
                out += [(f'{uid} (UID {uid} FLAGS (\\Seen) BODY[HEADER] {{{len(head)}}}'.encode(), head),
                        (f' BODY[TEXT]<0> {{{len(text)}}}'.encode(), text), b')']
            return 'OK', out

        def logout(self):
            pass
    imap = Imap()
    rows = inbox.search_mailbox(imap, ['preventivo', 'città'], sorter.parse, classify.CATEGORIES)
    assert sorted((r['folder'], r['role'], r['subject']) for r in rows) == [
        ('Cestino', 'trash', 'Il tuo preventivo'), ('Già letti', '', 'Preventivo città'), ('INBOX', 'inbox', 'Preventivo cucina')]
    assert all(r['seen'] and r['from'] for r in rows)
    args, literal = imap.searches[0]
    assert args == ('CHARSET', 'UTF-8', 'TEXT', '"preventivo"', 'TEXT') and literal == 'città'.encode()   # accents: a literal
    assert len(imap.searches) == 3                                     # the \Noselect folder is not opened

    svc = push.Service(str(tmp_path / 's.db'), str(tmp_path), lambda: [{'user': 'me@example.it', 'password': 'x'},
                       {'user': 'down@example.it', 'password': 'x'}], SECRET,
                       connect=lambda acc: Imap() if acc['user'] == 'me@example.it' else (_ for _ in ()).throw(OSError('down')),
                       parse=sorter.parse, categories=classify.CATEGORIES)
    sorter.db(str(tmp_path / 's.db')).close()
    status, out = svc.handle('/search', {'accounts': ['Me@example.it', 'down@example.it', 'nobody@example.it'], 'text': 'preventivo'})
    assert status == 200 and out['failed'] == ['down@example.it'] and len(out['messages']) == 3
    assert {m['account'] for m in out['messages']} == {'Me@example.it'}            # named as Quokka wrote it
    assert svc.handle('/search', {'accounts': ['me@example.it'], 'text': '  '})[0] == 400
    # an action on a result: in its folder, by name
    acted = FakeImap()
    svc.connect = lambda acc: acted
    assert svc.handle('/act', {'account': 'me@example.it', 'in': 'Già letti', 'uids': [5], 'action': 'flag'}) == (200, {'ok': True})
    assert acted.selected == '"Gi&AOA- letti"' and acted.calls[-1] == ('STORE', '5', '+FLAGS', '(\\Flagged)')

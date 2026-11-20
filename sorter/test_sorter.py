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
                                                'url': 'https://mail.example?quokka-account=me#/mailbox/INBOX/m2', 'tag': 'mail:me'})]
    assert push.flush(conn, str(tmp_path), now=time.time(), send=send) == 1 - 1     # nothing left


def test_every_n_minutes_groups_them(tmp_path):
    conn = sorter.db(str(tmp_path / 's.db'))
    _subscribe(conn)
    conn.execute("INSERT INTO push_setting (account, every, last_sent) VALUES ('me', 10, ?)", (time.time(),))
    for uid, who in enumerate(['Anna', 'Luca', 'Anna'], 1):
        push.queue(conn, 'me', uid, who, 'ciao')
    sent = []
    send = lambda sub, data, d, subject: sent.append(data) or 201                            # noqa: E731
    assert push.flush(conn, str(tmp_path), now=time.time() + 5 * 60, send=send) == 0         # not yet: 10 minutes
    assert push.flush(conn, str(tmp_path), now=time.time() + 10 * 60, send=send) == 1
    assert sent[0]['title'] == '3 nuove email' and sent[0]['body'] == 'Anna, Luca'
    assert sent[0]['url'] == 'https://mail.example?quokka-account=me#/mailbox/INBOX'          # several: the inbox


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
        (200, {'watched': True, 'every': 5, 'subscribed': True})
    assert svc.handle('/every', {'account': 'me@example.it', 'every': 7})[0] == 400
    assert svc.handle('/every', {'account': 'me@example.it', 'every': 30}) == (200, {'ok': True, 'every': 30})
    assert svc.handle('/test', {'account': 'me@example.it', 'endpoint': 'https://push.example/d1'}) == (200, {'ok': True})
    assert sent[0]['title'] == 'Le notifiche funzionano' and 'Me@Example.it' in sent[0]['body']
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
                           {'title': 'Mamma', 'body': 'Cena'}, str(tmp_path), 'https://mail.example')
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

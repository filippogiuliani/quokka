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
    plain.add('Negozio <offerte@shop.example>', 'Sconto del 30%', 'Approfitta')
    assert sorter.sort_round(conn, 'other', plain) == (0, 0)


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

# Putting Quokka on the server

Two containers: **quokka** (the webmail) and **quokka-sorter** (the categories). Both images are built from the
`quokka` branch and pushed to your registry (`QUOKKA_REGISTRY`); the server runs them with `docker-compose.yml` as a
Portainer stack, behind Nginx Proxy Manager.

## 1. The images

From the development machine, with everything committed:

    sh ~/push-images.sh quokka quokka-sorter

Each image gets two tags, the commit (e.g. `3a4568e`) and `latest`. To pin a version, set `QUOKKA_TAG` to the commit.

## 2. On the server

    sudo mkdir -p /srv/quokka/data /srv/quokka/sorter
    sudo chown 1000:1000 /srv/quokka/sorter        # the sorter runs as user 1000
    openssl rand -hex 32                               # → QUOKKA_SECRET: keep a copy in the password manager

`/srv/quokka/data` needs nothing: Quokka's container gives it to its own user at every start.

## 3. The sorter's mailboxes

Nothing to do: every time someone signs in to Quokka, Quokka passes the mailboxes in their **Settings → Accounts**
(the main one and the added ones) to the sorter, which keeps the passwords encrypted with `QUOKKA_SECRET` in its data
folder and reads those mailboxes from then on (categories, notifications). A changed password reaches it at the next
sign-in.

Optionally, for a mailbox nobody opens in Quokka, an `accounts.json` with the passwords **encrypted** (plain ones are
refused), set as `SORTER_ACCOUNTS`:

    docker run --rm -it -e QUOKKA_SECRET=<the same value> <your registry>/quokka-sorter encrypt

    [
      {"user": "mario@example.it", "password": "enc:v1:…"}
    ]

    sudo chown 1000:1000 /srv/quokka/accounts.json && sudo chmod 600 /srv/quokka/accounts.json

## 4. The stack

Portainer → Stacks → Add stack → name `quokka`, paste `docker-compose.yml`, then in "Environment variables" →
"Advanced mode" paste `.env.example` filled in. Deploy.

Check: `docker logs quokka` ends with nginx/php started and `[INFO] Quokka: …` lines; `docker logs quokka-sorter`
says `sorter: categories …` and, within a minute, `N sorted` for each mailbox.

## 5. Nginx Proxy Manager

- Attach the NPM container to the `quokka` network (Portainer → Containers → npm → Connected networks → `quokka`).
- Proxy host `mail.example.it` → scheme `http`, host `quokka`, port `8888`; SSL with Let's Encrypt, Force SSL,
  HTTP/2. "Block common exploits" can stay on.
- Attachments up to 25 MB: if NPM refuses bigger uploads, in the host's Advanced tab: `client_max_body_size 30m;`

NPM sends `X-Real-IP`, which is how Settings → Security and privacy → Recent sign-ins shows where a sign-in came from.

## 6. Pocket ID (optional)

In Pocket ID: a new OIDC client "Quokka", callback `https://mail.example.it/?QuokkaSsoCallback`, confidential
(with a secret). Put its issuer, id and secret in `QUOKKA_OIDC_*` and update the stack. The first time, each person
signs in with Pocket ID and gives their mailbox's password once; after that Pocket ID is enough.

## 7. First sign-in

- SnappyMail's admin panel (`/?admin`) is off: domains, plugins and the look come from these settings at every start.
  To read logs or clear the cache, `QUOKKA_ADMIN_PANEL=On` opens it; its first password is in
  `/srv/quokka/data/_data_/_default_/admin_password.txt` (change it and turn on its two-step code), then turn it off again.
  Change it, or turn the panel off in it once everything works.
- Open a mailbox and look at the left column: Sent, Drafts, Spam, Trash and Archive should be there. If
  one is missing or wrong (the usual names, in English and Italian, are recognised automatically), Settings → Folders → System
  folders.

## 8. Notifications with Quokka closed

The sorter sends them (sorter/push.py): every person turns them on in **Settings → Notifiche**, mailbox by mailbox and
device by device, and chooses how often (as soon as an email arrives, or every 5, 10, 15, 30, 60 minutes; always one
notification per email, like Gmail). Nothing else to set up, as long as:

- the sorter and Quokka are on the same Docker network (`quokka` in this compose: Quokka reaches it at
  `QUOKKA_SORTER_URL`, by default `http://quokka-sorter:8025`; that port is never published) and share `QUOKKA_SECRET`;
- the person signed in to that mailbox in Quokka at least once since this version (that is how the sorter learns it);
- Quokka is opened over **https** (browsers allow notifications only there);
- on iPhone and iPad, Quokka is added to the Home Screen (iOS 16.4 or later) and opened from there.

The sorter makes its notification key once and keeps it in `SORTER_DATA` (`vapid_private.pem`): keep it with the
backups, or every device has to turn the notifications on again.

## 9. All mailboxes in one list

With more than one mailbox (Settings → Accounts), the column shows **Caselle**: "Tutte le caselle" puts the INBOX of
every one of them in one list, each email marked with its mailbox, and so do Sent, Drafts, Trash, Spam and Archive
(one Trash for all the mailboxes); a mailbox alone works as before. The list comes
from the sorter, which keeps an index of each INBOX and system folder (sorter/inbox.py, in `SORTER_DATA`; the system
folders are found by their special-use flag or the usual names, and only read): the same network and
`QUOKKA_SECRET` as the notifications, nothing else to set up. `INDEX_DAYS` on the sorter (default 60) says how far
back it goes. Words in the search box, with "Tutte le caselle" chosen, also search every folder of every mailbox at
any date, on the mail servers themselves (the sorter asks them all at once). Opening an email switches to its mailbox; star, read, archive and delete work from the list itself
(archive and delete use the folders chosen for that mailbox in Settings → Folders, else the server's own).

## 10. Calendar and invitations (Kooka)

For whoever signs in with Pocket ID and uses Kooka in their family in Wombat (or for everyone, with `QUOKKA_KOOKA_URL`
set), the column and the phone's bottom menu have **Calendario**: Kooka's calendar in a panel inside Quokka ("Posta" goes back to the mail). Kooka must allow it: `KOOKA_EMBED_FROM=<Quokka's address>` in Kooka's
environment (and both on the same domain, e.g. kooka.example.com and quokka.example.com, so the Kooka sign-in is kept).
An email with an invitation (.ics) shows a card: the event at the local time (`TZ`), what the person already has in
Kooka at that time, and **Accetta / Forse / Rifiuta** (saved in Kooka, and the answer goes to the organiser from the
person's own mailbox) or **Aggiungi al calendario** for a plain .ics.

## 11. Kooka and Pelican through Wombat

Quokka reaches Kooka and Pelican through Wombat, as the other apps do: no personal tokens.
1. A key: `python3 -c 'import secrets; print(secrets.token_urlsafe(32))'`.
2. In Wombat's stack: `quokka` in `WOMBAT_APPS`, `WOMBAT_APP_QUOKKA_URL=<Quokka's address>`, `WOMBAT_APP_QUOKKA_KEY=<the key>`.
   Then in Wombat → Family, give the members access to Quokka (new members get it by themselves).
3. Here: `QUOKKA_HUB_URL=<Wombat's address>` (the public one, or `http://wombat:3004` with Quokka attached to Wombat's
   network) and `QUOKKA_HUB_KEY=<the key>`.

It works for whoever signs in with **Pocket ID** (the same Pocket ID as Wombat): Quokka asks Wombat who they are and
which apps they use in which family. With address and password the family apps stay off. Kooka and Pelican must know the
family: each person signs in there once with Pocket ID.

## Updating

`sh ~/push-images.sh quokka quokka-sorter`, then Portainer → the stack → Update, with "Re-pull image". Settings,
contacts, what the sorter learned and the encrypted links are in the data folders and stay.

## Backups

`/srv/quokka/data`, `/srv/quokka/sorter`, `/srv/quokka/accounts.json`. The mail itself stays at your mail provider. Without
`QUOKKA_SECRET` the encrypted parts of a backup can't be read: that is the point, so keep the key apart.

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

`accounts.json` lists the mailboxes to sort, with their passwords **encrypted** with `QUOKKA_SECRET` (plain passwords
are refused). For each mailbox:

    docker run --rm -it -e QUOKKA_SECRET=<the same value> <your registry>/quokka-sorter encrypt

It asks for the address and the password and prints one line; put the lines in a list:

    [
      {"user": "mario@example.it", "password": "enc:v1:…"},
      {"user": "anna@example.it", "password": "enc:v1:…"}
    ]

    sudo cp accounts.json /srv/quokka/accounts.json
    sudo chown 1000:1000 /srv/quokka/accounts.json && sudo chmod 600 /srv/quokka/accounts.json

A changed mailbox password means a new line (and a restart of the sorter). Without `accounts.json` entries the
categories simply never appear; Quokka works the same.

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

- The admin panel is at `/?admin`; its first password is in `/srv/quokka/data/_data_/_default_/admin_password.txt`.
  Change it, or turn the panel off in it once everything works.
- Open a mailbox and look at the left column: Sent, Drafts, Spam, Trash and Archive should be there. If
  one is missing or wrong (the usual names, in English and Italian, are recognised automatically), Settings → Folders → System
  folders.

## 8. Notifications with Quokka closed

The sorter sends them (sorter/push.py): every person turns them on in **Settings → Notifiche**, mailbox by mailbox and
device by device, and chooses how often (as soon as an email arrives, or at most every 5, 10, 15, 30, 60 minutes:
the emails of that time become one notification). Nothing else to set up, as long as:

- the sorter and Quokka are on the same Docker network (`quokka` in this compose: Quokka reaches it at
  `QUOKKA_SORTER_URL`, by default `http://quokka-sorter:8025`; that port is never published) and share `QUOKKA_SECRET`;
- the mailbox is in the sorter's `accounts.json` (only those are read);
- Quokka is opened over **https** (browsers allow notifications only there);
- on iPhone and iPad, Quokka is added to the Home Screen (iOS 16.4 or later) and opened from there.

The sorter makes its notification key once and keeps it in `SORTER_DATA` (`vapid_private.pem`): keep it with the
backups, or every device has to turn the notifications on again.

## Updating

`sh ~/push-images.sh quokka quokka-sorter`, then Portainer → the stack → Update, with "Re-pull image". Settings,
contacts, what the sorter learned and the encrypted links are in the data folders and stay.

## Backups

`/srv/quokka/data`, `/srv/quokka/sorter`, `/srv/quokka/accounts.json`. The mail itself stays at your mail provider. Without
`QUOKKA_SECRET` the encrypted parts of a backup can't be read: that is the point, so keep the key apart.

# Quokka – how it is made

Quokka is a customised version of [SnappyMail](https://github.com/the-djmaze/snappymail) (AGPL-3.0): its own look,
categories, bills, scam warnings, two-step sign-in, phones first, and optional integrations with other self-hosted
apps (Wombat, Pelican, Kooka).
The code and its texts are in English; the interface is translated (Italian included).

**Licence.** Like SnappyMail, Quokka is under the GNU AGPL v3 (see `LICENSE`). Whoever uses it over the network can ask
for its source: this repository, branch `quokka`. The original authors' copyright notices stay as they are.

## Branches

- `master` follows upstream SnappyMail as it is (`git pull upstream master`).
- `quokka` is ours: upstream's releases are merged into it (`git merge master`).

**Version.** Quokka's version is upstream's plus one more number (`3.1.3.1`, then `3.1.3.2`…, in `package.json`):
it is part of the address of scripts and styles, so a browser never mixes an old script with new pages. Raise it
with each release of the image; after merging a new upstream version, start again at `.1` (`3.1.4.1`).

Our changes are small and kept apart, so merges stay easy:

| What | Where |
|---|---|
| The brand: the quokka in the envelope (icons, favicon, manifest) and the logo «qu●kka» (Fredoka, OFL) on the sign-in page and in the column | `branding/` (sources, `render.cjs` for the PNGs), `static/`, templates `Login.html`, `MailFolderList.html`, `SettingsMenu.html` |
| The theme | `snappymail/v/0.0.0/themes/Quokka/` (CSS, Inter fonts under the OFL) |
| A preview line under the subject in the list | `app/templates/Views/User/MailMessageList.html` (`previewParent`) |
| Quokka's texts: the `QUOKKA` section (and the category names in `MESSAGE_TAGS`), English and Italian; plugins have their own `langs/` | `app/localization/en/user.json`, `app/localization/it/user.json`, `plugins/quokka-*/langs/` |
| Categories in the left column, a chip on each row, the "Category" menu | `dev/Common/Categories.js`, `dev/View/User/MailBox/FolderList.js`, `dev/Model/Message.js` (`categoryKey`, `setCategory`), `dev/View/User/MailBox/MessageView.js`, templates `MailFolderList.html`, `MailMessageList.html`, `MailMessageView.html` |
| The layout of the family apps: the app's name and the account in the left column, a heading with the unread count and quick filters over the list, rows on several lines, the phone's bottom menu and floating Compose | templates `MailFolderList.html` (`quokka-brand`, `quokka-account`), `MailMessageList.html` (`quokka-head`, `quokka-filters`, `quokka-tabbar`); `dev/View/User/MailBox/MessageList.js` (`quickFilters`, `listTitle`…), `FolderList.js` (`accountEmail`); the theme draws the rest. SVG in templates needs explicit closing tags (`<path …></path>`): the template loader does not read `/>` |
| Search by keyword sent as an atom (RFC 3501; strict servers refuse it quoted) | `app/libraries/MailSo/Imap/SearchCriterias.php` |
| The image | `Dockerfile` (upstream's release image, fewer PHP extensions) |
| Settings at start | `.docker/quokka/setup.sh` |

## The sorter

`sorter/` is a small Python service (its own image, `quokka-sorter`). Every minute it puts a category on the
new emails of each mailbox in `accounts.json`, as an IMAP keyword: `bills`, `school`, `bank`, `shipping`,
`account` (sign-ups, password resets, codes, new sign-ins), `social`, `promotions`. Quokka shows them in the left column
and on the rows, named in the person's language. When someone changes a category in Quokka (the "Category" menu of an
email), the sorter notices at the next round: it remembers the sender (and the whole domain
after two addresses) and learns the words. Nothing leaves the server. Its rules read Italian mail (the words in `classify.py`); for another language, add words. The passwords in `accounts.json` are encrypted
with `QUOKKA_SECRET` (`sorter/secret.py`; `docker run … quokka-sorter encrypt` makes an entry), plain ones are
refused. Tests: `python -m pytest sorter`.

Each person chooses which categories to see in Settings → Categories (`plugins/quokka-extras`, kept in their
settings and sent with the page as `QuokkaHiddenCategories`). A category turned off disappears from the column, the
filters, the rows and the «Category» menu; the sorter still tags the emails, so turning it back on shows them again.

## Leaving a newsletter

An email with a `List-Unsubscribe` header shows «Unsubscribe» next to the sender. After a confirmation the
server reads the header again from the mailbox (the page never chooses where to write) and:
- **one-click** (RFC 8058: an https address and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, used by most
  big senders): sends the POST itself, to public addresses only. Done;
- otherwise, a **mailto**: the email to the list opens ready to send;
- otherwise, a **web page**: it opens, to confirm there.

## The family apps from an email

`plugins/quokka-extras/` (installed and turned on at every start by `setup.sh`). On an email in «Bills»
Quokka reads the amount, the due date and the period (`dev/Common/Bill.js`) and offers:
- **Archive in Pelican**: the PDF attached (or the whole email as .eml) goes to Pelican's import queue, category home;
- **Reminder in Kooka**: a deadline (category money) on the due date, reminded 3 days before;
- **Not a bill**: takes the category off (the sorter learns from it).

Quokka is an app of Wombat, like Kooka and Pelican, but it only asks (`plugins/quokka-sso/Hub.php`). After a sign-in
with Pocket ID it asks Wombat who the person is (`/api/service/whoami`, with the Pocket ID identity) and calls Kooka and
Pelican through Wombat (`/api/service/apps/<app>/families/<id>/…`), which holds the apps' keys and checks that the
person may use them in that family. One key in the container (`QUOKKA_HUB_KEY` = `WOMBAT_APP_QUOKKA_KEY`), nothing for
people to paste. The identity is kept in an encrypted cookie of the browser (key from `QUOKKA_SECRET`), bound to the
mailbox it opened, and forgotten at sign-out. A sign-in with address and password has none: there the family apps stay
off, because the mail server's password says nothing about who someone is in the family. Settings → Family apps shows
the link and, for whoever is in more than one family, which one to use.

## Sign in with Pocket ID

`plugins/quokka-sso/`: «Sign in with Pocket ID» under the usual form (which stays). OpenID Connect, authorization code
with PKCE and state; the user comes from the userinfo endpoint (a confidential client, server to server). The mail
server only takes a password, so the first time Quokka asks for the mailbox's address and password, checks them with
the usual sign-in, and keeps them encrypted: XChaCha20-Poly1305 with a key derived (HKDF) from `QUOKKA_SECRET`, the
Pocket ID identity as additional data, one file per identity under `_data_/_default_/quokka-sso/` (0600). Then Pocket ID
is enough: the credentials go through SnappyMail's own one-time SSO hash. `QUOKKA_SECRET` is in the environment only, so
the data volume (or its backup) alone cannot be read. Settings → Sign-in shows the link and forgets it. Its own pages (the
first link, errors) are in the interface's language too (`langs/`).

## Under a finger (phone, tablet)

`dev/Common/TouchList.js`: hold an email half a second to select it (a short vibration), then a tap selects or
deselects the others instead of opening them; a bar shows how many, with Read / Move / Archive / Delete and
"All". Swipe an email left: to the bin; right: to the archive (past a third of the row, otherwise it comes back).
SnappyMail's drag of an email onto a folder stays for the mouse.

## Security and privacy

Settings → Security and privacy (`plugins/quokka-security/`, `dev/Settings/User/Security.js`):
- **Two-step verification** for the usual sign-in: a TOTP code from an app (QR code or key), eight single-use
  backup codes shown once. The code is asked only after a right password, and only to who turned it on; Pocket ID
  and remembered devices don't ask for it. The secret is encrypted with a key from `QUOKKA_SECRET` (without it, the
  option says it is not available); turning it off or making new backup codes needs a current code. Five wrong codes
  lock the second step for ten minutes. Only "code missing" skips SnappyMail's 5-second delay on failed sign-ins.
- **Recent sign-ins**: the last 20 sign-ins (device, address, how, wrong codes). The address comes from `X-Real-IP`,
  which the proxy (NPM) sets.
- **Stolen passwords**, off unless each person turns it on: at each sign-in the password is looked up in Have I Been
  Pwned with k-anonymity (only 5 characters of its SHA-1 leave the server); if found, a warning once per session.
- **Privacy**: external images (moved here from General).

Every open email is also checked for signs of a scam (`dev/Common/Suspicious.js`): a failed DMARC (or SPF without
DKIM) from the receiving server, a sender named like a bank, Poste Italiane, a courier… writing from another domain, a name
that is another address, links showing one of those companies' addresses but leading elsewhere or to a bare IP.
A red box lists the reasons, with «Move to spam» and «I trust this sender» (remembered on that device).

## Running it

`deploy/docker-compose.yml` is the stack for Portainer, `deploy/.env.example` its settings, `deploy/README.md` the
steps (images, folders, the sorter's mailboxes, NPM, Pocket ID). The settings:

| Variable | Default | |
|---|---|---|
| `MAIL_DOMAINS` | — | the domains people sign in with, e.g. `example.it` (space separated) |
| `IMAP_HOST` / `IMAP_PORT` | — / `993` | your mail provider's IMAP server (required) |
| `SMTP_HOST` / `SMTP_PORT` | — / `465` | its SMTP server (required) |
| `IMAP_SECURITY` / `SMTP_SECURITY` | `ssl` on 993/465 | `ssl`, `starttls` or `none` |
| `QUOKKA_HUB_URL` / `QUOKKA_HUB_KEY` | — | Wombat as Quokka's server reaches it, and the key (the same as `WOMBAT_APP_QUOKKA_KEY`, with `quokka` in `WOMBAT_APPS`): Kooka and Pelican from an email, for who signs in with Pocket ID |
| `QUOKKA_KOOKA_URL` | — | optional: Kooka's calendar inside Quokka for everyone (else its address comes from Wombat, for who signs in with Pocket ID) |
| `QUOKKA_OIDC_ISSUER` / `QUOKKA_OIDC_CLIENT_ID` / `QUOKKA_OIDC_CLIENT_SECRET` | — | Pocket ID (callback: `<QUOKKA_PUBLIC_URL>/?QuokkaSsoCallback`) |
| `QUOKKA_PUBLIC_URL` | — | Quokka's address, e.g. `https://mail.example.it` |
| `QUOKKA_SECRET` | — | at least 32 random characters (`openssl rand -hex 32`); changing it forgets the Pocket ID links |
| `QUOKKA_APP_PATH` | `./` | addresses relative to the page: works at a domain's root and behind a proxy with a prefix (`/proxy/8890/`) |
| `QUOKKA_TITLE` | `Quokka` | the name on the page |
| `QUOKKA_LANGUAGE` | `it` | |
| `TZ` | — | the time zone, e.g. `Europe/Berlin` (required by the compose) |
| `QUOKKA_ENCRYPTION` | `Off` | `On` brings back OpenPGP/GnuPG/S/MIME (keys in Settings → Security, sign and encrypt when writing) |

The admin panel is at `/?admin`; its first password is in the data volume, `_data_/_default_/admin_password.txt`.

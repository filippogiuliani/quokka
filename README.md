# Quokka

My customised version of [SnappyMail](https://github.com/the-djmaze/snappymail).

The mail engine, the IMAP/SMTP code and most of the interface are SnappyMail's, by DJ Maze and its contributors (and
RainLoop before them); Quokka adds a different look and the features below. Fixes that are useful to everyone are
sent upstream.

## What it adds

- **Every mailbox in one place**: all of them in the column with their folders, "All mailboxes" (inboxes, Sent,
  Trash… of every mailbox in one list), switching mailbox without reloading, search across every mailbox and
  folder; manage the folders and identities of any mailbox, and write from any of them.
- **Categories**: a small service (the *sorter*) tags new emails as bills, school, bank, shipping, account, social,
  promotions, and learns when someone moves an email to another category. Its word lists are Italian.
- **Bills, scams, newsletters**: amount and due date read from a bill; a warning on emails that look like a scam;
  one-click unsubscribe (RFC 8058).
- **Notifications** of new emails also with the app closed (Web Push, sent by the sorter).
- **Phones**: a bottom bar, swipe to archive or delete, hold to select.
- **Sign-in**: with an OpenID Connect provider such as Pocket ID (the mailbox password is asked once and kept
  encrypted), two-step verification (TOTP) for the usual sign-in, recent sign-ins, a check of the password against
  known breaches (opt-in).
- **Integrations** (optional): a calendar inside the webmail and invitations saved there, a deadline or a document
  sent from an email to other self-hosted apps (Kooka, Pelican, through Wombat).

## Running it

Two Docker images, `quokka` and `quokka-sorter`: see [deploy/README.md](deploy/README.md) for the stack, the
settings and the steps. [QUOKKA.md](QUOKKA.md) explains how the code is organised, what changed from SnappyMail and
how each feature works.

## Branches

- `quokka`: Quokka (this README).
- `master`: SnappyMail as it is upstream. Its releases are merged into `quokka`.

## Licence

GNU Affero General Public License v3, like SnappyMail: see [LICENSE](LICENSE). Whoever uses Quokka over a network
may ask for its source: this repository. The original copyright notices are kept. Fonts: Inter and Fredoka (SIL
Open Font License).

For SnappyMail itself (documentation, support, releases): https://github.com/the-djmaze/snappymail and
https://snappymail.eu.

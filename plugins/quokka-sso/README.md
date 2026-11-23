# Sign in with an OpenID Connect provider (Pocket ID)

A SnappyMail plugin for mail servers that only take a password: people sign in with the identity provider the other apps use
(written for Pocket ID; any OpenID Connect provider with a userinfo endpoint should do), and the mailbox password is
asked only once.

## How it works

- «Sign in with Pocket ID» under the usual login form, which stays.
- OpenID Connect authorization code flow with PKCE and state, as a confidential client; the person is the `sub` of
  the userinfo endpoint (`?QuokkaSso` starts it, `?QuokkaSsoCallback` is the redirect URI).
- The first time, the plugin asks for the mailbox's address and password, checks them with SnappyMail's usual
  `LoginProcess()`, and keeps them encrypted: XChaCha20-Poly1305 (sodium), key derived with HKDF from
  `QUOKKA_SECRET`, the identity as additional data, one file per identity under `_data_/_default_/quokka-sso/` (0600).
- From then on the provider is enough: the credentials go through SnappyMail's own one-time SSO hash
  (`CreateUserSsoHash`, 10 seconds), and the sign-in is remembered for 30 days.
- Settings → Sign-in shows whether the mailbox is linked and forgets the link (after a password change, for example).

The secret lives in the environment, not in the data folder: a copy of the data volume (a backup) cannot be read
alone. Changing it forgets every link.

## Settings (environment)

| Variable | |
|---|---|
| `QUOKKA_OIDC_ISSUER` | the provider, e.g. `https://id.example.com` (discovery at `/.well-known/openid-configuration`) |
| `QUOKKA_OIDC_CLIENT_ID` / `QUOKKA_OIDC_CLIENT_SECRET` | the client; its callback is `<QUOKKA_PUBLIC_URL>/?QuokkaSsoCallback` |
| `QUOKKA_PUBLIC_URL` | the webmail's address as people open it |
| `QUOKKA_SECRET` | at least 32 random characters (`openssl rand -hex 32`) |

## Not generic yet

It relies on small changes to SnappyMail's core made in this fork (proposed upstream: `signMe` for the SSO hash,
`?Callback=` treated as `?Callback`, and letting the provider's redirect through the Sec-Fetch check), and
`Hub.php` is specific to Quokka's family apps (Wombat). A generic version for SnappyMail's `plugins/` would leave
those out and take its settings from the plugin configuration.

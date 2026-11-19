#!/bin/sh
# Quokka: settings from the environment, applied at every start (after upstream's entrypoint made the config).
#   QUOKKA_TITLE        page title (default "Quokka")
#   QUOKKA_LANGUAGE     default language (default "it")
#   MAIL_DOMAINS       the domains people sign in with, space separated (e.g. "example.it example.com")
#   IMAP_HOST / IMAP_PORT   the mail provider's IMAP server (required with MAIL_DOMAINS) / default 993 (SSL)
#   SMTP_HOST / SMTP_PORT   its SMTP server (required with MAIL_DOMAINS) / default 465 (SSL)
#   IMAP_SECURITY / SMTP_SECURITY   ssl | starttls | none (default: ssl on 993 and 465, starttls otherwise)
set -eu
DATA=/var/lib/snappymail/_data_/_default_
INI=$DATA/configs/application.ini
TITLE=${QUOKKA_TITLE:-Quokka}
LANGUAGE=${QUOKKA_LANGUAGE:-it}
IMAP_HOST=${IMAP_HOST:-}
IMAP_PORT=${IMAP_PORT:-993}
SMTP_HOST=${SMTP_HOST:-}
SMTP_PORT=${SMTP_PORT:-465}
if [ -n "${MAIL_DOMAINS:-}" ] && { [ -z "$IMAP_HOST" ] || [ -z "$SMTP_HOST" ]; }; then
    echo "[ERROR] Quokka: MAIL_DOMAINS is set but IMAP_HOST or SMTP_HOST is missing (your mail provider's servers)" >&2
    exit 1
fi

# key = value inside one [section] of application.ini
set_ini() {
    sed -i "/^\[$1\]/,/^\[/ s|^$2 = .*|$2 = $3|" "$INI"
}

echo "[INFO] Quokka: title, theme and language"
set_ini webmail title "\"$TITLE\""
set_ini webmail loading_description "\"$TITLE\""
set_ini webmail theme '"Quokka"'
# Encryption (OpenPGP, GnuPG, and with them S/MIME and Mailvelope in the settings): off for a family, QUOKKA_ENCRYPTION=On to have it
if [ "${QUOKKA_ENCRYPTION:-Off}" = "On" ]; then crypto=On; else crypto=Off; fi
set_ini security openpgp "$crypto"
set_ini security gnupg "$crypto"
# addresses relative to the page: the same image works at the root of a domain and behind a proxy that serves it
# under a prefix (https://example.it/proxy/8890/); QUOKKA_APP_PATH sets an absolute one when needed
set_ini webmail app_path "\"${QUOKKA_APP_PATH:-./}\""
set_ini webmail language "\"$LANGUAGE\""
set_ini webmail allow_user_background 'Off'
set_ini webmail allow_themes 'Off'          # one look for everyone: the family theme (no Themes page)
set_ini webmail popup_identity 'Off'      # no "set up your identity" window after the first sign-in
set_ini webmail message_read_delay 0     # an email opened is read at once (upstream waits 5 seconds)
set_ini login allow_languages_on_login 'Off'
# the list like the family apps: grouped by day ("Oggi", "Ieri"), no checkboxes (they can be turned on in Settings)
set_ini defaults mail_list_grouped 'On'
set_ini defaults view_use_checkboxes 'Off'

# 1 = SSL/TLS from the start, 2 = STARTTLS, 0 = none (only for a test server on the same machine)
security() {
    case "$2" in ssl) echo 1 ;; starttls) echo 2 ;; none) echo 0 ;;
        *) case "$1" in 993|465) echo 1 ;; *) echo 2 ;; esac ;; esac
}

first=""
for domain in ${MAIL_DOMAINS:-}; do
    [ -n "$first" ] || first=$domain
    echo "[INFO] Quokka: mail server for $domain: IMAP $IMAP_HOST:$IMAP_PORT, SMTP $SMTP_HOST:$SMTP_PORT"
    cat > "$DATA/domains/$domain.json" <<EOF
{
    "IMAP": {
        "host": "$IMAP_HOST", "port": $IMAP_PORT, "type": $(security "$IMAP_PORT" "${IMAP_SECURITY:-}"), "timeout": 300,
        "shortLogin": false, "lowerLogin": true, "sasl": ["PLAIN", "LOGIN"],
        "ssl": {"verify_peer": true, "verify_peer_name": true, "allow_self_signed": false, "SNI_enabled": true,
                "disable_compression": true, "security_level": 1},
        "disabled_capabilities": ["METADATA", "OBJECTID", "PREVIEW", "STATUS=SIZE"],
        "use_expunge_all_on_delete": false, "fast_simple_search": true, "force_select": false,
        "message_all_headers": false, "message_list_limit": 10000, "search_filter": ""
    },
    "SMTP": {
        "host": "$SMTP_HOST", "port": $SMTP_PORT, "type": $(security "$SMTP_PORT" "${SMTP_SECURITY:-}"), "timeout": 60,
        "shortLogin": false, "lowerLogin": true, "sasl": ["PLAIN", "LOGIN"],
        "ssl": {"verify_peer": true, "verify_peer_name": true, "allow_self_signed": false, "SNI_enabled": true,
                "disable_compression": true, "security_level": 1},
        "useAuth": true, "setSender": false, "usePhpMail": false
    },
    "Sieve": {"host": "", "port": 4190, "type": 0, "timeout": 10, "enabled": false},
    "whiteList": ""
}
EOF
    chown www-data:www-data "$DATA/domains/$domain.json"
done
# Signing in with just the name ("mario") means the first domain
[ -z "$first" ] || set_ini login default_domain "\"$first\""

# The family apps from an email (plugins/quokka-extras): installed and turned on at every start, so an update of the
# image brings its new version; where Pelican and Kooka are reaches PHP through php-fpm (which hides the environment)
secret=${QUOKKA_SECRET:-}
[ -z "${QUOKKA_OIDC_ISSUER:-}" ] || [ ${#secret} -ge 32 ] || echo "[WARN] Quokka: QUOKKA_SECRET must be at least 32 characters: Pocket ID sign-in is off"
# the names before 2026-11 (paTrack, doTrack) still work
QUOKKA_PELICAN_URL=${QUOKKA_PELICAN_URL:-${QUOKKA_PATRACK_URL:-}}
QUOKKA_KOOKA_URL=${QUOKKA_KOOKA_URL:-${QUOKKA_DOTRACK_URL:-}}
export QUOKKA_PELICAN_URL QUOKKA_KOOKA_URL
echo "[INFO] Quokka: plugin quokka-extras (Pelican: ${QUOKKA_PELICAN_URL:-none}, Kooka: ${QUOKKA_KOOKA_URL:-none})"
mkdir -p "$DATA/plugins"
for plugin in quokka-extras quokka-sso quokka-security; do
    rm -rf "$DATA/plugins/$plugin"
    cp -r "/quokka/plugins/$plugin" "$DATA/plugins/$plugin"
done
chown -R www-data:www-data "$DATA/plugins"
set_ini plugins enable 'On'
set_ini plugins enabled_list '"quokka-extras,quokka-sso,quokka-security"'
FPM=/usr/local/etc/php-fpm.d/php-fpm.conf
sed -i '/^env\[QUOKKA_/d' "$FPM"
# (the Pocket ID settings and QUOKKA_SECRET too: they stay in the container, never in the data volume)
for name in QUOKKA_PELICAN_URL QUOKKA_KOOKA_URL QUOKKA_OIDC_ISSUER QUOKKA_OIDC_CLIENT_ID QUOKKA_OIDC_CLIENT_SECRET \
            QUOKKA_PUBLIC_URL QUOKKA_SECRET; do
    value=$(printenv "$name" | tr -d '"' || true)
    [ -z "$value" ] || printf 'env[%s] = "%s"\n' "$name" "$value" >> "$FPM"
done

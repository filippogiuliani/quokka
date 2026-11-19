"""The mailbox passwords in accounts.json, encrypted: "enc:v1:<base64>" (AES-256-GCM, 12-byte nonce first).

The key comes from QUOKKA_SECRET (the same environment variable Quokka uses) through HKDF-SHA256, so the file alone,
or a backup of it, gives nothing away. The mailbox address is bound in as associated data: a value copied onto
another entry does not open.

    docker run --rm -it -e QUOKKA_SECRET=… <image> encrypt      asks for the address and password, prints the entry
"""
import base64
import hashlib
import hmac
import os

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

PREFIX = 'enc:v1:'


def key(secret=None):
    secret = secret if secret is not None else os.environ.get('QUOKKA_SECRET', '')
    if len(secret) < 32:
        raise ValueError('QUOKKA_SECRET is missing or shorter than 32 characters')
    prk = hmac.new(b'\0' * 32, secret.encode(), hashlib.sha256).digest()          # HKDF extract, no salt
    return hmac.new(prk, b'quokka-sorter\x01', hashlib.sha256).digest()       # HKDF expand, one block


def seal(password, user, secret=None):
    nonce = os.urandom(12)
    data = AESGCM(key(secret)).encrypt(nonce, password.encode(), user.strip().lower().encode())
    return PREFIX + base64.b64encode(nonce + data).decode()


def open_(value, user, secret=None):
    raw = base64.b64decode(value[len(PREFIX):])
    return AESGCM(key(secret)).decrypt(raw[:12], raw[12:], user.strip().lower().encode()).decode()


def is_sealed(value):
    return isinstance(value, str) and value.startswith(PREFIX)

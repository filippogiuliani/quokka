<?php
/**
 * Quokka and Wombat: the family apps (Kooka, Pelican) for whoever signed in with Pocket ID.
 *
 * Quokka is an app of Wombat like the others, but it only asks: who is this person (POST /api/service/whoami, with the
 * Pocket ID identity), and Kooka or Pelican on their behalf (/api/service/apps/<app>/families/<id>/<path>?user=<id>).
 * Wombat holds the apps' keys and checks that the person may use them in that family. No personal tokens.
 *
 * The identity is known only after a sign-in with Pocket ID: it is kept in a cookie of this browser, encrypted with a key
 * derived from QUOKKA_SECRET and bound to the mailbox it opened. A sign-in with address and password has none: the family
 * apps stay off there (the mail server's password says nothing about who the person is in the family).
 *
 * Settings (environment)
 *   QUOKKA_HUB_URL   Wombat's address as Quokka's server reaches it, e.g. https://wombat.example.it or http://wombat:3004
 *   QUOKKA_HUB_KEY   the same as WOMBAT_APP_QUOKKA_KEY in Wombat (with quokka in WOMBAT_APPS)
 */
final class QuokkaHub
{
	const COOKIE = 'quokka_id';
	const TTL = 30 * 86400;          // as long as "remember me" after a Pocket ID sign-in
	const WHO_CACHE = 120;           // seconds: a change in Wombat (access, families) shows up within this

	private static function env(string $sName) : string
	{
		return \trim((string) \getenv($sName));
	}

	public static function configured() : bool
	{
		return '' !== self::url() && 24 <= \strlen(self::env('QUOKKA_HUB_KEY')) && 32 <= \strlen(self::env('QUOKKA_SECRET'));
	}

	private static function url() : string
	{
		$sUrl = self::env('QUOKKA_HUB_URL');
		return \preg_match('#^https?://#i', $sUrl) ? \rtrim($sUrl, '/') : '';
	}

	private static function key() : string
	{
		return \hash_hkdf('sha256', self::env('QUOKKA_SECRET'), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'quokka-hub-v1');
	}

	private static function secure() : bool
	{
		return \str_starts_with(\strtolower(self::env('QUOKKA_PUBLIC_URL')), 'https:') || !empty($_SERVER['HTTPS']);
	}

	// ---------- the identity of this browser ----------

	/** After a Pocket ID sign-in: {iss, sub, email, email_verified, name} for the mailbox it opens */
	public static function remember(string $sMailbox, array $aIdentity) : void
	{
		if (32 > \strlen(self::env('QUOKKA_SECRET')) || empty($aIdentity['sub'])) {
			return;
		}
		$sPlain = \json_encode(array('m' => \strtolower(\trim($sMailbox)), 'exp' => \time() + self::TTL, 'i' => array(
			'iss' => (string) ($aIdentity['iss'] ?? ''), 'sub' => (string) $aIdentity['sub'],
			'email' => (string) ($aIdentity['email'] ?? ''), 'email_verified' => !empty($aIdentity['email_verified']),
			'name' => (string) ($aIdentity['name'] ?? ''))));
		$sNonce = \random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$sValue = \rtrim(\strtr(\base64_encode($sNonce . \sodium_crypto_secretbox($sPlain, $sNonce, self::key())), '+/', '-_'), '=');
		\setcookie(self::COOKIE, $sValue, array('expires' => \time() + self::TTL, 'path' => '/', 'httponly' => true,
			'samesite' => 'Lax', 'secure' => self::secure()));
		$_COOKIE[self::COOKIE] = $sValue;
	}

	public static function forget() : void
	{
		if (isset($_COOKIE[self::COOKIE])) {
			\setcookie(self::COOKIE, '', array('expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => self::secure()));
			unset($_COOKIE[self::COOKIE]);
		}
	}

	/** The Pocket ID identity of this browser, when it signed in to this mailbox; null otherwise */
	public static function identity(string $sMailbox) : ?array
	{
		$sRaw = \base64_decode(\strtr((string) ($_COOKIE[self::COOKIE] ?? ''), '-_', '+/'), true);
		if (!$sRaw || \strlen($sRaw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || 32 > \strlen(self::env('QUOKKA_SECRET'))) {
			return null;
		}
		$sPlain = \sodium_crypto_secretbox_open(\substr($sRaw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			\substr($sRaw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
		$aData = false === $sPlain ? null : \json_decode($sPlain, true);
		if (!\is_array($aData) || ($aData['exp'] ?? 0) < \time() || !\hash_equals((string) ($aData['m'] ?? ''), \strtolower(\trim($sMailbox)))) {
			return null;
		}
		return $aData['i'] ?? null;
	}

	// ---------- Wombat ----------

	/** [status, data]: 0 when Wombat cannot be reached */
	private static function call(string $sMethod, string $sPath, ?array $aPayload = null, int $iTimeout = 15) : array
	{
		try {
			$oRequest = \SnappyMail\HTTP\Request::factory();
			$oRequest->timeout = $iTimeout;
			$oRequest->block_private_ips = false;   // set by the admin: Wombat may well be on the Docker network
			$oResponse = $oRequest->doRequest($sMethod, self::url() . '/api/service' . $sPath,
				null === $aPayload ? null : \json_encode($aPayload), array('Authorization: Bearer ' . self::env('QUOKKA_HUB_KEY'),
					'Content-Type: application/json', 'Accept: application/json', 'User-Agent: Quokka'));
		} catch (\Throwable $oException) {
			\error_log('Quokka: Wombat: ' . $oException->getMessage());
			return array(0, array());
		}
		$aData = \json_decode((string) ($oResponse->body ?? ''), true);
		return array($oResponse ? (int) $oResponse->status : 0, \is_array($aData) ? $aData : array());
	}

	/**
	 * Who signed in to this mailbox with Pocket ID, as Wombat sees them:
	 * {state: ok, user {hub_id, name, email}, families [{id, name, apps [ids I may use], urls {app: address}}]}
	 * or {state: off | no_pocket_id | unknown | no_family | down}.
	 */
	public static function who(string $sMailbox, bool $bFresh = false) : array
	{
		if (!self::configured()) {
			return array('state' => 'off');
		}
		$aId = self::identity($sMailbox);
		if (!$aId) {
			return array('state' => 'no_pocket_id');
		}
		$oCacher = \RainLoop\Api::Actions()->Cacher();
		$sKey = 'quokka-hub/who/' . \hash('sha256', $aId['iss'] . '|' . $aId['sub']);
		$aCached = $bFresh ? null : \json_decode((string) $oCacher->Get($sKey), true);
		if (\is_array($aCached) && ($aCached['at'] ?? 0) > \time() - self::WHO_CACHE) {
			return $aCached['who'];
		}
		[$iStatus, $aData] = self::call('POST', '/whoami', array('issuer' => $aId['iss'], 'subject' => $aId['sub'],
			'email' => $aId['email'], 'email_verified' => $aId['email_verified'], 'name' => $aId['name']));
		if (200 !== $iStatus) {
			\error_log("Quokka: Wombat whoami answered {$iStatus}");
			// Wombat down: what it said last, if anything
			return \is_array($aCached) ? $aCached['who'] : array('state' => 'down');
		}
		if (empty($aData['known'])) {
			$aWho = array('state' => 'unknown');
		} else {
			$iMe = (int) $aData['user']['hub_id'];
			$aFamilies = array();
			foreach ($aData['families'] ?? array() as $aSnap) {
				$aApps = array();
				foreach ($aSnap['members'] ?? array() as $aMember) {
					if ((int) ($aMember['hub_id'] ?? 0) === $iMe) {
						$aApps = \array_values(\array_intersect((array) ($aMember['apps'] ?? array()), array('kooka', 'pelican')));
					}
				}
				$aUrls = array();
				foreach ($aSnap['apps'] ?? array() as $aApp) {
					if (\in_array($aApp['id'] ?? '', array('kooka', 'pelican'), true) && \preg_match('#^https?://#i', (string) ($aApp['url'] ?? ''))) {
						$aUrls[$aApp['id']] = \rtrim($aApp['url'], '/');
					}
				}
				$aFamilies[] = array('id' => (int) $aSnap['hub_id'], 'name' => (string) $aSnap['name'], 'apps' => $aApps, 'urls' => $aUrls);
			}
			$aWho = $aFamilies
				? array('state' => 'ok', 'user' => array('hub_id' => $iMe, 'name' => (string) ($aData['user']['name'] ?? ''),
					'email' => (string) ($aData['user']['email'] ?? '')), 'families' => $aFamilies)
				: array('state' => 'no_family');
		}
		$oCacher->Set($sKey, \json_encode(array('at' => \time(), 'who' => $aWho)));
		return $aWho;
	}

	/** The family to use: the one chosen (when still among theirs), else the first */
	public static function family(array $aWho, int $iChosen = 0) : ?array
	{
		foreach ($aWho['families'] ?? array() as $aFamily) {
			if ($aFamily['id'] === $iChosen) {
				return $aFamily;
			}
		}
		return $aWho['families'][0] ?? null;
	}

	/**
	 * Ask Kooka or Pelican through Wombat, as this person in this family: [status, data].
	 * status 0: Wombat or the app cannot be reached; 403: no access; 404: the app does not know the family yet.
	 */
	public static function ask(array $aWho, array $aFamily, string $sApp, string $sMethod, string $sPath, array $aQuery = array(),
		?array $aPayload = null, int $iTimeout = 30) : array
	{
		$sQuery = \http_build_query(array('user' => $aWho['user']['hub_id']) + $aQuery);
		[$iStatus, $aData] = self::call($sMethod, "/apps/{$sApp}/families/{$aFamily['id']}{$sPath}?{$sQuery}", $aPayload, $iTimeout);
		return array(502 === $iStatus ? 0 : $iStatus, $aData);
	}
}

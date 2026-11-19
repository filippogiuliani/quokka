<?php
/**
 * Quokka – security: what Settings → Security and privacy shows besides SnappyMail's own options.
 *
 *  - Two-step verification (TOTP, RFC 6238) for the usual sign-in: after the password, a code from an app
 *    (Aegis, Google Authenticator…), or one of eight single-use backup codes. Pocket ID sign-ins don't ask
 *    for it (Pocket ID is already a second factor), nor does a device that was remembered ("Ricordami").
 *    The secret is kept encrypted (XChaCha20-Poly1305, key from QUOKKA_SECRET), the backup codes as hashes.
 *  - The last sign-ins: when, from which device and address, how, and the codes that were wrong.
 *  - Stolen passwords (off unless the person turns it on): at each sign-in the password is looked up in
 *    Have I Been Pwned's list with k-anonymity: only the first 5 characters of its SHA-1 leave the server.
 */
class QuokkaSecurityPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Quokka security',
		AUTHOR   = 'Quokka',
		VERSION  = '1.0',
		RELEASE  = '2026-11-19',
		REQUIRED = '2.36.0',
		CATEGORY = 'Security',
		LICENSE  = 'AGPL-3.0',
		DESCRIPTION = 'Two-step verification, last sign-ins, stolen password check.';

	const STORE = 'quokka_security';
	const KEEP_LOGINS = 20;
	const MAX_WRONG = 5;           // wrong codes in a row…
	const LOCK_SECONDS = 600;      // …lock the second step for ten minutes
	const PERIOD = 30;

	public function Init() : void
	{
		$this->UseLangs(true);      // langs/*.json: QUOKKA_SECURITY/… (sign-in); the settings page's texts are QUOKKA/… in the core
		$this->addJs('js/QuokkaSecurity.js');
		$this->addHook('login.success', 'OnLogin');
		$this->addHook('filter.app-data', 'FilterAppData');
		foreach (array('Get', 'TotpStart', 'TotpEnable', 'TotpDisable', 'TotpBackup', 'Pwned') as $sName) {
			$this->addJsonHook('QuokkaSecurity' . $sName, 'Json' . $sName);
		}
	}

	// ---------- storage: one JSON per account ----------

	private function load($oAccount) : array
	{
		$sData = \RainLoop\Api::Actions()->StorageProvider()->Get($oAccount,
			\RainLoop\Providers\Storage\Enumerations\StorageType::CONFIG, self::STORE);
		$aData = $sData ? \json_decode($sData, true) : null;
		return \is_array($aData) ? $aData : array();
	}

	private function store($oAccount, array $aData) : bool
	{
		return \RainLoop\Api::Actions()->StorageProvider()->Put($oAccount,
			\RainLoop\Providers\Storage\Enumerations\StorageType::CONFIG, self::STORE, \json_encode($aData));
	}

	private static function key() : ?string
	{
		$sSecret = (string) \getenv('QUOKKA_SECRET');
		return 32 <= \strlen($sSecret) ? \hash_hkdf('sha256', $sSecret, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'quokka-2fa') : null;
	}

	private static function seal(string $sPlain, string $sEmail) : string
	{
		$sNonce = \random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		return \base64_encode($sNonce . \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($sPlain, $sEmail, $sNonce, self::key()));
	}

	private static function open(string $sSealed, string $sEmail) : ?string
	{
		$sRaw = \base64_decode($sSealed, true);
		$iNonce = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if (!self::key() || false === $sRaw || \strlen($sRaw) <= $iNonce) {
			return null;
		}
		$sPlain = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(\substr($sRaw, $iNonce), $sEmail, \substr($sRaw, 0, $iNonce), self::key());
		return false === $sPlain ? null : $sPlain;
	}

	// ---------- TOTP ----------

	private static function base32(string $sBytes) : string
	{
		$sAlphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$sBits = '';
		foreach (\str_split($sBytes) as $c) {
			$sBits .= \str_pad(\decbin(\ord($c)), 8, '0', STR_PAD_LEFT);
		}
		$sOut = '';
		foreach (\str_split($sBits, 5) as $sChunk) {
			$sOut .= $sAlphabet[\bindec(\str_pad($sChunk, 5, '0'))];
		}
		return $sOut;
	}

	private static function hotp(string $sKey, int $iCounter) : string
	{
		$sHash = \hash_hmac('sha1', \pack('J', $iCounter), $sKey, true);
		$iOffset = \ord($sHash[19]) & 0x0f;
		$iValue = ((\ord($sHash[$iOffset]) & 0x7f) << 24) | (\ord($sHash[$iOffset + 1]) << 16)
			| (\ord($sHash[$iOffset + 2]) << 8) | \ord($sHash[$iOffset + 3]);
		return \str_pad((string) ($iValue % 1000000), 6, '0', STR_PAD_LEFT);
	}

	/** The time step the code belongs to (now, or one step either side for clocks a little off), or null */
	private static function verify(string $sKey, string $sCode, int $iLastStep) : ?int
	{
		if (!\preg_match('/^\d{6}$/D', $sCode)) {
			return null;
		}
		$iNow = \intdiv(\time(), self::PERIOD);
		foreach (array($iNow, $iNow - 1, $iNow + 1) as $iStep) {
			if ($iStep > $iLastStep && \hash_equals(self::hotp($sKey, $iStep), $sCode)) {
				return $iStep;          // a code is good once: the same one can't be used twice
			}
		}
		return null;
	}

	private static function backupCodes() : array
	{
		$aCodes = array();
		for ($i = 0; $i < 8; ++$i) {
			$aCodes[] = \sprintf('%04d-%04d', \random_int(0, 9999), \random_int(0, 9999));
		}
		return $aCodes;
	}

	/** The current code, or a backup code (spent when used). Updates $aData; true when it is right. */
	private function checkCode(array &$aData, string $sEmail, string $sCode) : bool
	{
		$sKey = isset($aData['totp']['secret']) ? self::open($aData['totp']['secret'], $sEmail) : null;
		if (!$sKey) {
			return false;
		}
		$sCode = \preg_replace('/\s+/', '', $sCode);
		$iStep = self::verify($sKey, $sCode, (int) ($aData['totp']['last'] ?? 0));
		if (null !== $iStep) {
			$aData['totp']['last'] = $iStep;
			return true;
		}
		$sDigits = \preg_replace('/\D/', '', $sCode);
		if (8 === \strlen($sDigits)) {
			foreach ($aData['totp']['backup'] ?? array() as $i => $sHash) {
				if (\password_verify($sDigits, $sHash)) {
					unset($aData['totp']['backup'][$i]);
					$aData['totp']['backup'] = \array_values($aData['totp']['backup']);
					return true;
				}
			}
		}
		return false;
	}

	private static function enabled(array $aData) : bool
	{
		return !empty($aData['totp']['enabled']) && !empty($aData['totp']['secret']);
	}

	// ---------- sign-in ----------

	/** Pocket ID: the one-time SSO hash, or the page that links a mailbox to it */
	private static function viaPocketId() : bool
	{
		return (bool) \preg_match('/^(Sso|QuokkaSsoLink)(&|$)/', (string) ($_SERVER['QUERY_STRING'] ?? ''));
	}

	private static function clientIp() : string
	{
		$sIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
		// the image's nginx passes X-Real-IP (set by NPM) as REMOTE_ADDR; without it, or when it is the proxy's own
		// address, the client is the last public hop of X-Forwarded-For
		if (('' === $sIp || self::isPrivate($sIp)) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			foreach (\array_reverse(\array_map('trim', \explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']))) as $sHop) {
				if (\filter_var($sHop, FILTER_VALIDATE_IP)) {
					$sIp = $sHop;
					if (!self::isPrivate($sHop)) {
						break;
					}
				}
			}
		}
		return $sIp;
	}

	private static function isPrivate(string $sIp) : bool
	{
		return '' !== $sIp && false === \filter_var($sIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
	}

	private function remember(array &$aData, string $sHow, bool $bOk) : void
	{
		\array_unshift($aData['logins'], array(
			'time' => \time(),
			'ip' => self::clientIp(),
			'ua' => \mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
			'how' => $sHow,
			'ok' => $bOk
		));
		$aData['logins'] = \array_slice($aData['logins'], 0, self::KEEP_LOGINS);
	}

	public function OnLogin($oAccount) : void
	{
		$aData = $this->load($oAccount);
		$aData['logins'] = $aData['logins'] ?? array();
		$bPocketId = self::viaPocketId();

		if (!$bPocketId && self::enabled($aData)) {
			$sCode = \trim((string) $this->jsonParam('quokka_code', ''));
			if ('' === $sCode) {
				throw new \RainLoop\Exceptions\ClientException(\RainLoop\Notifications::AuthError, null, 'QUOKKA_2FA_REQUIRED');
			}
			$iLocked = (int) ($aData['totp']['lockedUntil'] ?? 0);
			if ($iLocked > \time()) {
				throw new \RainLoop\Exceptions\ClientException(\RainLoop\Notifications::AuthError, null, 'QUOKKA_2FA_LOCKED');
			}
			if (!$this->checkCode($aData, $oAccount->Email(), $sCode)) {
				$aData['totp']['wrong'] = (int) ($aData['totp']['wrong'] ?? 0) + 1;
				if (self::MAX_WRONG <= $aData['totp']['wrong']) {
					$aData['totp']['lockedUntil'] = \time() + self::LOCK_SECONDS;
					$aData['totp']['wrong'] = 0;
				}
				$this->remember($aData, 'password', false);
				$this->store($oAccount, $aData);
				throw new \RainLoop\Exceptions\ClientException(\RainLoop\Notifications::AuthError, null, 'QUOKKA_2FA_WRONG');
			}
			$aData['totp']['wrong'] = 0;
		}

		$this->remember($aData, $bPocketId ? 'pocketid' : (self::enabled($aData) ? 'password+code' : 'password'), true);
		if (!empty($aData['pwnedCheck'])) {
			$this->checkPwned($aData, $oAccount);
		}
		$this->store($oAccount, $aData);
	}

	private function checkPwned(array &$aData, $oAccount) : void
	{
		try {
			$oPass = $oAccount->ImapPass();
			$iCount = \SnappyMail\Hibp::password($oPass instanceof \SnappyMail\SensitiveString ? $oPass : new \SnappyMail\SensitiveString((string) $oPass));
			$aData['pwned'] = array('count' => $iCount, 'time' => \time());
		} catch (\Throwable $oException) {
			$this->Manager()->Actions()->Logger()->WriteException($oException);
		}
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if ($bAdmin || !\is_array($aResult) || empty($aResult['Auth'])) {
			return;
		}
		try {
			$aData = $this->load(\RainLoop\Api::Actions()->getMainAccountFromToken());
			$aResult['QuokkaPwned'] = !empty($aData['pwnedCheck']) ? (int) ($aData['pwned']['count'] ?? 0) : 0;
		} catch (\Throwable $oException) {
		}
	}

	// ---------- Settings → Security and privacy ----------

	private function account()
	{
		return \RainLoop\Api::Actions()->getMainAccountFromToken();
	}

	private function state($oAccount, array $aData) : array
	{
		$sCurrentUa = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
		$sCurrentIp = self::clientIp();
		return array(
			'available' => null !== self::key(),
			'totp' => self::enabled($aData),
			'totpSince' => (int) ($aData['totp']['since'] ?? 0),
			'backupLeft' => \count($aData['totp']['backup'] ?? array()),
			'pwnedCheck' => !empty($aData['pwnedCheck']),
			'pwned' => $aData['pwned'] ?? null,
			'logins' => \array_map(fn($a) => $a + array('here' => $a['ua'] === $sCurrentUa && $a['ip'] === $sCurrentIp,
				'local' => self::isPrivate($a['ip'] ?? '')), $aData['logins'] ?? array())
		);
	}

	public function JsonGet()
	{
		$oAccount = $this->account();
		return $this->jsonResponse(__FUNCTION__, $this->state($oAccount, $this->load($oAccount)));
	}

	/** A new secret, waiting for the first code: {secret, uri, qr (SVG)} */
	public function JsonTotpStart()
	{
		if (!self::key()) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_NO_SECRET'));
		}
		$oAccount = $this->account();
		$aData = $this->load($oAccount);
		if (self::enabled($aData)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_ALREADY_ON'));
		}
		$sKey = \random_bytes(20);
		$aData['pending'] = self::seal($sKey, $oAccount->Email());
		$this->store($oAccount, $aData);
		$sSecret = self::base32($sKey);
		$sIssuer = \rawurlencode((string) (\RainLoop\Api::Config()->Get('webmail', 'title', '') ?: 'Quokka'));
		$sUri = "otpauth://totp/{$sIssuer}:" . \rawurlencode($oAccount->Email()) . "?secret={$sSecret}&issuer={$sIssuer}&digits=6&period=30";
		return $this->jsonResponse(__FUNCTION__, array(
			'secret' => \trim(\chunk_split($sSecret, 4, ' ')),
			'uri' => $sUri,
			'qr' => self::qrSvg($sUri)
		));
	}

	private static function qrSvg(string $sData) : string
	{
		$oQr = \SnappyMail\QRCode::getMinimumQRCode($sData, \SnappyMail\QRCode::ERROR_CORRECT_LEVEL_M);
		$n = $oQr->getModuleCount();
		$sPath = '';
		for ($r = 0; $r < $n; ++$r) {
			for ($c = 0; $c < $n; ++$c) {
				if ($oQr->isDark($r, $c)) {
					$sPath .= 'M' . ($c + 4) . ' ' . ($r + 4) . 'h1v1h-1z';
				}
			}
		}
		$s = $n + 8;
		return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$s} {$s}\" shape-rendering=\"crispEdges\">"
			. "<rect width=\"{$s}\" height=\"{$s}\" fill=\"#fff\"/><path d=\"{$sPath}\" fill=\"#000\"/></svg>";
	}

	/** {code}: the first code from the app turns it on; the backup codes come back, once */
	public function JsonTotpEnable()
	{
		$oAccount = $this->account();
		$aData = $this->load($oAccount);
		$sKey = isset($aData['pending']) ? self::open($aData['pending'], $oAccount->Email()) : null;
		if (!$sKey) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_START_AGAIN'));
		}
		$iStep = self::verify($sKey, \preg_replace('/\s+/', '', (string) $this->jsonParam('code', '')), 0);
		if (null === $iStep) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_CODE_SETUP'));
		}
		$aCodes = self::backupCodes();
		$aData['totp'] = array(
			'enabled' => true,
			'secret' => $aData['pending'],
			'since' => \time(),
			'last' => $iStep,
			'backup' => \array_map(fn($s) => \password_hash(\str_replace('-', '', $s), PASSWORD_DEFAULT), $aCodes)
		);
		unset($aData['pending']);
		$this->store($oAccount, $aData);
		return $this->jsonResponse(__FUNCTION__, array('ok' => true, 'backup' => $aCodes) + $this->state($oAccount, $aData));
	}

	/** {code}: turning it off needs a current code (or a backup one), not just an open session */
	public function JsonTotpDisable()
	{
		$oAccount = $this->account();
		$aData = $this->load($oAccount);
		if (!$this->checkCode($aData, $oAccount->Email(), (string) $this->jsonParam('code', ''))) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_CODE'));
		}
		unset($aData['totp'], $aData['pending']);
		$this->store($oAccount, $aData);
		return $this->jsonResponse(__FUNCTION__, array('ok' => true) + $this->state($oAccount, $aData));
	}

	/** {code}: eight new backup codes (the old ones stop working) */
	public function JsonTotpBackup()
	{
		$oAccount = $this->account();
		$aData = $this->load($oAccount);
		if (!$this->checkCode($aData, $oAccount->Email(), (string) $this->jsonParam('code', ''))) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_CODE'));
		}
		$aCodes = self::backupCodes();
		$aData['totp']['backup'] = \array_map(fn($s) => \password_hash(\str_replace('-', '', $s), PASSWORD_DEFAULT), $aCodes);
		$this->store($oAccount, $aData);
		return $this->jsonResponse(__FUNCTION__, array('ok' => true, 'backup' => $aCodes) + $this->state($oAccount, $aData));
	}

	/** {enabled: 0|1}: the stolen-password check on or off; turning it on checks right away */
	public function JsonPwned()
	{
		$oAccount = $this->account();
		$aData = $this->load($oAccount);
		$aData['pwnedCheck'] = !empty($this->jsonParam('enabled', 0));
		if ($aData['pwnedCheck']) {
			$this->checkPwned($aData, $oAccount);
		} else {
			unset($aData['pwned']);
		}
		$this->store($oAccount, $aData);
		return $this->jsonResponse(__FUNCTION__, $this->state($oAccount, $aData));
	}
}

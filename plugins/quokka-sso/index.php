<?php
/**
 * Quokka – sign in with Pocket ID (OpenID Connect), next to the usual sign-in with address and password.
 *
 * The mail server only takes a password, so the first time someone comes from Pocket ID they give their
 * mailbox's address and password once: Quokka checks them on the mail server and keeps them encrypted
 * (XChaCha20-Poly1305, a key derived from QUOKKA_SECRET, bound to the Pocket ID identity). From then on Pocket ID is
 * enough: the plugin hands the credentials to SnappyMail's own one-time SSO hash (10 seconds, one use).
 * The secret lives in the environment, not in the data volume: a copy of the volume (a backup) cannot be read alone.
 *
 * Settings (environment)
 *   QUOKKA_OIDC_ISSUER         e.g. https://id.example.it (Pocket ID)
 *   QUOKKA_OIDC_CLIENT_ID / QUOKKA_OIDC_CLIENT_SECRET
 *   QUOKKA_PUBLIC_URL          Quokka's address as people open it, e.g. https://mail.example.it
 *                             (Pocket ID's callback: <QUOKKA_PUBLIC_URL>/?QuokkaSsoCallback)
 *   QUOKKA_SECRET              at least 32 random characters (openssl rand -hex 32); changing it forgets every link
 */
class QuokkaSsoPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Quokka Pocket ID',
		AUTHOR   = 'Quokka',
		VERSION  = '1.0',
		RELEASE  = '2026-11-18',
		REQUIRED = '2.36.0',
		CATEGORY = 'Login',
		LICENSE  = 'AGPL-3.0',
		DESCRIPTION = 'Sign in with Pocket ID (OpenID Connect); mailbox credentials kept encrypted on the server.';

	const STATE_TTL = 600;

	public function Init() : void
	{
		$this->UseLangs(true);      // langs/*.json: QUOKKA_SSO/… for the page and for the server's own pages
		$this->addJs('js/QuokkaSso.js');
		$this->addTemplate('templates/QuokkaSsoSettingsTab.html');
		$this->addHook('filter.app-data', 'FilterAppData');
		$this->addPartHook('QuokkaSso', 'Start');
		$this->addPartHook('QuokkaSsoCallback', 'Callback');
		$this->addPartHook('QuokkaSsoLink', 'Link');
		$this->addJsonHook('QuokkaSsoStatus', 'JsonStatus');
		$this->addJsonHook('QuokkaSsoUnlink', 'JsonUnlink');
	}

	// ---------- settings ----------

	private static function env(string $sName) : string
	{
		return \trim((string) \getenv($sName));
	}

	private static function configured() : bool
	{
		return self::env('QUOKKA_OIDC_ISSUER') && self::env('QUOKKA_OIDC_CLIENT_ID') && self::env('QUOKKA_OIDC_CLIENT_SECRET')
			&& self::env('QUOKKA_PUBLIC_URL') && 32 <= \strlen(self::env('QUOKKA_SECRET'));
	}

	private static function publicUrl() : string
	{
		return \rtrim(self::env('QUOKKA_PUBLIC_URL'), '/') . '/';
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult)) {
			$aResult['QuokkaSso'] = self::configured();
		}
	}

	// ---------- the encrypted links: one file per Pocket ID identity ----------

	private static function dir() : string
	{
		$sDir = APP_PRIVATE_DATA . 'quokka-sso/';
		\is_dir($sDir) || \mkdir($sDir, 0700, true);
		return $sDir;
	}

	private static function file(string $sSub) : string
	{
		return self::dir() . \hash('sha256', 'quokka-sso|' . $sSub) . '.json';
	}

	private static function key() : string
	{
		return \hash_hkdf('sha256', self::env('QUOKKA_SECRET'), SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, 'quokka-sso-v1');
	}

	private static function save(string $sSub, string $sEmail, string $sPassword) : bool
	{
		$sNonce = \random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		$sPlain = \json_encode(array('email' => $sEmail, 'password' => $sPassword));
		$sCipher = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($sPlain, 'quokka-sso|' . $sSub, $sNonce, self::key());
		\sodium_memzero($sPlain);
		$sFile = self::file($sSub);
		$bOk = false !== \file_put_contents($sFile . '.tmp', \json_encode(array(
			'v' => 1, 'nonce' => \base64_encode($sNonce), 'data' => \base64_encode($sCipher), 'at' => \time(),
			// to find (and forget) the links of a mailbox from its settings, without opening any
			'owner' => self::owner($sEmail)
		)), LOCK_EX);
		return $bOk && \chmod($sFile . '.tmp', 0600) && \rename($sFile . '.tmp', $sFile);
	}

	private static function owner(string $sEmail) : string
	{
		return \hash('sha256', 'owner|' . \strtolower(\trim($sEmail)));
	}

	/** The files of the links that open this mailbox */
	private static function linksOf(string $sEmail) : array
	{
		$aFiles = array();
		foreach (\glob(self::dir() . '*.json') ?: array() as $sFile) {
			$aStored = \json_decode((string) \file_get_contents($sFile), true);
			if (\is_array($aStored) && isset($aStored['owner']) && \hash_equals($aStored['owner'], self::owner($sEmail))) {
				$aFiles[] = $sFile;
			}
		}
		return $aFiles;
	}

	/** ['email' => …, 'password' => …] or null (none, or it cannot be read: another secret) */
	private static function load(string $sSub) : ?array
	{
		$aStored = \is_readable(self::file($sSub)) ? \json_decode((string) \file_get_contents(self::file($sSub)), true) : null;
		if (!\is_array($aStored) || 1 !== ($aStored['v'] ?? 0)) {
			return null;
		}
		$sPlain = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(\base64_decode($aStored['data']),
			'quokka-sso|' . $sSub, \base64_decode($aStored['nonce']), self::key());
		$aData = false === $sPlain ? null : \json_decode($sPlain, true);
		return \is_array($aData) && !empty($aData['email']) && isset($aData['password']) ? $aData : null;
	}

	// ---------- OpenID Connect ----------

	private function http(string $sMethod, string $sUrl, ?string $sBody = null, array $aHeaders = array()) : ?array
	{
		$oRequest = \SnappyMail\HTTP\Request::factory();
		$oRequest->timeout = 15;
		$oRequest->block_private_ips = false;           // Pocket ID may well be on the Docker network
		$oResponse = $oRequest->doRequest($sMethod, $sUrl, $sBody, \array_merge(array('Accept: application/json'), $aHeaders));
		if (!$oResponse || 200 > $oResponse->status || 300 <= $oResponse->status) {
			\error_log("Quokka SSO: {$sMethod} {$sUrl} → " . ($oResponse ? $oResponse->status : 'no answer'));
			return null;
		}
		$aData = \json_decode((string) $oResponse->body, true);
		return \is_array($aData) ? $aData : null;
	}

	private function discovery() : ?array
	{
		$oCacher = \RainLoop\Api::Actions()->Cacher();
		$sKey = 'quokka-sso/discovery/' . \sha1(self::env('QUOKKA_OIDC_ISSUER'));
		$sCached = $oCacher->Get($sKey);
		if ($sCached && ($aData = \json_decode($sCached, true))) {
			return $aData;
		}
		$aData = $this->http('GET', \rtrim(self::env('QUOKKA_OIDC_ISSUER'), '/') . '/.well-known/openid-configuration');
		if ($aData && !empty($aData['authorization_endpoint']) && !empty($aData['token_endpoint']) && !empty($aData['userinfo_endpoint'])) {
			$oCacher->Set($sKey, \json_encode($aData));
			return $aData;
		}
		return null;
	}

	private static function b64url(string $s) : string
	{
		return \rtrim(\strtr(\base64_encode($s), '+/', '-_'), '=');
	}

	private static function cookieOptions() : array
	{
		return array('expires' => \time() + self::STATE_TTL, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
			'secure' => \str_starts_with(self::publicUrl(), 'https:'));
	}

	/** ?QuokkaSso: off to Pocket ID */
	public function Start() : bool
	{
		if (!self::configured() || !($aDiscovery = $this->discovery())) {
			return $this->page($this->t('PAGE_UNAVAILABLE'), '<p>' . $this->t('PAGE_UNAVAILABLE_TEXT') . '</p>');
		}
		$sState = self::b64url(\random_bytes(24));
		$sVerifier = self::b64url(\random_bytes(48));
		\RainLoop\Api::Actions()->Cacher()->Set('quokka-sso/state/' . $sState, \json_encode(array('verifier' => $sVerifier, 'time' => \time())));
		\setcookie('quokka_sso', $sState, self::cookieOptions());
		\header('Location: ' . $aDiscovery['authorization_endpoint'] . '?' . \http_build_query(array(
			'response_type' => 'code',
			'client_id' => self::env('QUOKKA_OIDC_CLIENT_ID'),
			'redirect_uri' => self::publicUrl() . '?QuokkaSsoCallback',
			'scope' => 'openid email profile',
			'state' => $sState,
			'code_challenge' => self::b64url(\hash('sha256', $sVerifier, true)),
			'code_challenge_method' => 'S256'
		)));
		return true;
	}

	/** ?QuokkaSsoCallback&code=…&state=…: who it is; in at once if the mailbox is linked, else the form to link it */
	public function Callback() : bool
	{
		$sState = (string) ($_GET['state'] ?? '');
		$oCacher = \RainLoop\Api::Actions()->Cacher();
		$sSaved = $sState ? $oCacher->Get('quokka-sso/state/' . $sState, true) : null;
		$aSaved = $sSaved ? \json_decode($sSaved, true) : null;
		\setcookie('quokka_sso', '', array('expires' => 1) + self::cookieOptions());
		if (!$aSaved || !\hash_equals((string) ($_COOKIE['quokka_sso'] ?? ''), $sState) || \time() - $aSaved['time'] > self::STATE_TTL) {
			return $this->page($this->t('PAGE_EXPIRED'), '<p>' . $this->t('PAGE_EXPIRED_TEXT') . '</p><p><a class="btn" href="./?QuokkaSso">' . $this->t('TRY_AGAIN') . '</a></p>');
		}
		if (!empty($_GET['error']) || empty($_GET['code']) || !($aDiscovery = $this->discovery())) {
			return $this->page($this->t('PAGE_FAILED'), '<p>' . $this->t('PAGE_NOT_CONFIRMED') . '</p><p><a class="btn" href="./">' . $this->t('BACK_TO_SIGN_IN') . '</a></p>');
		}
		$aToken = $this->http('POST', $aDiscovery['token_endpoint'], \http_build_query(array(
			'grant_type' => 'authorization_code',
			'code' => (string) $_GET['code'],
			'redirect_uri' => self::publicUrl() . '?QuokkaSsoCallback',
			'client_id' => self::env('QUOKKA_OIDC_CLIENT_ID'),
			'client_secret' => self::env('QUOKKA_OIDC_CLIENT_SECRET'),
			'code_verifier' => $aSaved['verifier']
		)), array('Content-Type: application/x-www-form-urlencoded'));
		$aUser = !empty($aToken['access_token'])
			? $this->http('GET', $aDiscovery['userinfo_endpoint'], null, array('Authorization: Bearer ' . $aToken['access_token']))
			: null;
		$sSub = (string) ($aUser['sub'] ?? '');
		if ('' === $sSub) {
			return $this->page($this->t('PAGE_FAILED'), '<p>' . $this->t('PAGE_NO_IDENTITY') . '</p><p><a class="btn" href="./">' . $this->t('BACK_TO_SIGN_IN') . '</a></p>');
		}

		$aLinked = self::load($sSub);
		if ($aLinked) {
			return $this->enter($aLinked['email'], $aLinked['password']);
		}
		// Not linked yet: the form, with a one-time ticket that stands for this identity for 10 minutes
		$sTicket = self::b64url(\random_bytes(24));
		$oCacher->Set('quokka-sso/ticket/' . $sTicket, \json_encode(array('sub' => $sSub, 'time' => \time())));
		return $this->linkForm($sTicket, (string) ($aUser['email'] ?? ''), (string) ($aUser['name'] ?? ''), '');
	}

	/** POST ?QuokkaSsoLink: check the mailbox on the mail server, keep it encrypted, sign in */
	public function Link() : bool
	{
		$oCacher = \RainLoop\Api::Actions()->Cacher();
		$sTicket = (string) ($_POST['ticket'] ?? '');
		$sSaved = $sTicket ? $oCacher->Get('quokka-sso/ticket/' . $sTicket) : null;
		$aSaved = $sSaved ? \json_decode($sSaved, true) : null;
		if (!$aSaved || \time() - $aSaved['time'] > self::STATE_TTL) {
			return $this->page($this->t('PAGE_TIMEOUT'), '<p>' . $this->t('PAGE_TIMEOUT_TEXT') . '</p><p><a class="btn" href="./?QuokkaSso">' . $this->t('SIGN_IN_WITH') . '</a></p>');
		}
		$sEmail = \trim((string) ($_POST['email'] ?? ''));
		$sPassword = (string) ($_POST['password'] ?? '');
		try {
			// the same check as the usual sign-in: the mail server says whether they are right
			\RainLoop\Api::Actions()->LoginProcess($sEmail, new \SnappyMail\SensitiveString($sPassword));
		} catch (\Throwable $oException) {
			// the log says what really went wrong; the page says it in plain words
			$oPrevious = $oException->getPrevious();
			\error_log('Quokka SSO: linking ' . $sEmail . ' failed: ' . \get_class($oException)
				. ' ' . $oException->getCode() . ' ' . $oException->getMessage()
				. ($oPrevious ? ' <- ' . \get_class($oPrevious) . ': ' . $oPrevious->getMessage() : ''));
			switch ($oException instanceof \RainLoop\Exceptions\ClientException ? $oException->getCode() : 0) {
				case \RainLoop\Notifications::ConnectionError:
				case \RainLoop\Notifications::MailServerError:
					$sError = 'LINK_NO_SERVER'; break;
				case \RainLoop\Notifications::DomainNotAllowed:
				case \RainLoop\Notifications::AccountNotAllowed:
					$sError = 'LINK_DOMAIN'; break;
				case \RainLoop\Notifications::AuthError:
					// a refused password comes as LoginBadCredentialsException, anything else is a server problem
					$sError = $oPrevious && !($oPrevious instanceof \MailSo\Imap\Exceptions\LoginBadCredentialsException)
						? 'LINK_NO_SERVER' : 'LINK_WRONG'; break;
				default:
					$sError = 'LINK_FAILED';
			}
			return $this->linkForm($sTicket, $sEmail, '', $this->t($sError));
		}
		$oCacher->Delete('quokka-sso/ticket/' . $sTicket);
		if (!self::save($aSaved['sub'], $sEmail, $sPassword)) {
			\error_log('Quokka SSO: cannot write the link');
		}
		\header('Location: ./');
		return true;
	}

	private function enter(string $sEmail, string $sPassword) : bool
	{
		$sHash = \RainLoop\Api::CreateUserSsoHash($sEmail, $sPassword);
		// Not a redirect: a redirect would still belong to the page load Pocket ID started, and SnappyMail rightly refuses
		// ?Sso from other sites. This page moves on by itself, so ?Sso is a load started by Quokka.
		$sUrl = \htmlspecialchars('./?Sso&hash=' . \rawurlencode((string) $sHash), ENT_QUOTES, 'UTF-8');
		\header('Content-Type: text/html; charset=utf-8');
		\header('Cache-Control: no-store');
		\header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
		\header('Referrer-Policy: no-referrer');
		echo "<!doctype html><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"0;url={$sUrl}\"><title>Quokka</title>"
			. "<p style=\"font:15px system-ui;text-align:center;margin-top:30vh\"><a href=\"{$sUrl}\">Quokka</a></p>";
		return true;
	}

	// ---------- the pages of the flow ----------

	private function linkForm(string $sTicket, string $sEmail, string $sName, string $sError) : bool
	{
		$h = fn($s) => \htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
		return $this->page($this->t('LINK_TITLE'),
			($sName ? '<p>' . $this->t('LINK_HELLO', array('NAME' => $h($sName))) . '</p>' : '')
			. '<p>' . $this->t('LINK_TEXT') . '</p>'
			. ($sError ? '<p class="err">' . $h($sError) . '</p>' : '')
			. '<form method="post" action="./?QuokkaSsoLink">'
			. '<input type="hidden" name="ticket" value="' . $h($sTicket) . '">'
			. '<label>' . $this->t('EMAIL') . '<input name="email" type="email" required autocomplete="username" value="' . $h($sEmail) . '"></label>'
			. '<label>' . $this->t('MAILBOX_PASSWORD') . '<input name="password" type="password" required autocomplete="current-password" autofocus></label>'
			. '<button type="submit">' . $this->t('LINK_AND_ENTER') . '</button>'
			. '</form><p class="small"><a href="./">' . $this->t('CANCEL_USUAL') . '</a></p>');
	}

	/** The interface's language (the person's choice or the server's default) */
	private static function lang() : string
	{
		try {
			return \RainLoop\Api::Actions()->GetLanguage() ?: 'en';
		} catch (\Throwable $oException) {
			return 'en';
		}
	}

	/** A text of the server's own pages, from langs/<language>.json over langs/en.json; %KEY% filled from $aVars */
	private function t(string $sKey, array $aVars = array()) : string
	{
		static $aTexts = null;
		if (null === $aTexts) {
			$fRead = fn($sLang) => (\json_decode((string) @\file_get_contents(__DIR__ . "/langs/{$sLang}.json"), true) ?: array())['QUOKKA_SSO'] ?? array();
			$sLang = \preg_replace('/[^a-zA-Z-]/', '', self::lang());
			$aTexts = \array_replace($fRead('en'), $fRead(\explode('-', $sLang)[0]), $fRead($sLang));
		}
		$sText = $aTexts[$sKey] ?? $sKey;
		foreach ($aVars as $sName => $sValue) {
			$sText = \str_replace('%' . $sName . '%', $sValue, $sText);
		}
		return $sText;
	}

	private function page(string $sTitle, string $sBody) : bool
	{
		\header('Content-Type: text/html; charset=utf-8');
		\header('Cache-Control: no-store');
		\header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
		$sTitle = \htmlspecialchars($sTitle, ENT_QUOTES, 'UTF-8');
		$sLang = \htmlspecialchars(self::lang(), ENT_QUOTES, 'UTF-8');
		echo <<<HTML
<!doctype html><html lang="{$sLang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$sTitle} – Quokka</title><style>
:root{color-scheme:light dark;--bg:light-dark(#f7f8fa,#111317);--surface:light-dark(#fff,#1a1d23);--ink:light-dark(#111827,#e8eaed);
--muted:light-dark(#6b7280,#9aa0a9);--line:light-dark(#e5e7eb,#2c313a);--primary:light-dark(#1e3a5f,#8fb3d9);--primary-ink:light-dark(#fff,#0f1a26);--danger:light-dark(#c2410c,#f08a5d)}
body{margin:0;min-height:100dvh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:16px;box-sizing:border-box}
main{width:min(420px,100%);background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:28px}
h1{margin:0 0 12px;font-size:22px;letter-spacing:-.02em;color:var(--primary)}p{margin:0 0 12px}.small{font-size:13px;color:var(--muted)}
.err{color:var(--danger);font-weight:600}label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;margin:0 0 12px}
input{font:inherit;height:42px;padding:0 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink)}
button,.btn{display:inline-flex;align-items:center;justify-content:center;width:100%;height:44px;border:0;border-radius:10px;
background:var(--primary);color:var(--primary-ink);font:inherit;font-weight:600;text-decoration:none;cursor:pointer;margin:4px 0 12px}
a{color:var(--primary)}</style></head><body><main><h1>{$sTitle}</h1>{$sBody}</main></body></html>
HTML;
		return true;
	}

	// ---------- Settings → Sign-in ----------

	public function JsonStatus()
	{
		$oAccount = \RainLoop\Api::Actions()->getMainAccountFromToken(false);
		return $this->jsonResponse(__FUNCTION__, array(
			'configured' => self::configured(),
			'email' => $oAccount ? $oAccount->Email() : '',
			'linked' => $oAccount && self::configured() ? \count(self::linksOf($oAccount->Email())) : 0
		));
	}

	/** Forget the encrypted copy of this mailbox's password (Pocket ID will ask again) */
	public function JsonUnlink()
	{
		$oAccount = \RainLoop\Api::Actions()->getMainAccountFromToken(false);
		if (!$oAccount || !self::configured()) {
			return $this->jsonResponse(__FUNCTION__, false);
		}
		$iRemoved = 0;
		foreach (self::linksOf($oAccount->Email()) as $sFile) {
			\unlink($sFile) && $iRemoved++;
		}
		return $this->jsonResponse(__FUNCTION__, array('removed' => $iRemoved));
	}
}

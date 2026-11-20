<?php
/**
 * Quokka – the family apps from an email: "Archivia in Pelican" (the PDF attached, or the whole email) and
 * "Promemoria in Kooka" (a deadline). The calls leave from the server with each person's own API token, so the
 * tokens never reach the browser.
 *
 * Where the apps are: QUOKKA_PELICAN_URL and QUOKKA_KOOKA_URL in the environment (e.g. https://pelican.example.it).
 * Each person pastes their tokens once in Settings → Family apps (Pelican: pt_…, Kooka: dt_…).
 *
 * Also: which categories each person sees (Settings → Categories), and "Unsubscribe" on newsletters, from their List-Unsubscribe header (RFC 2369, one-click RFC 8058).
 */
class QuokkaExtrasPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Quokka extras',
		AUTHOR   = 'Quokka',
		VERSION  = '1.0',
		RELEASE  = '2026-11-18',
		REQUIRED = '2.36.0',
		CATEGORY = 'General',
		LICENSE  = 'AGPL-3.0',
		DESCRIPTION = 'Categories settings, one-click unsubscribe, an email to Pelican and its deadline to Kooka.';

	const MAX_FILE = 30 * 1024 * 1024;

	/** The user's settings; the app tokens saved before the rename (paTrack, doTrack until 2026-11) under the new names */
	private function settings() : array
	{
		$aSettings = $this->getUserSettings();
		foreach (array('patrackToken' => 'pelicanToken', 'dotrackToken' => 'kookaToken') as $sOld => $sNew) {
			if (!isset($aSettings[$sNew]) && isset($aSettings[$sOld])) {
				$aSettings[$sNew] = $aSettings[$sOld];
			}
			unset($aSettings[$sOld]);
		}
		return $aSettings;
	}

	public function Init() : void
	{
		$this->UseLangs(true);      // langs/*.json: the texts, QUOKKA_EXTRAS/…; errors go to the page as ERR_… keys
		$this->addJs('js/QuokkaExtras.js');
		$this->addTemplate('templates/QuokkaAppsSettingsTab.html');
		$this->addTemplate('templates/QuokkaCategoriesSettingsTab.html');
		$this->addHook('filter.app-data', 'FilterAppData');
		$this->addJsonHook('QuokkaCategoriesSave', 'JsonCategorieSave');
		$this->addJsonHook('QuokkaExtrasGet', 'JsonGet');
		$this->addJsonHook('QuokkaExtrasSave', 'JsonSave');
		$this->addJsonHook('QuokkaExtrasToPelican', 'JsonToPelican');
		$this->addJsonHook('QuokkaExtrasToKooka', 'JsonToKooka');
		$this->addJsonHook('QuokkaExtrasUnsubscribe', 'JsonUnsubscribe');
		// the sorter reads every mailbox people sign in to (categories, notifications): Quokka passes them on
		$this->addHook('login.success', 'RegisterMailboxes');
		$this->addHook('json.after-AccountSetup', 'RegisterMailboxes');
		$this->addJsonHook('QuokkaPushStatus', 'JsonPushStatus');
		$this->addJsonHook('QuokkaPushSubscribe', 'JsonPushSubscribe');
		$this->addJsonHook('QuokkaPushUnsubscribe', 'JsonPushUnsubscribe');
		$this->addJsonHook('QuokkaPushEvery', 'JsonPushEvery');
		$this->addJsonHook('QuokkaPushTest', 'JsonPushTest');
		$this->addTemplate('templates/QuokkaPushSettingsTab.html');
	}

	private static function appUrl(string $sName) : string
	{
		$sUrl = \trim((string) \getenv($sName));
		return \preg_match('#^https?://#i', $sUrl) ? \rtrim($sUrl, '/') : '';
	}

	/** The categories this person turned off, for the page from the start */
	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult) && !empty($aResult['Auth'])) {
			try {
				$aResult['QuokkaHiddenCategories'] = \array_values($this->settings()['hiddenCategories'] ?? array());
			} catch (\Throwable $oException) {
			}
		}
	}

	/** {hidden: ["social", …]}: the category keys not to show */
	public function JsonCategorieSave()
	{
		$aHidden = \array_values(\array_unique(\array_filter((array) $this->jsonParam('hidden', array()),
			fn($s) => \is_string($s) && \preg_match('/^[a-z]{1,30}$/D', $s))));
		$aSettings = $this->settings();
		$aSettings['hiddenCategories'] = \array_slice($aHidden, 0, 30);
		return $this->jsonResponse(__FUNCTION__, $this->saveUserSettings($aSettings));
	}

	/** What the page may know: whether each app is there and whether this person has a token for it. */
	public function JsonGet()
	{
		$aSettings = $this->settings();
		return $this->jsonResponse(__FUNCTION__, array(
			'pelican' => '' !== self::appUrl('QUOKKA_PELICAN_URL'),
			'kooka' => '' !== self::appUrl('QUOKKA_KOOKA_URL'),
			'pelicanToken' => !empty($aSettings['pelicanToken']),
			'kookaToken' => !empty($aSettings['kookaToken'])
		));
	}

	/** A token left empty keeps the one saved; "-" removes it. */
	public function JsonSave()
	{
		$aSettings = $this->settings();
		foreach (array('pelicanToken' => 'pt_', 'kookaToken' => 'dt_') as $sKey => $sPrefix) {
			$sValue = \trim((string) $this->jsonParam($sKey, ''));
			if ('-' === $sValue) {
				unset($aSettings[$sKey]);
			} else if ('' !== $sValue) {
				if (!\str_starts_with($sValue, $sPrefix) || 200 < \strlen($sValue)) {
					return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_TOKEN_PREFIX', 'prefix' => $sPrefix));
				}
				$aSettings[$sKey] = $sValue;
			}
		}
		return $this->jsonResponse(__FUNCTION__, $this->saveUserSettings($aSettings));
	}

	/** {folder, uid, mimeIndex ('' = the whole email), fileName, title} → Pelican's import queue */
	public function JsonToPelican()
	{
		$sBase = self::appUrl('QUOKKA_PELICAN_URL');
		$sToken = (string) ($this->settings()['pelicanToken'] ?? '');
		if (!$sBase || !$sToken) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_PELICAN_NOT_LINKED'));
		}
		$sFolder = (string) $this->jsonParam('folder', '');
		$iUid = (int) $this->jsonParam('uid', 0);
		$sMimeIndex = \preg_replace('/[^0-9.]/', '', (string) $this->jsonParam('mimeIndex', ''));
		$sFileName = \trim((string) $this->jsonParam('fileName', '')) ?: 'email.eml';
		if (!$sFolder || 1 > $iUid) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_MESSAGE_NOT_FOUND'));
		}

		$oActions = \RainLoop\Api::Actions();
		$oAccount = $oActions->getAccountFromToken();
		if (!$oActions->ImapClient()->IsLoggined()) {
			$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oActions->ImapClient(), $oActions->Config());
		}
		$sData = '';
		$oActions->MailClient()->MessageMimeStream(function ($rResource) use (&$sData) {
			if (\is_resource($rResource)) {
				$sData = (string) \stream_get_contents($rResource, self::MAX_FILE + 1);
			}
		}, $sFolder, $iUid, $sMimeIndex);
		if ('' === $sData || self::MAX_FILE < \strlen($sData)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => '' === $sData ? 'ERR_ATTACHMENT_NOT_FOUND' : 'ERR_FILE_TOO_BIG'));
		}

		$sBoundary = 'quokka' . \bin2hex(\random_bytes(12));
		$fField = fn($sName, $sValue) => "--{$sBoundary}\r\nContent-Disposition: form-data; name=\"{$sName}\"\r\n\r\n{$sValue}\r\n";
		$sSafeName = \str_replace(array('"', "\r", "\n"), '', $sFileName);
		$sBody = $fField('source', 'api')
			. $fField('category', 'home')
			. $fField('title', \str_replace(array("\r", "\n"), ' ', \mb_substr((string) $this->jsonParam('title', ''), 0, 200)))
			. "--{$sBoundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$sSafeName}\"\r\n"
			. "Content-Type: application/octet-stream\r\n\r\n{$sData}\r\n--{$sBoundary}--\r\n";
		return $this->jsonResponse(__FUNCTION__, $this->call('POST', $sBase . '/api/imports', $sBody, $sToken,
			"multipart/form-data; boundary={$sBoundary}", 'Pelican'));
	}

	/** {title, dueDate (YYYY-MM-DD), notes} → a deadline in Kooka, reminded three days before */
	public function JsonToKooka()
	{
		$sBase = self::appUrl('QUOKKA_KOOKA_URL');
		$sToken = (string) ($this->settings()['kookaToken'] ?? '');
		if (!$sBase || !$sToken) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_KOOKA_NOT_LINKED'));
		}
		$sDue = (string) $this->jsonParam('dueDate', '');
		if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/D', $sDue)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_NO_DUE_DATE'));
		}
		$sBody = \json_encode(array(
			'kind' => 'deadline',
			'category' => 'money',
			'title' => \mb_substr(\trim((string) $this->jsonParam('title', '')) ?: 'Deadline', 0, 200),
			'due_date' => $sDue,
			'remind_days' => 3,
			'notes' => \mb_substr((string) $this->jsonParam('notes', ''), 0, 2000)
		));
		return $this->jsonResponse(__FUNCTION__, $this->call('POST', $sBase . '/api/items', $sBody, $sToken, 'application/json', 'Kooka'));
	}

	/**
	 * Unsubscribe from the list that sent an email. The header is read here, from the mailbox, never taken from the
	 * page. One-click (RFC 8058: an https address and "List-Unsubscribe-Post: List-Unsubscribe=One-Click") is done
	 * from the server: {done: true}. Otherwise the page gets what people do themselves: {mailto} (an email to send)
	 * or {url} (a page to open).
	 */
	public function JsonUnsubscribe()
	{
		$sFolder = (string) $this->jsonParam('folder', '');
		$iUid = (int) $this->jsonParam('uid', 0);
		if (!$sFolder || 1 > $iUid) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_MESSAGE_NOT_FOUND'));
		}
		$oActions = \RainLoop\Api::Actions();
		$oAccount = $oActions->getAccountFromToken();
		if (!$oActions->ImapClient()->IsLoggined()) {
			$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oActions->ImapClient(), $oActions->Config());
		}
		$oHeaders = $oActions->MailClient()->Message($sFolder, $iUid)?->Headers;
		$aLinks = self::unsubscribeLinks($oHeaders ? $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::LIST_UNSUBSCRIBE) : '');
		if (!$aLinks['url'] && !$aLinks['mailto']) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_UNSUBSCRIBE_NO_WAY'));
		}
		$bOneClick = $oHeaders && \preg_match('/List-Unsubscribe\s*=\s*One-Click/i',
			$oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::LIST_UNSUBSCRIBE_POST));
		if ($bOneClick && \str_starts_with(\strtolower($aLinks['url']), 'https://')) {
			try {
				$oRequest = \SnappyMail\HTTP\Request::factory();
				$oRequest->timeout = 20;
				$oRequest->block_private_ips = true;   // an address written by whoever sent the email
				$oResponse = $oRequest->doRequest('POST', $aLinks['url'], 'List-Unsubscribe=One-Click',
					array('Content-Type: application/x-www-form-urlencoded'));
				$iStatus = $oResponse ? (int) $oResponse->status : 0;
				if (200 <= $iStatus && 300 > $iStatus) {
					return $this->jsonResponse(__FUNCTION__, array('done' => true));
				}
			} catch (\Throwable $oException) {
				\error_log('Quokka: ' . $oException);
			}
			// the sender's server said no: fall back to what people can do themselves
		}
		return $this->jsonResponse(__FUNCTION__, $aLinks['mailto'] ? array('mailto' => $aLinks['mailto']) : array('url' => $aLinks['url']));
	}

	/** "<https://x/u?id=1>, <mailto:u@x?subject=unsub>" → {url, mailto}: the first of each, http(s) and mailto only */
	public static function unsubscribeLinks(string $sHeader) : array
	{
		$aLinks = array('url' => '', 'mailto' => '');
		\preg_match_all('/<\s*([^>\s]+)\s*>/', $sHeader, $aMatches);
		foreach ($aMatches[1] as $sLink) {
			if (!$aLinks['url'] && \preg_match('#^https?://[^\s"\'<>]+$#iD', $sLink)) {
				$aLinks['url'] = $sLink;
			} else if (!$aLinks['mailto'] && \preg_match('/^mailto:[^\s"\'<>]+$/iD', $sLink)) {
				$aLinks['mailto'] = $sLink;
			}
		}
		return $aLinks;
	}

	private function call(string $sMethod, string $sUrl, string $sBody, string $sToken, string $sType, string $sApp) : array
	{
		try {
			$oRequest = \SnappyMail\HTTP\Request::factory();
			$oRequest->timeout = 30;
			// the addresses come from the environment, set by the admin: the apps may well be on the Docker network
			$oRequest->block_private_ips = false;
			$oResponse = $oRequest->doRequest($sMethod, $sUrl, $sBody, array(
				'Authorization: Bearer ' . $sToken,
				'Content-Type: ' . $sType,
				'Accept: application/json'
			));
		} catch (\Throwable $oException) {
			\error_log('Quokka: ' . $oException);
			return array('error' => 'ERR_APP_DOWN', 'app' => $sApp);
		}
		$iStatus = $oResponse ? (int) $oResponse->status : 0;
		if (200 <= $iStatus && 300 > $iStatus) {
			$aData = \json_decode((string) $oResponse->body, true);
			return array('ok' => true, 'status' => \is_array($aData) ? ($aData['status'] ?? '') : '');
		}
		return array('app' => $sApp, 'status' => $iStatus, 'error' => match (true) {
			401 === $iStatus || 403 === $iStatus => 'ERR_APP_TOKEN',
			0 === $iStatus => 'ERR_APP_DOWN',
			default => 'ERR_APP_STATUS'
		});
	}

	// ---------- notifications with Quokka closed: the sorter sends them (sorter/push.py) ----------

	/** A signed call to the sorter's notifications service, on the internal network. [status, data] */
	private static function sorter(string $sPath, array $aData = array(), int $iTimeout = 15) : array
	{
		$sSecret = \trim((string) \getenv('QUOKKA_SECRET'));
		$sBase = \rtrim(\trim((string) \getenv('QUOKKA_SORTER_URL')) ?: 'http://quokka-sorter:8025', '/');
		if (32 > \strlen($sSecret)) {
			return array(0, array());
		}
		$sBody = $aData ? \json_encode($aData) : '';
		$sTime = (string) \time();
		$sSign = \hash_hmac('sha256', "{$sTime}\n{$sPath}\n{$sBody}", \hash('sha256', 'quokka-push|' . $sSecret, true));
		try {
			$oRequest = \SnappyMail\HTTP\Request::factory();
			$oRequest->timeout = $iTimeout;
			$oRequest->block_private_ips = false;   // the sorter is on the stack's own network
			$oResponse = $oRequest->doRequest($aData ? 'POST' : 'GET', $sBase . $sPath, $sBody, array(
				'Content-Type: application/json', 'X-Quokka-Time: ' . $sTime, 'X-Quokka-Sign: ' . $sSign
			));
		} catch (\Throwable $oException) {
			\error_log('Quokka notifications: the sorter does not answer: ' . $oException->getMessage());
			return array(0, array());
		}
		$aOut = \json_decode((string) ($oResponse->body ?? ''), true);
		return array($oResponse ? (int) $oResponse->status : 0, \is_array($aOut) ? $aOut : array());
	}

	/** The mailbox the person is in now: notifications are set per mailbox */
	private static function mailbox() : string
	{
		$oAccount = \RainLoop\Api::Actions()->getAccountFromToken(false);
		return $oAccount ? $oAccount->Email() : '';
	}

	/**
	 * The person's mailboxes (the main one and the added ones, with their passwords) to the sorter, which keeps them
	 * sealed with QUOKKA_SECRET and reads them from then on: categories and notifications with nothing to set up.
	 * At every sign-in and after an account is added or changed; a sorter that does not answer never stops a sign-in.
	 */
	public function RegisterMailboxes() : void
	{
		if (32 > \strlen(\trim((string) \getenv('QUOKKA_SECRET')))) {
			return;
		}
		try {
			$oActions = \RainLoop\Api::Actions();
			$oMain = $oActions->getMainAccountFromToken(false);
			if (!$oMain) {
				return;
			}
			$aBoxes = array($oMain->Email() => $oMain->ImapPass());
			foreach ($oActions->GetAccounts($oMain) as $aAccount) {
				$oAccount = \RainLoop\Model\AdditionalAccount::NewInstanceFromTokenArray($oActions, $aAccount);
				if ($oAccount && \strlen($oAccount->ImapPass())) {
					$aBoxes[$oAccount->Email()] = $oAccount->ImapPass();
				}
			}
			foreach ($aBoxes as $sEmail => $sPassword) {
				[$iStatus] = self::sorter('/register', array('account' => $sEmail, 'password' => $sPassword), 3);
				if (0 === $iStatus) {
					break;   // not there: no point waiting for each mailbox
				}
			}
		} catch (\Throwable $oException) {
			\error_log('Quokka: mailboxes not passed on to the sorter: ' . $oException->getMessage());
		}
	}

	/** {endpoint} → {available, watched, every, subscribed, key, mailbox} */
	public function JsonPushStatus()
	{
		[$iKey, $aKey] = self::sorter('/key');
		if (200 !== $iKey || empty($aKey['key'])) {
			return $this->jsonResponse(__FUNCTION__, array('available' => false));
		}
		$aParams = array('account' => self::mailbox(), 'endpoint' => (string) $this->jsonParam('endpoint', ''));
		[, $aStatus] = self::sorter('/status', $aParams);
		if (empty($aStatus['watched'])) {
			// signed in before Quokka passed mailboxes on (kept signed in): pass them on now
			$this->RegisterMailboxes();
			[, $aStatus] = self::sorter('/status', $aParams);
		}
		return $this->jsonResponse(__FUNCTION__, array('available' => true, 'key' => $aKey['key'], 'mailbox' => self::mailbox()) + $aStatus);
	}

	/** {subscription, lang}: this device gets the notifications of this mailbox */
	public function JsonPushSubscribe()
	{
		$aSub = $this->jsonParam('subscription', array());
		$sUrl = \rtrim(\trim((string) \getenv('QUOKKA_PUBLIC_URL')), '/');
		if (!$sUrl) {
			$sUrl = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http')
				. '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
		}
		[$iStatus, $aOut] = self::sorter('/subscribe', array('account' => self::mailbox(), 'subscription' => \is_array($aSub) ? $aSub : array(),
			'url' => $sUrl . '/', 'lang' => (string) $this->jsonParam('lang', 'it')));
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus ? $aOut : array('error' => $aOut['error'] ?? 'unavailable'));
	}

	public function JsonPushUnsubscribe()
	{
		[$iStatus] = self::sorter('/unsubscribe', array('endpoint' => (string) $this->jsonParam('endpoint', ''), 'account' => self::mailbox()));
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus);
	}

	/** {every}: minutes between two notifications of this mailbox (0 = as soon as an email arrives) */
	public function JsonPushEvery()
	{
		[$iStatus, $aOut] = self::sorter('/every', array('account' => self::mailbox(), 'every' => (int) $this->jsonParam('every', 5)));
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus ? $aOut : array('error' => $aOut['error'] ?? 'unavailable'));
	}

	public function JsonPushTest()
	{
		[$iStatus, $aOut] = self::sorter('/test', array('account' => self::mailbox(), 'endpoint' => (string) $this->jsonParam('endpoint', '')));
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus ? $aOut : array('error' => $aOut['error'] ?? 'unavailable'));
	}
}

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
		$this->addHook('main.content-security-policy', 'ContentSecurityPolicy');
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
		$this->addJsonHook('QuokkaOpenAccount', 'JsonOpenAccount');
		$this->addJsonHook('QuokkaBoxInfo', 'JsonBoxInfo');
		$this->addJsonHook('QuokkaBoxRefresh', 'JsonBoxRefresh');
		// "All mailboxes": the INBOX of every mailbox of the person in one list (the sorter's index, sorter/inbox.py)
		$this->addJsonHook('QuokkaUnifiedList', 'JsonUnifiedList');
		$this->addJsonHook('QuokkaUnifiedAct', 'JsonUnifiedAct');
		$this->addJsonHook('QuokkaUnifiedSearch', 'JsonUnifiedSearch');
		// invitations in emails (.ics): what is on in Kooka at that time, saved there, answered to the organiser
		$this->addJsonHook('QuokkaInvite', 'JsonInvite');
		$this->addJsonHook('QuokkaInviteAnswer', 'JsonInviteAnswer');
		$this->addTemplate('templates/QuokkaPushSettingsTab.html');
	}

	private static function appUrl(string $sName) : string
	{
		$sUrl = \trim((string) \getenv($sName));
		return \preg_match('#^https?://#i', $sUrl) ? \rtrim($sUrl, '/') : '';
	}

	/** Kooka's calendar is shown inside Quokka (Common/CalendarPanel.js): its address, and only it, may be framed */
	public function ContentSecurityPolicy(\SnappyMail\HTTP\CSP $CSP) : void
	{
		$aUrl = \parse_url(self::appUrl('QUOKKA_KOOKA_URL'));
		if (!empty($aUrl['scheme']) && !empty($aUrl['host'])) {
			$CSP->add('frame-src', "'self'");
			$CSP->add('frame-src', $aUrl['scheme'] . '://' . $aUrl['host'] . (empty($aUrl['port']) ? '' : ':' . $aUrl['port']));
		}
	}

	/** The categories this person turned off, and Kooka's calendar (when Kooka is there), for the page from the start */
	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult) && !empty($aResult['Auth'])) {
			$sKooka = self::appUrl('QUOKKA_KOOKA_URL');
			$aResult['QuokkaCalendarUrl'] = $sKooka ? $sKooka . '/#/calendar' : '';
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

	// ---------- invitations in emails: Kooka is the calendar ----------

	/** A call to Kooka with the person's own token: [status, data] (0: not linked or not reachable) */
	private function kooka(string $sMethod, string $sPath, ?array $aData = null) : array
	{
		$sBase = self::appUrl('QUOKKA_KOOKA_URL');
		$sToken = (string) ($this->settings()['kookaToken'] ?? '');
		if (!$sBase || !$sToken) {
			return array(0, array());
		}
		try {
			$oRequest = \SnappyMail\HTTP\Request::factory();
			$oRequest->timeout = 15;
			$oRequest->block_private_ips = false;   // set by the admin: Kooka may well be on the Docker network
			$oResponse = $oRequest->doRequest($sMethod, $sBase . '/api' . $sPath, null === $aData ? null : \json_encode($aData), array(
				'Authorization: Bearer ' . $sToken, 'Content-Type: application/json', 'Accept: application/json'));
		} catch (\Throwable $oException) {
			\error_log('Quokka: Kooka: ' . $oException->getMessage());
			return array(0, array());
		}
		$aOut = \json_decode((string) ($oResponse->body ?? ''), true);
		return array($oResponse ? (int) $oResponse->status : 0, \is_array($aOut) ? $aOut : array());
	}

	/** The event of the invitation in an email ({folder, uid}), read from the mailbox: [event, account] or [null, …] */
	private function invitation() : array
	{
		require_once __DIR__ . '/Ics.php';
		$sFolder = (string) $this->jsonParam('folder', '');
		$iUid = (int) $this->jsonParam('uid', 0);
		$oActions = \RainLoop\Api::Actions();
		$oAccount = $oActions->getAccountFromToken();
		if (!$sFolder || 1 > $iUid) {
			return array(null, $oAccount);
		}
		if (!$oActions->ImapClient()->IsLoggined()) {
			$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oActions->ImapClient(), $oActions->Config());
		}
		$oMessage = $oActions->MailClient()->Message($sFolder, $iUid);
		$sPart = $sEncoding = '';
		$iBest = 0;
		foreach ($oMessage?->Attachments ?: array() as $oAttachment) {
			$sType = \strtolower($oAttachment->ContentType());
			$sName = \strtolower($oAttachment->FileName());
			// the text/calendar part says what the email is (an invitation, a reply, a cancellation): it wins
			$iScore = 'text/calendar' === $sType ? 2 : ('application/ics' === $sType || \str_ends_with($sName, '.ics') ? 1 : 0);
			if ($iScore > $iBest) {
				[$iBest, $sPart, $sEncoding] = array($iScore, $oAttachment->PartID(), \strtolower($oAttachment->ContentTransferEncoding()));
			}
		}
		if (!$sPart) {
			return array(null, $oAccount);
		}
		$sData = '';
		$oActions->MailClient()->MessageMimeStream(function ($rResource) use (&$sData) {
			if (\is_resource($rResource)) {
				$sData = (string) \stream_get_contents($rResource, 512 * 1024);
			}
		}, $sFolder, $iUid, $sPart);
		// decoded by the mail server or by SnappyMail, depending on the server: when it still is not, here
		if (!\str_contains($sData, 'BEGIN:VCALENDAR')) {
			$sDecoded = \base64_decode(\preg_replace('/\s+/', '', $sData), true);
			if ($sDecoded && \str_contains($sDecoded, 'BEGIN:VCALENDAR')) {
				$sData = $sDecoded;
			}
		}
		if ('quoted-printable' === $sEncoding && \preg_match('/=\r?\n|=3D/', $sData)) {
			$sData = \quoted_printable_decode($sData);
		}
		if (!\mb_check_encoding($sData, 'UTF-8')) {
			$sData = \mb_convert_encoding($sData, 'UTF-8', 'ISO-8859-1');
		}
		$sZone = \trim((string) \getenv('TZ')) ?: \date_default_timezone_get();
		return array(\QuokkaIcs::parse($sData, $sZone), $oAccount);
	}

	/** This person among the attendees (any of their mailboxes, or an identity of this one): the attendee or null */
	private static function myAttendee(array $aEvent, $oAccount) : ?array
	{
		$aMine = \array_map('strtolower', self::mailboxes());
		$aMine[] = \strtolower($oAccount->Email());
		try {
			foreach (\RainLoop\Api::Actions()->GetIdentities($oAccount) as $oIdentity) {
				$aMine[] = \strtolower($oIdentity->Email());
			}
		} catch (\Throwable $oException) {
		}
		foreach ($aEvent['attendees'] as $aAttendee) {
			if (\in_array($aAttendee['email'], $aMine, true)) {
				return $aAttendee;
			}
		}
		return null;
	}

	/**
	 * {folder, uid} → {event, canReply, kooka: linked, saved {id, url}|null, conflicts [...], sameDay [...]}
	 * conflicts: the person's timed things in Kooka that overlap the event; sameDay: whole-day ones on its days.
	 */
	public function JsonInvite()
	{
		[$aEvent, $oAccount] = $this->invitation();
		if (!$aEvent) {
			return $this->jsonResponse(__FUNCTION__, array('event' => null));
		}
		$aMe = self::myAttendee($aEvent, $oAccount);
		$aOut = array(
			'event' => \array_diff_key($aEvent, array('lines' => 1)),
			'canReply' => 'REQUEST' === $aEvent['method'] && $aEvent['organizer'] && $aMe
				&& !\in_array($aEvent['organizer']['email'], \array_map('strtolower', self::mailboxes()), true),
			'kooka' => false, 'saved' => null, 'conflicts' => array(), 'sameDay' => array()
		);
		$sFrom = $aEvent['start']['date'];
		$sTo = \max($sFrom, \min($aEvent['end']['date'], \date('Y-m-d', \strtotime($sFrom . ' +31 days'))));
		[$iStatus, $aBusy] = $this->kooka('GET', '/calendar/busy?' . \http_build_query(array('from' => $sFrom, 'to' => $sTo)));
		if (200 === $iStatus) {
			$aOut['kooka'] = true;
			$sStart = $sFrom . ' ' . ($aEvent['start']['time'] ?? '00:00');
			$sEnd = $aEvent['end']['date'] . ' ' . ($aEvent['end']['time'] ?? '24:00');
			foreach ($aBusy as $aItem) {
				if ($aItem['all_day'] || $aEvent['allDay']) {
					$aOut['sameDay'][] = $aItem;
					continue;
				}
				$sItemEnd = $aItem['date'] . ' ' . ($aItem['end_time'] ?: \date('H:i', \strtotime($aItem['time'] . ' +1 hour')));
				if ($aItem['end_time'] && $aItem['end_time'] <= $aItem['time']) {
					$sItemEnd = $aItem['date'] . ' 24:00';
				}
				if ($aItem['date'] . ' ' . $aItem['time'] < $sEnd && $sItemEnd > $sStart) {
					$aOut['conflicts'][] = $aItem;
				}
			}
			if ($aEvent['uid']) {
				[$iSaved, $aSaved] = $this->kooka('GET', '/calendar/invite?' . \http_build_query(array('uid' => $aEvent['uid'])));
				if (200 === $iSaved && !empty($aSaved['saved'])) {
					$aOut['saved'] = array('id' => $aSaved['id'], 'url' => $aSaved['url']);
					// already in the calendar: it is no conflict with itself
					$fOther = fn($aItem) => $aItem['id'] !== $aSaved['id'];
					$aOut['conflicts'] = \array_values(\array_filter($aOut['conflicts'], $fOther));
					$aOut['sameDay'] = \array_values(\array_filter($aOut['sameDay'], $fOther));
				}
			}
		}
		return $this->jsonResponse(__FUNCTION__, $aOut);
	}

	/**
	 * {folder, uid, answer: accept | tentative | decline | save | remove, lang}
	 * accept, tentative, save: into Kooka (once per event: again, it updates it); decline, remove: out of it.
	 * accept, tentative, decline on an invitation also answer the organiser by email (iTIP REPLY).
	 * → {saved {id, url}|null, replied, error?}
	 */
	public function JsonInviteAnswer()
	{
		$sAnswer = (string) $this->jsonParam('answer', '');
		if (!\in_array($sAnswer, array('accept', 'tentative', 'decline', 'save', 'remove'), true)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'invalid'));
		}
		[$aEvent, $oAccount] = $this->invitation();
		if (!$aEvent || !$aEvent['uid']) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'ERR_INVITE_UNREADABLE'));
		}
		$bEn = \str_starts_with((string) $this->jsonParam('lang', 'it'), 'en');
		$aOut = array('saved' => null, 'replied' => false);

		// Kooka
		if ('decline' === $sAnswer || 'remove' === $sAnswer) {
			$this->kooka('POST', '/calendar/invite', array('uid' => $aEvent['uid'], 'status' => 'cancelled'));
		} else {
			$aNotes = array();
			if ($aEvent['organizer']) {
				$aNotes[] = ($bEn ? 'Organiser: ' : 'Organizzatore: ') . \trim($aEvent['organizer']['name'] . ' <' . $aEvent['organizer']['email'] . '>');
			}
			$bSameDay = $aEvent['start']['date'] === $aEvent['end']['date'];
			if (!$bSameDay) {
				$aNotes[] = ($bEn ? 'Until ' : 'Fino al ') . $aEvent['end']['date'] . ($aEvent['end']['time'] ? ' ' . $aEvent['end']['time'] : '');
			}
			if ('tentative' === $sAnswer) {
				$aNotes[] = $bEn ? 'Answered: maybe' : 'Risposta: forse';
			}
			if ($aEvent['description']) {
				$aNotes[] = "\n" . \mb_substr($aEvent['description'], 0, 1500);
			}
			[$iStatus, $aSaved] = $this->kooka('POST', '/calendar/invite', array(
				'uid' => $aEvent['uid'],
				'status' => 'tentative' === $sAnswer ? 'tentative' : 'confirmed',
				'title' => $aEvent['summary'] ?: ($bEn ? 'Event' : 'Evento'),
				'date' => $aEvent['start']['date'],
				'time' => $aEvent['start']['time'],
				'end_time' => $bSameDay ? $aEvent['end']['time'] : null,
				'location' => \mb_substr($aEvent['location'], 0, 200),
				'notes' => \implode("\n", $aNotes)
			));
			if (200 === $iStatus || 201 === $iStatus) {
				$aOut['saved'] = array('id' => $aSaved['id'] ?? null, 'url' => $aSaved['url'] ?? '');
			} else {
				$aOut['error'] = 0 === $iStatus ? 'ERR_KOOKA_NOT_LINKED' : 'ERR_KOOKA_SAVE';
			}
		}

		// the organiser
		$aMe = self::myAttendee($aEvent, $oAccount);
		$sPartstat = array('accept' => 'ACCEPTED', 'tentative' => 'TENTATIVE', 'decline' => 'DECLINED')[$sAnswer] ?? '';
		if ($sPartstat && $aMe && 'REQUEST' === $aEvent['method'] && $aEvent['organizer']) {
			try {
				$this->sendReply($oAccount, $aEvent, $aMe, $sPartstat, $bEn);
				$aOut['replied'] = true;
			} catch (\Throwable $oException) {
				\error_log('Quokka: invitation reply not sent: ' . $oException->getMessage());
				$aOut['error'] = 'ERR_INVITE_REPLY';
			}
		}
		return $this->jsonResponse(__FUNCTION__, $aOut);
	}

	private function sendReply($oAccount, array $aEvent, array $aMe, string $sPartstat, bool $bEn) : void
	{
		$oActions = \RainLoop\Api::Actions();
		$oIdentity = $oActions->GetIdentityByID($oAccount, '', true);
		$sName = $oIdentity ? $oIdentity->Name() : '';
		$aWords = $bEn
			? array('ACCEPTED' => array('Accepted', 'has accepted'), 'TENTATIVE' => array('Tentative', 'might come to'), 'DECLINED' => array('Declined', 'has declined'))
			: array('ACCEPTED' => array('Accettato', 'ha accettato'), 'TENTATIVE' => array('Forse', 'forse parteciperà a'), 'DECLINED' => array('Rifiutato', 'ha rifiutato'));
		[$sPrefix, $sVerb] = $aWords[$sPartstat];

		$oMessage = new \MailSo\Mime\Message();
		$oMessage->DoesNotAddDefaultXMailer();
		$oMessage->SetFrom(new \MailSo\Mime\Email($oIdentity ? $oIdentity->Email() : $oAccount->Email(), $sName));
		$oFrom = $oMessage->GetFrom();
		$oMessage->RegenerateMessageId($oFrom ? $oFrom->GetDomain() : '');
		$oMessage->SetTo(new \MailSo\Mime\EmailCollection(
			$aEvent['organizer']['name'] ? "\"{$aEvent['organizer']['name']}\" <{$aEvent['organizer']['email']}>" : $aEvent['organizer']['email']));
		$oMessage->SetSubject("{$sPrefix}: {$aEvent['summary']}");

		$oAlternative = new \MailSo\Mime\Part;
		$oAlternative->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'multipart/alternative');
		$oAlternative->addPlain(\trim(($sName ?: $aMe['email']) . " {$sVerb}: {$aEvent['summary']}"));
		$oCalendar = new \MailSo\Mime\Part;
		$oCalendar->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE, 'text/calendar; charset=utf-8; method=REPLY');
		$oCalendar->Headers->AddByName(\MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING, 'quoted-printable');
		$oCalendar->Body = \MailSo\Base\StreamWrappers\Binary::CreateStream(
			\MailSo\Base\ResourceRegistry::CreateMemoryResourceFromString(\QuokkaIcs::reply($aEvent, $aMe['email'], $sName, $sPartstat)),
			'convert.quoted-printable-encode'
		);
		$oAlternative->SubParts->append($oCalendar);
		$oMessage->SubParts->append($oAlternative);
		$oActions->SendBuiltMessage($oAccount, $oMessage);
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

	/** The person's mailboxes: the main one, then the added ones */
	private static function mailboxes() : array
	{
		$oActions = \RainLoop\Api::Actions();
		$oMain = $oActions->getMainAccountFromToken(false);
		if (!$oMain) {
			return array();
		}
		return \array_values(\array_unique(\array_merge(array($oMain->Email()), \array_keys($oActions->GetAccounts($oMain)))));
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
		// every mailbox of the person (main and added) on this device, one test notification at the end naming them
		$this->RegisterMailboxes();
		$aBoxes = self::mailboxes();
		$sCurrent = self::mailbox();
		$aBase = array('subscription' => \is_array($aSub) ? $aSub : array(), 'url' => $sUrl . '/', 'lang' => (string) $this->jsonParam('lang', 'it'));
		foreach ($aBoxes as $sEmail) {
			if ($sEmail !== $sCurrent) {
				self::sorter('/subscribe', array('account' => $sEmail, 'test' => false) + $aBase);
			}
		}
		[$iStatus, $aOut] = self::sorter('/subscribe', array('account' => $sCurrent, 'label' => \implode(', ', $aBoxes)) + $aBase);
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus ? $aOut : array('error' => $aOut['error'] ?? 'unavailable'));
	}

	public function JsonPushUnsubscribe()
	{
		// every mailbox on this device, as they were turned on together
		[$iStatus] = self::sorter('/unsubscribe', array('endpoint' => (string) $this->jsonParam('endpoint', '')));
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus);
	}

	/** {every}: minutes between two notifications of this mailbox (0 = as soon as an email arrives) */
	public function JsonPushEvery()
	{
		[$iStatus, $aOut] = self::sorter('/every', array('account' => self::mailbox(), 'every' => (int) $this->jsonParam('every', 5)));
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus ? $aOut : array('error' => $aOut['error'] ?? 'unavailable'));
	}

	/** {email}: a tap on a notification of another of the person's mailboxes: switch to it ({switched}) */
	public function JsonOpenAccount()
	{
		$sEmail = \SnappyMail\IDN::emailToAscii(\trim((string) $this->jsonParam('email', '')));
		$oCurrent = \RainLoop\Api::Actions()->getAccountFromToken(false);
		if (!$sEmail || !$oCurrent || \strtolower($oCurrent->Email()) === \strtolower($sEmail)) {
			return $this->jsonResponse(__FUNCTION__, array('switched' => false));
		}
		try {
			$bOk = \RainLoop\Api::Actions()->switchAccount(new \MailSo\Mime\Email($sEmail));
		} catch (\Throwable $oException) {
			$bOk = false;   // not one of this person's mailboxes, or it does not open: stay where we are
		}
		return $this->jsonResponse(__FUNCTION__, array('switched' => $bOk));
	}

	// ---------- Settings → Accounts: each mailbox with its own things, without switching to it ----------
	// The mailbox is the one named in QuokkaAccount (see Actions/UserAuth.php getAccountFromToken), else the open one.

	/** The folders this mailbox uses as Sent, Drafts, Spam, Trash and Archive ('' when not chosen) */
	public function JsonBoxInfo()
	{
		$oAccount = \RainLoop\Api::Actions()->getAccountFromToken();
		$oSettings = \RainLoop\Api::Actions()->SettingsProvider(true)->Load($oAccount);
		$aOut = array('email' => $oAccount->Email());
		foreach (array('sent' => 'SentFolder', 'drafts' => 'DraftsFolder', 'junk' => 'JunkFolder',
			'trash' => 'TrashFolder', 'archive' => 'ArchiveFolder') as $sKey => $sConf) {
			$aOut[$sKey] = (string) $oSettings->GetConf($sConf, '');
		}
		// detect: 1, for sending from it: where the sent email goes when none was chosen (as the server or the name says)
		$aOut['sentFolder'] = '__UNUSE__' === $aOut['sent'] ? '' : $aOut['sent'];
		if ('' === $aOut['sent'] && $this->jsonParam('detect', 0)) {
			try {
				$oActions = \RainLoop\Api::Actions();
				if (!$oActions->ImapClient()->IsLoggined()) {
					$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oActions->ImapClient(), $oActions->Config());
				}
				$aNames = array('sent', 'sent items', 'sent messages', 'sent mail', 'inviata', 'inviate', 'posta inviata',
					'elementi inviati', 'messaggi inviati', 'inbox.sent', 'inbox.inviata', 'inbox.posta inviata');
				$sByName = '';
				foreach ($oActions->ImapClient()->FolderList('', '*') as $oFolder) {
					if ('sent' === $oFolder->Role()) {
						$aOut['sentFolder'] = $oFolder->FullName;
						break;
					}
					if (!$sByName && \in_array(\mb_strtolower($oFolder->FullName), $aNames, true)) {
						$sByName = $oFolder->FullName;
					}
				}
				$aOut['sentFolder'] = $aOut['sentFolder'] ?: $sByName;
			} catch (\Throwable $oException) {
				\error_log('Quokka: the Sent folder of ' . $aOut['email'] . ': ' . $oException->getMessage());
			}
		}
		return $this->jsonResponse(__FUNCTION__, $aOut);
	}

	/** Its folders changed: the sorter reads them again now, for the column (else at its next round) */
	public function JsonBoxRefresh()
	{
		$oAccount = \RainLoop\Api::Actions()->getAccountFromToken();
		[$iStatus, $aOut] = self::sorter('/folders', array('account' => $oAccount->Email()), 30);
		return $this->jsonResponse(__FUNCTION__, array('ok' => 200 === $iStatus));
	}

	// ---------- "All mailboxes": one list for all of a person's mailboxes ----------

	/**
	 * {category, unseen, attachment, text, offset, folders} → {available, messages, unread, folders, more, pending}
	 * Only the mailboxes of the person signed in (main and added) are ever asked for.
	 */
	public function JsonUnifiedList()
	{
		$aBoxes = self::mailboxes();
		if (!$aBoxes) {
			return $this->jsonResponse(__FUNCTION__, array('available' => false));
		}
		$aParams = array(
			'accounts' => $aBoxes,
			'role' => \in_array($sRole = (string) $this->jsonParam('role', 'inbox'), array('inbox', 'sent', 'drafts', 'trash', 'junk', 'archive'), true) ? $sRole : 'inbox',
			'category' => \preg_replace('/[^a-z]/', '', (string) $this->jsonParam('category', '')),
			'unseen' => !empty($this->jsonParam('unseen', false)),
			'attachment' => !empty($this->jsonParam('attachment', false)),
			'text' => \mb_substr((string) $this->jsonParam('text', ''), 0, 200),
			'offset' => \max(0, (int) $this->jsonParam('offset', 0)),
			'limit' => 50,
			'folders' => !empty($this->jsonParam('folders', false))     // every mailbox's folders, for the column
		);
		[$iStatus, $aOut] = self::sorter('/inbox', $aParams, 10);
		if (200 === $iStatus && !empty($aOut['missing'])) {
			// mailboxes the sorter does not read yet (added before Quokka passed them on): pass them on now
			$this->RegisterMailboxes();
			[$iStatus, $aOut] = self::sorter('/inbox', $aParams, 10);
		}
		if (200 !== $iStatus) {
			return $this->jsonResponse(__FUNCTION__, array('available' => false));
		}
		return $this->jsonResponse(__FUNCTION__, array(
			'available' => true,
			'messages' => $aOut['messages'] ?? array(),
			'unread' => $aOut['unread'] ?? array(),
			'unread_here' => (int) ($aOut['unread_here'] ?? 0),
			'folders' => $aOut['folders'] ?? array(),
			'more' => !empty($aOut['more']),
			'pending' => \array_values(\array_unique(\array_merge($aOut['pending'] ?? array(), $aOut['missing'] ?? array())))
		));
	}

	/** {text}: every folder of every mailbox of the person, at any date, on the mail servers (as Outlook searches) */
	public function JsonUnifiedSearch()
	{
		$sText = \trim(\mb_substr((string) $this->jsonParam('text', ''), 0, 200));
		$aBoxes = self::mailboxes();
		if ('' === $sText || !$aBoxes) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'invalid'));
		}
		[$iStatus, $aOut] = self::sorter('/search', array('accounts' => $aBoxes, 'text' => $sText), 90);
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus
			? array('messages' => $aOut['messages'] ?? array(), 'more' => !empty($aOut['more']), 'failed' => $aOut['failed'] ?? array())
			: array('error' => 'unavailable'));
	}

	/** {account, uids, action: seen|unseen|flag|unflag|opened|archive|trash} on emails of one of the person's mailboxes */
	public function JsonUnifiedAct()
	{
		$sAccount = (string) $this->jsonParam('account', '');
		$aBoxes = self::mailboxes();
		$iKey = \array_search(\strtolower($sAccount), \array_map('strtolower', $aBoxes), true);
		$sAction = (string) $this->jsonParam('action', '');
		if (false === $iKey || !\in_array($sAction, array('seen', 'unseen', 'flag', 'unflag', 'opened', 'archive', 'trash'), true)) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'invalid'));
		}
		$sAccount = $aBoxes[$iKey];
		$aData = array(
			'account' => $sAccount,
			'role' => \in_array($sRole = (string) $this->jsonParam('role', 'inbox'), array('inbox', 'sent', 'drafts', 'trash', 'junk', 'archive', ''), true) ? $sRole : 'inbox',
			'in' => \mb_substr((string) $this->jsonParam('in', ''), 0, 300),   // a search result: its folder by name
			'action' => $sAction,
			'uids' => \array_slice(\array_values(\array_filter(\array_map('intval', (array) $this->jsonParam('uids', array())))), 0, 500)
		);
		if ('archive' === $sAction || 'trash' === $sAction) {
			// the folder that mailbox uses (Settings → Folders), else the sorter finds the server's own
			$sFolder = self::systemFolder($sAccount, 'archive' === $sAction ? 'ArchiveFolder' : 'TrashFolder');
			if ($sFolder) {
				$aData['folder'] = $sFolder;
			}
		}
		[$iStatus, $aOut] = self::sorter('/act', $aData, 30);
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus ? array('ok' => true) : array('error' => $aOut['error'] ?? 'unavailable'));
	}

	/** The Archive or Trash folder chosen for one of the person's mailboxes, '' when none was chosen */
	private static function systemFolder(string $sEmail, string $sKey) : string
	{
		try {
			$oActions = \RainLoop\Api::Actions();
			$oMain = $oActions->getMainAccountFromToken(false);
			if (!$oMain) {
				return '';
			}
			$oAccount = null;
			if (\strtolower($oMain->Email()) === \strtolower($sEmail)) {
				$oAccount = $oMain;
			} else {
				foreach ($oActions->GetAccounts($oMain) as $sAddress => $aAccount) {
					if (\strtolower($sAddress) === \strtolower($sEmail)) {
						$oAccount = \RainLoop\Model\AdditionalAccount::NewInstanceFromTokenArray($oActions, $aAccount);
						break;
					}
				}
			}
			$oSettings = $oAccount ? $oActions->SettingsProvider(true)->Load($oAccount) : null;
			$sFolder = $oSettings instanceof \RainLoop\Settings ? (string) $oSettings->GetConf($sKey, '') : '';
			return '' === $sFolder || '__UNUSE__' === $sFolder ? '' : $sFolder;
		} catch (\Throwable $oException) {
			return '';
		}
	}

	public function JsonPushTest()
	{
		[$iStatus, $aOut] = self::sorter('/test', array('account' => self::mailbox(), 'endpoint' => (string) $this->jsonParam('endpoint', '')));
		return $this->jsonResponse(__FUNCTION__, 200 === $iStatus ? $aOut : array('error' => $aOut['error'] ?? 'unavailable'));
	}
}

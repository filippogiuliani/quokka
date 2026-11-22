<?php
/**
 * Quokka – invitations in emails (iCalendar, RFC 5545): the event read from an .ics, in the local time zone, and
 * the reply to the organiser (iTIP, RFC 5546: METHOD:REPLY with the attendee's answer).
 *
 * Plain functions, no SnappyMail inside: tests/ics_test.php runs them alone.
 */
class QuokkaIcs
{
	const MAX_TEXT = 2000;

	/** Windows time zone names (Outlook, Exchange) → IANA, the usual ones */
	const WINDOWS_ZONES = array(
		'w. europe standard time' => 'Europe/Rome', 'romance standard time' => 'Europe/Paris',
		'central europe standard time' => 'Europe/Budapest', 'central european standard time' => 'Europe/Warsaw',
		'gmt standard time' => 'Europe/London', 'greenwich standard time' => 'Atlantic/Reykjavik',
		'e. europe standard time' => 'Europe/Chisinau', 'fle standard time' => 'Europe/Kiev', 'gtb standard time' => 'Europe/Athens',
		'russian standard time' => 'Europe/Moscow', 'utc' => 'UTC', 'coordinated universal time' => 'UTC',
		'eastern standard time' => 'America/New_York', 'central standard time' => 'America/Chicago',
		'mountain standard time' => 'America/Denver', 'pacific standard time' => 'America/Los_Angeles',
		'india standard time' => 'Asia/Kolkata', 'china standard time' => 'Asia/Shanghai', 'tokyo standard time' => 'Asia/Tokyo',
		'aus eastern standard time' => 'Australia/Sydney'
	);

	/**
	 * The first event of a calendar text: null when there is none.
	 * [method, uid, sequence, status, summary, location, description, organizer {email, name}, attendees [{email,
	 *  name, partstat}], allDay, start {date, time}, end {date, time}, recurring, lines {NAME: raw line}]
	 */
	public static function parse(string $sText, string $sLocalZone) : ?array
	{
		$sText = \preg_replace("/\r?\n[ \t]/", '', \str_replace("\r\n", "\n", $sText));
		$aStack = array();
		$sMethod = '';
		$aEvent = null;
		$aZones = array();          // TZID => [[kind, offset, dtstart, rrule], …] from VTIMEZONE
		$sZone = '';
		$aSub = null;
		foreach (\explode("\n", $sText) as $sLine) {
			$sLine = \rtrim($sLine, "\r");
			if ('' === \trim($sLine)) {
				continue;
			}
			[$sName, $aParams, $sValue] = self::line($sLine);
			if ('BEGIN' === $sName) {
				$aStack[] = \strtoupper($sValue);
				if ('VEVENT' === \strtoupper($sValue) && null === $aEvent) {
					$aEvent = array();
				} else if (\in_array(\strtoupper($sValue), array('STANDARD', 'DAYLIGHT'), true)) {
					$aSub = array('kind' => \strtoupper($sValue));
				}
				continue;
			}
			if ('END' === $sName) {
				$sEnd = \array_pop($aStack);
				if ('VEVENT' === $sEnd && null !== $aEvent && !isset($aEvent['__done'])) {
					$aEvent['__done'] = true;
				} else if (('STANDARD' === $sEnd || 'DAYLIGHT' === $sEnd) && $aSub && $sZone) {
					$aZones[$sZone][] = $aSub;
					$aSub = null;
				}
				continue;
			}
			$sIn = \end($aStack) ?: '';
			if ('VCALENDAR' === $sIn && 'METHOD' === $sName) {
				$sMethod = \strtoupper(\trim($sValue));
			} else if ('VTIMEZONE' === $sIn && 'TZID' === $sName) {
				$sZone = $sValue;
			} else if (('STANDARD' === $sIn || 'DAYLIGHT' === $sIn) && $aSub !== null) {
				$aSub[$sName] = $sValue;
			} else if ('VEVENT' === $sIn && null !== $aEvent && !isset($aEvent['__done'])) {
				$aEvent[$sName][] = array($aParams, $sValue, $sLine);
			}
		}
		if (!$aEvent || empty($aEvent['DTSTART'])) {
			return null;
		}
		$fText = fn($k) => isset($aEvent[$k]) ? \mb_substr(self::unescape($aEvent[$k][0][1]), 0, self::MAX_TEXT) : '';
		$oLocal = self::zone($sLocalZone, array()) ?: new \DateTimeZone('UTC');

		[$oStart, $bAllDay] = self::when($aEvent['DTSTART'][0], $aZones, $oLocal);
		if (!$oStart) {
			return null;
		}
		$oEnd = null;
		if (!empty($aEvent['DTEND'])) {
			[$oEnd] = self::when($aEvent['DTEND'][0], $aZones, $oLocal);
		} else if (!empty($aEvent['DURATION'])) {
			try {
				$oEnd = $oStart->add(new \DateInterval(\ltrim(\trim($aEvent['DURATION'][0][1]), '+')));
			} catch (\Throwable $oException) {
				$oEnd = null;
			}
		}
		if (!$oEnd || $oEnd <= $oStart) {
			$oEnd = $bAllDay ? $oStart->modify('+1 day') : $oStart->modify('+1 hour');
		}
		if ($bAllDay) {
			$oEnd = $oEnd->modify('-1 day');          // DTEND of a whole day is the day after
		}

		$fPerson = function (array $aItem) : array {
			[$aParams, $sValue] = $aItem;
			return array(
				'email' => \strtolower(\preg_replace('/^mailto:/i', '', \trim($sValue))),
				'name' => isset($aParams['CN']) ? self::unescape($aParams['CN']) : '',
				'partstat' => \strtoupper($aParams['PARTSTAT'] ?? 'NEEDS-ACTION')
			);
		};
		$aLines = array();
		foreach (array('UID', 'DTSTART', 'DTEND', 'DURATION', 'SUMMARY', 'ORGANIZER', 'RECURRENCE-ID', 'SEQUENCE') as $sKey) {
			if (isset($aEvent[$sKey])) {
				$aLines[$sKey] = $aEvent[$sKey][0][2];
			}
		}
		return array(
			'method' => $sMethod ?: 'PUBLISH',
			'uid' => \trim($fText('UID')),
			'sequence' => (int) $fText('SEQUENCE'),
			'status' => \strtoupper(\trim($fText('STATUS'))),
			'summary' => \trim($fText('SUMMARY')),
			'location' => \trim($fText('LOCATION')),
			'description' => \trim($fText('DESCRIPTION')),
			'organizer' => isset($aEvent['ORGANIZER']) ? $fPerson($aEvent['ORGANIZER'][0]) : null,
			'attendees' => \array_map($fPerson, $aEvent['ATTENDEE'] ?? array()),
			'allDay' => $bAllDay,
			'start' => array('date' => $oStart->format('Y-m-d'), 'time' => $bAllDay ? null : $oStart->format('H:i')),
			'end' => array('date' => $oEnd->format('Y-m-d'), 'time' => $bAllDay ? null : $oEnd->format('H:i')),
			'recurring' => !empty($aEvent['RRULE']),
			'lines' => $aLines
		);
	}

	/** "NAME;P1=a;P2="b:c":value" → [NAME, {P1: a, P2: b:c}, value] */
	private static function line(string $sLine) : array
	{
		$iColon = -1;
		$bQuoted = false;
		for ($i = 0, $n = \strlen($sLine); $i < $n; ++$i) {
			if ('"' === $sLine[$i]) {
				$bQuoted = !$bQuoted;
			} else if (':' === $sLine[$i] && !$bQuoted) {
				$iColon = $i;
				break;
			}
		}
		if (0 > $iColon) {
			return array(\strtoupper($sLine), array(), '');
		}
		$sHead = \substr($sLine, 0, $iColon);
		\preg_match_all('/;([^=;]+)=("[^"]*"|[^;]*)/', $sHead, $aMatches, \PREG_SET_ORDER);
		$aParams = array();
		foreach ($aMatches as $aMatch) {
			$aParams[\strtoupper($aMatch[1])] = \trim($aMatch[2], '"');
		}
		return array(\strtoupper(\explode(';', $sHead, 2)[0]), $aParams, \substr($sLine, $iColon + 1));
	}

	public static function unescape(string $s) : string
	{
		return \strtr($s, array('\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\'));
	}

	/** [DateTimeImmutable in the local zone, all day] of DTSTART/DTEND */
	private static function when(array $aItem, array $aZones, \DateTimeZone $oLocal) : array
	{
		[$aParams, $sValue] = $aItem;
		$sValue = \trim($sValue);
		if (\preg_match('/^(\d{4})(\d{2})(\d{2})$/', $sValue, $m) || 'DATE' === \strtoupper($aParams['VALUE'] ?? '')) {
			$o = \DateTimeImmutable::createFromFormat('!Ymd', \substr($sValue, 0, 8), $oLocal);
			return array($o ?: null, true);
		}
		if (!\preg_match('/^(\d{8})T(\d{4})(\d{2})?(Z?)$/', $sValue, $m)) {
			return array(null, false);
		}
		$sStamp = $m[1] . $m[2] . ($m[3] ?: '00');
		if ('Z' === $m[4]) {
			$oZone = new \DateTimeZone('UTC');
		} else if (isset($aParams['TZID'])) {
			$oZone = self::zone($aParams['TZID'], $aZones[$aParams['TZID']] ?? array(), $sStamp) ?: $oLocal;
		} else {
			$oZone = $oLocal;                          // "floating": the same clock time wherever one is
		}
		$o = \DateTimeImmutable::createFromFormat('YmdHis', $sStamp, $oZone);
		return array($o ? $o->setTimezone($oLocal) : null, false);
	}

	/** A time zone from its name (IANA, Windows) or, failing that, from the VTIMEZONE that came with it */
	private static function zone(string $sName, array $aRules, string $sStamp = '') : ?\DateTimeZone
	{
		$sName = \trim($sName, " \"/");
		try {
			if ('' !== $sName && \in_array($sName, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
				return new \DateTimeZone($sName);
			}
		} catch (\Throwable $oException) {
		}
		$sWin = self::WINDOWS_ZONES[\strtolower($sName)] ?? '';
		if ($sWin) {
			return new \DateTimeZone($sWin);
		}
		// "(UTC+01:00) Amsterdam, Berlin…" and other names: the offset of the VTIMEZONE rule in force at that date
		$sOffset = self::ruleOffset($aRules, $sStamp);
		if (!$sOffset && \preg_match('/(?:UTC|GMT)\s*([+-]\d{1,2}):?(\d{2})?/i', $sName, $m)) {
			$sOffset = \sprintf('%+03d%02d', (int) $m[1], (int) ($m[2] ?? 0));
		}
		return $sOffset ? new \DateTimeZone($sOffset) : null;
	}

	/** TZOFFSETTO of the STANDARD or DAYLIGHT part in force at YmdHis (yearly rules: BYMONTH and BYDAY like -1SU) */
	private static function ruleOffset(array $aRules, string $sStamp) : string
	{
		if (!$aRules) {
			return '';
		}
		$iYear = (int) \substr($sStamp ?: \gmdate('YmdHis'), 0, 4);
		$aChanges = array();
		foreach ($aRules as $aRule) {
			$sTo = \trim($aRule['TZOFFSETTO'] ?? '');
			if (!\preg_match('/^[+-]\d{4}$/', $sTo)) {
				continue;
			}
			$sStart = \preg_replace('/[^0-9T]/', '', $aRule['DTSTART'] ?? '');
			$sTime = \strlen($sStart) >= 15 ? \substr($sStart, 9, 6) : '000000';
			if (\preg_match('/BYMONTH=(\d+)/', $aRule['RRULE'] ?? '', $mm) && \preg_match('/BYDAY=(-?\d)([A-Z]{2})/', $aRule['RRULE'], $md)) {
				$aDays = array('SU' => 'sunday', 'MO' => 'monday', 'TU' => 'tuesday', 'WE' => 'wednesday', 'TH' => 'thursday',
					'FR' => 'friday', 'SA' => 'saturday');
				$sMonth = \date('F Y', \mktime(0, 0, 0, (int) $mm[1], 1, $iYear));
				$o = (int) $md[1] < 0
					? new \DateTimeImmutable("last {$aDays[$md[2]]} of {$sMonth}")
					: new \DateTimeImmutable(\sprintf('%s %s of %s', array('', 'first', 'second', 'third', 'fourth')[(int) $md[1]] ?? 'first', $aDays[$md[2]], $sMonth));
				$aChanges[] = array($o->format('Ymd') . $sTime, $sTo);
			} else {
				$aChanges[] = array(\substr($sStart, 0, 8) . $sTime, $sTo);
			}
		}
		if (!$aChanges) {
			return '';
		}
		\usort($aChanges, fn($a, $b) => \strcmp($a[0], $b[0]));
		$sAt = \str_replace('T', '', $sStamp ?: \gmdate('YmdHis'));
		$sOffset = \end($aChanges)[1];                  // before the first change of the year: the last one of last year
		foreach ($aChanges as [$sWhen, $sTo]) {
			if (\strcmp($sAt, $sWhen) >= 0) {
				$sOffset = $sTo;
			}
		}
		return $sOffset;
	}

	/**
	 * The reply to the organiser: METHOD:REPLY with this attendee's answer (ACCEPTED, TENTATIVE, DECLINED), the
	 * event's own lines (UID, start, end, title, organiser) as they came.
	 */
	public static function reply(array $aEvent, string $sEmail, string $sName, string $sPartstat) : string
	{
		$aOut = array('BEGIN:VCALENDAR', 'PRODID:-//Quokka//Quokka//EN', 'VERSION:2.0', 'CALSCALE:GREGORIAN', 'METHOD:REPLY',
			'BEGIN:VEVENT');
		foreach (array('UID', 'RECURRENCE-ID', 'SEQUENCE', 'DTSTART', 'DTEND', 'DURATION', 'SUMMARY', 'ORGANIZER') as $sKey) {
			if (isset($aEvent['lines'][$sKey])) {
				$aOut[] = $aEvent['lines'][$sKey];
			}
		}
		$aOut[] = 'DTSTAMP:' . \gmdate('Ymd\THis\Z');
		$sCn = '' !== $sName ? ';CN="' . \str_replace(array('"', "\r", "\n"), '', $sName) . '"' : '';
		$aOut[] = "ATTENDEE;PARTSTAT={$sPartstat}{$sCn}:mailto:{$sEmail}";
		$aOut[] = 'END:VEVENT';
		$aOut[] = 'END:VCALENDAR';
		return \implode("\r\n", \array_map(array(self::class, 'fold'), $aOut)) . "\r\n";
	}

	/** Lines of at most 75 octets, continued with a space (never in the middle of a UTF-8 character) */
	public static function fold(string $sLine) : string
	{
		$aParts = array();
		while (\strlen($sLine) > 75) {
			$iCut = 75;
			while ($iCut > 1 && (\ord($sLine[$iCut]) & 0xC0) === 0x80) {
				--$iCut;
			}
			$aParts[] = \substr($sLine, 0, $iCut);
			$sLine = ' ' . \substr($sLine, $iCut);
		}
		$aParts[] = $sLine;
		return \implode("\r\n", $aParts);
	}
}

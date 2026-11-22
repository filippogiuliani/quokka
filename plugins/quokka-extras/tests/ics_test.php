<?php
// php tests/ics_test.php  (from plugins/quokka-extras): the invitations Quokka reads, as Google and Outlook send them
require __DIR__ . '/../Ics.php';
$fails = 0;
function check($name, $got, $want) {
	global $fails;
	if ($got !== $want) { $fails++; echo "FAIL $name\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n"; }
	else echo "ok   $name\n";
}
$google = "BEGIN:VCALENDAR\r\nPRODID:-//Google Inc//Google Calendar 70.9054//EN\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Rome\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Rome:20261203T150000\r\nDTEND;TZID=Europe/Rome:20261203T160000\r\nDTSTAMP:20261121T120000Z\r\nORGANIZER;CN=Giulia Rossi:mailto:giulia@ufficio.example\r\nUID:abc123@google.com\r\nATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=\r\n TRUE;CN=Filippo;X-NUM-GUESTS=0:mailto:Lavoro@Famiglia.test\r\nSEQUENCE:2\r\nSUMMARY:Riunione\\, budget 2027\r\nLOCATION:Sala 2\; piano terra\r\nDESCRIPTION:Ordine del giorno:\\n1. budget\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$e = QuokkaIcs::parse($google, 'Europe/Rome');
check('google method', $e['method'], 'REQUEST');
check('google when', array($e['start'], $e['end'], $e['allDay']), array(array('date' => '2026-12-03', 'time' => '15:00'), array('date' => '2026-12-03', 'time' => '16:00'), false));
check('google text', array($e['summary'], $e['location'], $e['description']), array('Riunione, budget 2027', 'Sala 2; piano terra', "Ordine del giorno:\n1. budget"));
check('google people', array($e['organizer']['email'], $e['organizer']['name'], $e['attendees'][0]['email'], $e['attendees'][0]['partstat']),
	array('giulia@ufficio.example', 'Giulia Rossi', 'lavoro@famiglia.test', 'NEEDS-ACTION'));
check('google uid seq', array($e['uid'], $e['sequence']), array('abc123@google.com', 2));

$outlook = "BEGIN:VCALENDAR\nMETHOD:REQUEST\nBEGIN:VTIMEZONE\nTZID:W. Europe Standard Time\nBEGIN:STANDARD\nDTSTART:16010101T030000\nTZOFFSETFROM:+0200\nTZOFFSETTO:+0100\nRRULE:FREQ=YEARLY;INTERVAL=1;BYDAY=-1SU;BYMONTH=10\nEND:STANDARD\nBEGIN:DAYLIGHT\nDTSTART:16010101T020000\nTZOFFSETFROM:+0100\nTZOFFSETTO:+0200\nRRULE:FREQ=YEARLY;INTERVAL=1;BYDAY=-1SU;BYMONTH=3\nEND:DAYLIGHT\nEND:VTIMEZONE\nBEGIN:VEVENT\nUID:040000008200E00074C5B7101A82E008\nSUMMARY;LANGUAGE=it-IT:Colloquio\nDTSTART;TZID=W. Europe Standard Time:20260710T093000\nDTEND;TZID=W. Europe Standard Time:20260710T100000\nORGANIZER;CN=\"Rossi, Mario\":mailto:mario@ditta.example\nEND:VEVENT\nEND:VCALENDAR\n";
$e = QuokkaIcs::parse($outlook, 'Europe/Rome');
check('outlook windows zone', array($e['start']['time'], $e['end']['time'], $e['organizer']['name']), array('09:30', '10:00', 'Rossi, Mario'));
// the same with a zone name nobody knows: its VTIMEZONE rules (summer time in July: +02:00)
$e = QuokkaIcs::parse(str_replace(array('TZID=W. Europe Standard Time', 'TZID:W. Europe Standard Time'),
	array('TZID="(UTC+01:00) Amsterdam, Berlin, Bern"', 'TZID:(UTC+01:00) Amsterdam, Berlin, Bern'), $outlook), 'Europe/Rome');
check('custom zone by its rules, summer', $e['start']['time'], '09:30');
$e = QuokkaIcs::parse(str_replace(array('W. Europe Standard Time', '20260710'), array('Mia zona', '20261210'), $outlook), 'Europe/Rome');
check('custom zone by its rules, winter', $e['start']['time'], '09:30');
$e = QuokkaIcs::parse(str_replace(array('W. Europe Standard Time', '20260710'), array('Mia zona', '20261210'), $outlook), 'Europe/London');
check('custom zone, shown in London', $e['start']['time'], '08:30');

$utc = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:u1\nSUMMARY:Webinar\nDTSTART:20260115T170000Z\nDURATION:PT90M\nEND:VEVENT\nEND:VCALENDAR";
$e = QuokkaIcs::parse($utc, 'Europe/Rome');
check('utc + duration', array($e['method'], $e['start']['time'], $e['end']['time']), array('PUBLISH', '18:00', '19:30'));

$allday = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:d1\nSUMMARY:Gita\nDTSTART;VALUE=DATE:20261224\nDTEND;VALUE=DATE:20261227\nRRULE:FREQ=YEARLY\nEND:VEVENT\nEND:VCALENDAR";
$e = QuokkaIcs::parse($allday, 'Europe/Rome');
check('all day, end inclusive', array($e['allDay'], $e['start']['date'], $e['end']['date'], $e['start']['time'], $e['recurring']), array(true, '2026-12-24', '2026-12-26', null, true));

$floating = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:f1\nSUMMARY:Cena\nDTSTART:20261205T200000\nEND:VEVENT\nEND:VCALENDAR";
$e = QuokkaIcs::parse($floating, 'Europe/Rome');
check('floating, no end: one hour', array($e['start']['time'], $e['end']['time']), array('20:00', '21:00'));
check('no event', QuokkaIcs::parse("BEGIN:VCALENDAR\nEND:VCALENDAR", 'Europe/Rome'), null);

$e = QuokkaIcs::parse($google, 'Europe/Rome');
$reply = QuokkaIcs::reply($e, 'lavoro@famiglia.test', 'Filippo "F"', 'ACCEPTED');
check('reply method', strpos($reply, "METHOD:REPLY\r\n") !== false, true);
check('reply keeps the event', strpos($reply, "UID:abc123@google.com\r\n") !== false && strpos($reply, "DTSTART;TZID=Europe/Rome:20261203T150000\r\n") !== false
	&& strpos($reply, "SEQUENCE:2\r\n") !== false, true);
check('reply attendee', strpos(str_replace("\r\n ", '', $reply), 'ATTENDEE;PARTSTAT=ACCEPTED;CN="Filippo F":mailto:lavoro@famiglia.test') !== false, true);
check('reply lines folded', max(array_map('strlen', explode("\r\n", $reply))) <= 75, true);
$long = QuokkaIcs::fold('SUMMARY:' . str_repeat('è', 60));
check('fold keeps UTF-8 whole', mb_check_encoding(str_replace("\r\n ", '', $long), 'UTF-8') && max(array_map('strlen', explode("\r\n", $long))) <= 75, true);
echo $fails ? "$fails FAILED\n" : "all passed\n";
exit($fails ? 1 : 0);

<?php

declare(strict_types=1);

// Reine Logik: Register, Gateway-Antwort, Zeitraster, Cloud-API und Auftragsliste.
require __DIR__ . '/bootstrap.php';
foreach (['AlphaDataFlow', 'AlphaRegisters', 'AlphaModbusGateway', 'AlphaTimeGrid', 'AlphaCloudApi', 'AlphaCloudQueue', 'AlphaEnergy'] as $class) {
    require_once __DIR__ . "/../libs/$class.php";
}

$reg = require __DIR__ . '/fixtures/registers.php';
$block = static function (string $name) use ($reg): string {
    $def = AlphaRegisters::BLOCKS[$name];
    $out = '';
    for ($i = 0; $i < $def['quantity']; $i++) {
        $out .= pack('n', $reg[$def['address'] + $i]);
    }
    return $out;
};

section('Register dekodieren');
$grid = AlphaRegisters::decode('grid', $block('grid'));
check($grid['grid_feed_in_energy'] === 1234.56 && $grid['grid_consumption_energy'] === 2345.67, 'Zähler als uint32 mit 0,01 kWh');
check($grid['grid_power'] === -500 && $grid['grid_power_l1'] === -400 && $grid['grid_power_l2'] === 150, 'Leistungen als int32 mit Vorzeichen');
$bat = AlphaRegisters::decode('battery', $block('battery'));
check($bat['battery_soc'] === 65.5 && $bat['battery_voltage'] === 51.2 && $bat['battery_current'] === -10.5 && $bat['battery_power'] === -1500,
    'Speicher: SOC 65,5 %, 51,2 V, -10,5 A, -1500 W (int16 mit Vorzeichen)');
$inv = AlphaRegisters::decode('inverter', $block('inverter'));
check($inv['inverter_power'] === 2000 && $inv['pv1_power'] === 1800 && $inv['pv2_power'] === 1700 && $inv['pv_energy'] === 9876.5 && $inv['inverter_mode'] === 2,
    'Wechselrichter: Leistung, Strings, Ertrag, Modus (letztes Register des Blocks)');
check(($inv['inverter_power_l1'] ?? null) === 800 && ($inv['inverter_power_l2'] ?? null) === 700 && ($inv['inverter_power_l3'] ?? null) === 500,
    'Wechselrichter: Leistung je Phase aus 1030, 1032, 1034 (auch ohne Cloud)');
check(AlphaRegisters::decode('grid', substr($block('grid'), 2)) === [] && AlphaRegisters::decode('nix', '') === [], 'falsche Länge oder unbekannter Block ergibt nichts');

section('Abgeleitete Werte');
$all = AlphaRegisters::derive($grid + $bat + $inv + AlphaRegisters::decode('pvmeter', $block('pvmeter')));
check($all['pv_power'] === 3500, 'PV-Leistung ist die Summe der Strings');
check($all['load_power'] === 1500, 'Verbrauch = Netz (-500) + Wechselrichter (2000) + PV-Zähler (0)');
check($all['grid_frequency'] === 50.01 && !isset($all['grid_frequency_raw']), 'Frequenz 0,01 Hz erkannt');
check(AlphaRegisters::derive(['grid_frequency_raw' => 499])['grid_frequency'] === 49.9, 'Frequenz 0,1 Hz erkannt');
check($all['battery_state'] === 1, 'Speicher lädt bei negativer Leistung');
check(AlphaRegisters::derive(['battery_power' => 800])['battery_state'] === 2 && AlphaRegisters::derive(['battery_power' => -10])['battery_state'] === 0, 'entlädt bei positiver Leistung, ruht unter 30 W');
check(!isset(AlphaRegisters::derive($bat)['load_power']) && !isset(AlphaRegisters::derive($bat)['pv_power']), 'ohne Netz- und Wechselrichterblock kein errechneter Verbrauch');
$parts = AlphaRegisters::split($all);
check(isset($parts['battery']['battery_soc'], $parts['battery']['battery_state']) && !isset($parts['inverter']['battery_soc']) && isset($parts['inverter']['pv_power']),
    'Aufteilung auf Wechselrichter und Speicher');

section('Antwort des ModBus Gateways');
$raw = pack('CC', 3, 4) . "\x00\x01\x00\x02";
check(AlphaModbusGateway::payload($raw, 2) === "\x00\x01\x00\x02", 'roh: zwei Kopfbytes entfernt');
check(AlphaModbusGateway::payload(strtoupper(bin2hex($raw)), 2) === "\x00\x01\x00\x02", 'hex-kodierte Antwort wird ebenfalls gelesen');
check(AlphaModbusGateway::payload(false, 2) === null && AlphaModbusGateway::payload('', 2) === null && AlphaModbusGateway::payload($raw, 3) === null,
    'false, leer und falsche Länge ergeben null');
$req = json_decode(AlphaModbusGateway::request(16, 19), true);
check($req === ['DataID' => AlphaDataFlow::MODBUS_TX, 'Function' => 3, 'Address' => 16, 'Quantity' => 19, 'Data' => ''], 'Lesepaket wie gemessen');

section('Zeitraster');
check(AlphaTimeGrid::snap('06:07') === '06:00' && AlphaTimeGrid::snap('06:08') === '06:15' && AlphaTimeGrid::snap('23:53') === '23:45', 'rundet auf 15 Minuten, 23:53 bleibt am selben Tag');
check(AlphaTimeGrid::snap('24:00') === '00:00' && AlphaTimeGrid::snap('quatsch') === '00:00' && !AlphaTimeGrid::valid('7:5'), 'ungültige Zeiten werden 00:00');
$ts = AlphaTimeGrid::toTimestamp('13:30');
check(date('Y-m-d H:i', $ts) === '2000-01-01 13:30' && AlphaTimeGrid::toClock($ts) === '13:30', 'Uhrzeit liegt auf dem festen Wintertag und kommt zurück');
check(AlphaTimeGrid::toClock((int) mktime(17, 52, 30, 7, 14, 2026)) === '17:45', 'Zeitstempel eines Sommertags liefert dieselbe Ortszeit');

section('Cloud-API');
check(AlphaCloudApi::sign('app', 'secret', 1700000000) === hash('sha512', 'appsecret1700000000'), 'Signatur = sha512(appId + appSecret + timeStamp)');
$headers = AlphaCloudApi::headers('app', 'secret', 1700000000);
check($headers[0] === 'appId: app' && $headers[1] === 'timeStamp: 1700000000' && str_starts_with($headers[2], 'sign: '), 'Kopfzeilen appId, timeStamp, sign');
$r = AlphaCloudApi::build('evstatus:EV 1/2', 'AL1');
check($r['method'] === 'GET' && $r['url'] === AlphaCloudApi::BASE_URL . '/getEvChargerStatusBySn?sysSn=AL1&evchargerSn=EV+1%2F2' && $r['body'] === null, 'Leseaufruf mit kodierter Seriennummer');
$r = AlphaCloudApi::build('set_control:EV1', 'AL1', ['start' => true]);
check($r['method'] === 'POST' && json_decode((string) $r['body'], true) === ['sysSn' => 'AL1', 'evchargerSn' => 'EV1', 'controlMode' => 1], 'Wallbox starten');
$full = ['grid_charge' => true, 'charge_limit' => 90, 'charge_start1' => '01:00', 'charge_end1' => '05:00', 'charge_start2' => '00:00', 'charge_end2' => '00:00'];
$r = AlphaCloudApi::build('set_charge', 'AL1', $full);
check(json_decode((string) $r['body'], true) === ['sysSn' => 'AL1', 'gridCharge' => 1, 'batHighCap' => 90, 'timeChaf1' => '01:00', 'timeChae1' => '05:00', 'timeChaf2' => '00:00', 'timeChae2' => '00:00'],
    'Ladekonfiguration mit allen Feldern der Cloud');
check(AlphaCloudApi::build('unbekannt', 'AL1') === null, 'unbekannter Auftrag ergibt nichts');
check(AlphaCloudApi::fromCloud('charge', ['gridCharge' => 1, 'batHighCap' => 90.0, 'timeChaf1' => '01:00', 'timeChae1' => '05:00:00', 'timeChaf2' => '00:00', 'timeChae2' => '00:00']) === $full,
    'Konfiguration aus der Cloud wird neutral (bool, int, HH:mm)');
check(AlphaCloudApi::fromCloud('charge', ['gridCharge' => 1]) === [] && AlphaCloudApi::fromCloud('charge', null) === [], 'unvollständige Antwort ergibt nichts');
check(AlphaCloudApi::configOf('discharge_limit') === 'discharge' && AlphaCloudApi::configOf('charge_end2') === 'charge' && AlphaCloudApi::configOf('x') === '', 'Zuordnung der Einstellungen');
$ok = AlphaCloudApi::parse(200, '{"code":200,"msg":"Success","data":{"a":1}}');
$err = AlphaCloudApi::parse(200, '{"code":6007,"msg":"x","data":null}');
check($ok['ok'] && $ok['data'] === ['a' => 1] && !$err['ok'] && $err['code'] === 6007 && AlphaCloudApi::isAuthError(6007) && !AlphaCloudApi::isAuthError(6053), 'Erfolg und Fehlercode erkannt');
check(AlphaCloudApi::parse(200, '{"code":7001,"info":"aus info","data":null}')['error'] === 'aus info' && AlphaCloudApi::parse(200, '{"code":7001,"msg":"aus msg"}')['error'] === 'aus msg'
    && AlphaCloudApi::parse(200, '{"code":7001}')['error'] === 'Error 7001', 'Fehlertext unbekannter Codes aus msg oder info');
check(AlphaCloudApi::parse(200, '{"code":6012,"info":"x"}')['error'] === 'The AppID is missing' && AlphaCloudApi::isAuthError(6012), 'fehlende AppID hat einen eigenen Text');
check(AlphaCloudApi::parse(0, '', 'timeout')['error'] === 'timeout' && !AlphaCloudApi::parse(502, '<html>')['ok'], 'Verbindungsfehler und Nicht-JSON sind Fehler');

section('Energie ohne Cloud');
$c = ['grid_consumption_energy' => 100.0, 'grid_feed_in_energy' => 40.0, 'pv_energy' => 200.0, 'battery_charge_energy' => 30.0, 'battery_discharge_energy' => 20.0];
check(AlphaEnergy::counters($c + ['x' => 1]) === $c && AlphaEnergy::counters(['pv_energy' => 0.0] + $c) === null && AlphaEnergy::counters(array_slice($c, 1)) === null,
    'nur vollständige, positive Zählerstände');
check(AlphaEnergy::totals($c) === ['load_energy' => 250.0, 'self_sufficiency' => 60.0, 'self_consumption' => 80.0], 'Verbrauch als Bilanz, Autarkie und Eigenverbrauch');
[$t, $start] = AlphaEnergy::today($c, [], '2026-11-16');
check($t['inverter']['pv_energy_today'] === 0.0 && $start === ['date' => '2026-11-16', 'counters' => $c], 'erster Lauf merkt den Tagesbeginn');
[$t, $start] = AlphaEnergy::today(['pv_energy' => 205.5, 'battery_charge_energy' => 31.25] + $c, $start, '2026-11-16');
check($t['inverter']['pv_energy_today'] === 5.5 && $t['battery']['battery_charge_today'] === 1.25 && $t['inverter']['grid_feed_in_today'] === 0.0 && $start['counters'] === $c,
    'Tageswert = Abstand zum Tagesbeginn');
[$t, $next] = AlphaEnergy::today(['pv_energy' => 210.0] + $c, $start, '2026-11-17');
check($t['inverter']['pv_energy_today'] === 0.0 && $next['date'] === '2026-11-17' && $next['counters']['pv_energy'] === 210.0, 'neuer Tag beginnt bei 0');
[$t, $next] = AlphaEnergy::today(['pv_energy' => 150.0] + $c, $start, '2026-11-16');
check($t['inverter']['pv_energy_today'] === 0.0 && $next['counters']['pv_energy'] === 150.0, 'zurückgesprungener Zähler setzt den Tagesbeginn neu');
$day = ['pv_energy_today' => 10.0, 'grid_feed_in_today' => 2.0, 'grid_consumption_today' => 5.0, 'battery_charge_today' => 4.0, 'battery_discharge_today' => 3.0];
check(AlphaEnergy::todayTotals($day) === ['load_energy_today' => 12.0, 'self_consumption_today' => 80.0, 'self_sufficiency_today' => 58.3], 'Verbrauch, Eigenverbrauch und Autarkie heute');
check(AlphaEnergy::todayTotals(['pv_energy_today' => 0.0] + $day) === ['load_energy_today' => 2.0, 'self_sufficiency_today' => 0.0]
    && AlphaEnergy::todayTotals(array_slice($day, 1)) === [], 'ohne PV keine Eigenverbrauchsquote, unvollständig ergibt nichts');

section('Auftragsliste');
$s = AlphaCloudQueue::fresh();
$reads = ['a' => 60, 'b' => 300];
check(AlphaCloudQueue::next($s, $reads, 1000) === 'a', 'nie gelaufene Aufträge sind fällig, der erste zuerst');
$s = AlphaCloudQueue::done($s, 'a', 1000, false);
check(AlphaCloudQueue::next($s, $reads, 1005) === null && AlphaCloudQueue::next($s, $reads, 1010) === 'b', 'Mindestabstand 10 s, dann der nächste');
$s = AlphaCloudQueue::done($s, 'b', 1010, false);
check(AlphaCloudQueue::next($s, $reads, 1030) === null && AlphaCloudQueue::next($s, $reads, 1060) === 'a', 'erst wieder nach dem Intervall');
$s = AlphaCloudQueue::done($s, 'a', 1060, true);
check($s['nextAllowed'] === 1120 && ($s['last']['a'] ?? 0) === 1000, 'zu viele Anfragen: 60 s Pause, der Auftrag gilt nicht als gelaufen');
$s = AlphaCloudQueue::done($s, 'a', 1120, true);
check($s['nextAllowed'] === 1240, 'Pause verdoppelt sich');
$s = AlphaCloudQueue::done($s, 'a', 1240, false);
check($s['backoff'] === 0 && $s['nextAllowed'] === 1250, 'Erfolg setzt die Drosselung zurück');
$s = AlphaCloudQueue::enqueue($s, 'set_x', ['p' => 1], 1300, 20);
$s = AlphaCloudQueue::enqueue($s, 'set_x', ['q' => 2], 1310, 20);
check($s['pending']['set_x']['args'] === ['p' => 1, 'q' => 2] && AlphaCloudQueue::next($s, [], 1325) === null && AlphaCloudQueue::next($s, [], 1330) === 'set_x',
    'Schreibaufträge werden gebündelt und warten die Ruhezeit ab der letzten Änderung ab');
check(AlphaCloudQueue::next($s, $reads, 1400, ['set_x']) === 'a' && AlphaCloudQueue::next($s, $reads, 1400) === 'set_x', 'gesperrter Schreibauftrag lässt Leseaufträge vor, sonst geht Schreiben vor');
$s = AlphaCloudQueue::done($s, 'a', 1390, false);
$s = AlphaCloudQueue::force($s, 'b', 1405);
check(AlphaCloudQueue::next($s, $reads, 1404, ['set_x']) === null && AlphaCloudQueue::next($s, $reads, 1405, ['set_x']) === 'b', 'erzwungenes Lesen kommt zu seinem Zeitpunkt, nicht vorher');
$f = AlphaCloudQueue::done($s, 'b', 1405, false, false);
check($f['force']['b'] === 1465 && !isset(AlphaCloudQueue::done($s, 'b', 1405, false, true)['force']['b']), 'scheitert ein erzwungenes Lesen, wird es nach 60 s wiederholt');
[$shown, $s] = AlphaCloudQueue::overlay($s, 'set_x', ['p' => 0, 'q' => 0, 'r' => 9], 1400);
check($shown === ['p' => 1, 'q' => 2, 'r' => 9] && isset($s['pending']['set_x']), 'ungesendeter Sollwert überdeckt den gelesenen Stand');
$s = AlphaCloudQueue::sent($s, 'set_x', 1400);
check(AlphaCloudQueue::next($s, [], 1500) === null, 'gesendeter Auftrag läuft nicht noch einmal');
[$shown, $s2] = AlphaCloudQueue::overlay($s, 'set_x', ['p' => 0, 'q' => 2, 'r' => 9], 1420);
check($shown['p'] === 1 && isset($s2['pending']['set_x']), 'alter Stand kurz nach dem Schreiben: Sollwert bleibt stehen');
[$shown, $s2] = AlphaCloudQueue::overlay($s, 'set_x', ['p' => 1, 'q' => 2, 'r' => 9], 1420);
check($shown['p'] === 1 && !isset($s2['pending']['set_x']), 'bestätigter Stand räumt den Auftrag ab');
[$shown, $s2] = AlphaCloudQueue::overlay($s, 'set_x', ['p' => 0, 'q' => 2, 'r' => 9], 1521);
check($shown['p'] === 0 && !isset($s2['pending']['set_x']), 'nach 120 s ohne Bestätigung gilt der gelesene Stand');

section('Cloud-Leistungen');
check(AlphaCloudApi::powerValues(['soc' => 80, 'pbat' => -900, 'ppv' => '2100', 'pgrid' => 300, 'pload' => 1500, 'ppvDetail' => ['ppv1' => 2100], 'pgridDetail' => ['pmeterL2' => 300]])
    === ['battery_soc' => 80.0, 'battery_power' => -900.0, 'pv_power' => 2100.0, 'grid_power' => 300.0, 'load_power' => 1500.0, 'pv1_power' => 2100.0, 'grid_power_l2' => 300.0, 'battery_state' => 1],
    'Felder der Cloud in den Namen der Modbus-Werte, Zahlen auch als Text');
check(AlphaCloudApi::powerValues(null) === [] && AlphaCloudApi::powerValues(['soc' => 'n/a', 'ppvDetail' => 'x']) === [], 'Unsinn ergibt keine Werte');
$sumData = ['epvtotal' => 5.5, 'eload' => 32, 'eoutput' => 0.01, 'einput' => 30.71, 'echarge' => 0.1, 'edischarge' => 0.2,
    'eselfConsumption' => 1, 'eselfSufficiency' => 0.1014, 'totalIncome' => 1.32, 'todayIncome' => null, 'treeNum' => 0.55, 'carbonNum' => 2.75, 'moneyType' => 'EUR'];
$withCounters = AlphaCloudApi::sumValues($sumData, true);
$noCounters = AlphaCloudApi::sumValues($sumData, false);
check($withCounters['inverter']['pv_energy'] === 5.5 && $withCounters['inverter']['grid_consumption_energy'] === 30.71 && $withCounters['battery']['battery_discharge_energy'] === 0.2,
    'Summen: Zählerstände nur im Modus „Nur Cloud“');
check(!isset($noCounters['inverter']['pv_energy']) && $noCounters['battery'] === [] && $noCounters['inverter']['self_sufficiency'] === 10.14 && $noCounters['inverter']['load_energy'] === 32.0,
    'Summen mit Modbus: keine zweite Quelle für die Zähler, Autarkie in Prozent');
check($noCounters['system'] === ['income_total' => 1.32, 'trees' => 0.55, 'co2' => 2.75, 'currency' => 'EUR'], 'Summen: Ertrag ohne leeren Tageswert, Währung');
$today = AlphaCloudApi::todayValues(['epv' => 1.3, 'eOutput' => 0.01, 'eInput' => 30.71, 'eCharge' => 0.1, 'eDischarge' => 0, 'eGridCharge' => 0, 'eChargingPile' => 2.5]);
check($today['inverter']['pv_energy_today'] === 1.3 && $today['battery']['battery_discharge_today'] === 0.0 && $today['wallbox'] === ['energy_today' => 2.5], 'Tageswerte, auch 0');
$ess = AlphaCloudApi::essValues([['sysSn' => 'ANDERE', 'minv' => 'X'], ['sysSn' => 'AL1', 'minv' => 'SMILE-G3-T6-INV', 'popv' => 8, 'cobat' => 7.68, 'usCapacity' => 95, 'emsStatus' => 'Normal']], 'AL1');
check($ess['system']['inverter_model'] === 'SMILE-G3-T6-INV' && $ess['system']['ems_status'] === 'Normal' && $ess['battery']['battery_capacity'] === 7.68 && $ess['battery']['battery_usable_share'] === 95.0,
    'Stammdaten der eigenen Anlage, nicht der ersten in der Liste');
check(AlphaCloudApi::essValues([['sysSn' => 'ANDERE']], 'AL1') === ['battery' => [], 'system' => []], 'fremde Anlage: nichts');
$pw = AlphaCloudApi::powerValues(['prealL1' => 10, 'prealL2' => 39, 'prealL3' => 21]);
check($pw['inverter_power'] === 70.0 && $pw['inverter_power_l2'] === 39.0, 'Wechselrichterleistung = Summe der Phasen');
check(AlphaCloudApi::build('today', 'AL1', ['date' => '2026-11-13'])['url'] === AlphaCloudApi::BASE_URL . '/getOneDateEnergyBySn?sysSn=AL1&queryDate=2026-11-13'
    && AlphaCloudApi::build('ess', 'AL1')['url'] === AlphaCloudApi::BASE_URL . '/getEssList', 'Tagesabfrage mit Datum, Anlagenliste ohne Seriennummer');
$q = AlphaCloudQueue::fresh();
$q['force']['charge'] = 100;
check(AlphaCloudQueue::next($q, ['sum' => 300, 'charge' => 300], 200) === 'charge', 'Zurücklesen nach einer Änderung geht einem nie gelaufenen Abruf vor');
check(AlphaRegisters::batteryState(31) === 2 && AlphaRegisters::batteryState(-31) === 1 && AlphaRegisters::batteryState(30) === 0, 'Speicherzustand ab 30 W');

done();

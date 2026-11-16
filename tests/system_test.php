<?php

declare(strict_types=1);

// System mit Gateway- und Cloud-Attrappe: Modbus-Zyklus, Fehlerfälle, Cloud-Takt, Schreibaufträge.
require __DIR__ . '/bootstrap.php';

$modbusRequests = static fn(FakeModbusGateway $g): string => implode(',', array_map(static fn(array $r): string => $r['address'] . '+' . $r['quantity'], $g->requests));

section('Modbus-Zyklus');
[$sys, $gw] = alphaWorld();
$inv = alphaDevice(ALPHA_INVERTER, $sys);
$bat = alphaDevice(ALPHA_BATTERY, $sys);
check(World::timer($sys, 'Poll')['interval'] === 10000 && World::timer($sys, 'CloudTick')['interval'] === 0, 'Abfrage alle 10 s, Cloud-Takt aus ohne Zugangsdaten');
check(World::value($sys, 'ModbusState') === 0 && World::value($sys, 'CloudState') === 0, 'Zustände vor dem ersten Lauf: nicht verbunden, nicht eingerichtet');
Kernel::advance(10);
check($modbusRequests($gw) === '1024+65,256+40,16+19,161+2,35+20,2048+5,2127+11,2256+6', 'acht Blöcke je Zyklus, die neuen hinten: ' . $modbusRequests($gw));
check(World::value($sys, 'ModbusState') === 1, 'Modbus verbunden');
check(World::value($inv, 'PVPower') === 3500.0 && World::value($inv, 'PV1Power') === 1800.0 && World::value($inv, 'PV2Power') === 1700.0, 'PV gesamt und je String');
check(World::value($inv, 'GridPower') === -500.0 && World::value($inv, 'GridPowerL1') === -400.0 && World::value($inv, 'LoadPower') === 1500.0, 'Netz und Verbrauch');
check(World::value($inv, 'GridFeedInEnergy') === 1234.56 && World::value($inv, 'GridConsumptionEnergy') === 2345.67 && World::value($inv, 'PVEnergy') === 9876.5, 'Zählerstände');
check(World::value($inv, 'Frequency') === 50.01 && World::value($inv, 'InverterPower') === 2000.0 && World::value($inv, 'Mode') === 2, 'Frequenz, Wechselrichterleistung, Modus');
check(World::value($bat, 'SOC') === 65.5 && World::value($bat, 'Power') === -1500.0 && World::value($bat, 'State') === 1, 'Speicher: SOC, Leistung, lädt');
check(World::value($bat, 'ChargeEnergy') === 432.1 && World::value($bat, 'DischargeEnergy') === 321.0 && World::value($bat, 'Voltage') === 51.2 && World::value($bat, 'Current') === -10.5,
    'Speicher: Energien, Spannung, Strom');
check(!in_array('SOC', World::idents($inv), true) && !in_array('PVPower', World::idents($bat), true), 'jedes Gerät bekommt nur seine Werte');

section('Neues Gerät, Zähler, Lücken');
$inv2 = alphaDevice(ALPHA_INVERTER, $sys);
check(World::value($inv2, 'PVPower') === 3500.0, 'neu angelegtes Gerät holt den letzten Stand sofort');
$gw->set32(16, 0);
$gw->set32(18, 100);
$gw->set32(1055, 2000);
Kernel::advance(10);
check(World::value($inv, 'GridFeedInEnergy') === 1234.56 && World::value($inv, 'GridConsumptionEnergy') === 2345.67, 'Zähler: 0 und Rücksprung werden verworfen');
check(World::value($inv, 'PV1Power') === 2000.0 && World::value($inv, 'PVPower') === 3700.0, 'Leistungen folgen sofort');
$gw->set32(18, 234600);
unset($gw->registers[161], $gw->registers[162]);
Kernel::advance(10);
check(World::value($inv, 'GridConsumptionEnergy') === 2346.0 && World::value($sys, 'ModbusState') === 1, 'fehlender PV-Zähler-Block stört die übrigen Werte nicht');
$gw->mode = 'hex';
$gw->set16(258, 700);
Kernel::advance(10);
check(World::value($bat, 'SOC') === 70.0, 'hex-kodierte Gateway-Antwort wird gelesen');

section('Gerät antwortet nicht');
[$sys, $gw] = alphaWorld();
$bat = alphaDevice(ALPHA_BATTERY, $sys);
Kernel::advance(10);
$gw->mode = 'dead';
$gw->requests = [];
Kernel::advance(10);
check(World::value($sys, 'ModbusState') === 2 && World::value($bat, 'SOC') === 65.5, 'Zustand „keine Antwort“, letzter Wert bleibt stehen');
check(World::logLines('/Keine Antwort der Alpha-ESS-Anlage/') === [], 'ein einzelner Fehlzyklus wird noch nicht gemeldet');
Kernel::advance(10);
check(count(World::logLines('/Keine Antwort der Alpha-ESS-Anlage/')) === 1, 'zweiter Fehlzyklus: eine Warnung im Log');
$before = count($gw->requests);
Kernel::advance(20);
check(count($gw->requests) === $before, '30 s Pause nach dem zweiten Fehlzyklus');
Kernel::advance(10);
check(count($gw->requests) > $before, 'danach ein neuer Versuch');
$before = count($gw->requests);
Kernel::advance(50);
check(count($gw->requests) === $before, '60 s Pause nach dem dritten');
$gw->mode = 'normal';
$gw->set16(258, 801);
Kernel::advance(130);
check(World::value($sys, 'ModbusState') === 1 && World::value($bat, 'SOC') === 80.1 && count(World::logLines('/wiederhergestellt/')) === 1, 'Gerät wieder da: Werte laufen, eine Meldung');
check(count(World::logLines('/Keine Antwort der Alpha-ESS-Anlage/')) === 1, 'die Warnung kam nur einmal');

section('Ohne ModBus Gateway');
[$sys, $gw] = alphaWorld([], false);
Kernel::advance(30);
check(World::value($sys, 'ModbusState') === 0 && $gw->requests === [] && Kernel::$warnings === [], 'kein Elter: Zustand „nicht verbunden“, keine Warnungen');
check(IPS_GetInstance($sys)['InstanceStatus'] === 102, 'Instanz bleibt aktiv');
check(json_decode((string) Kernel::callPublic($sys, 'GetCompatibleParents', []), true) === ['type' => 'connect', 'modules' => [['moduleID' => FakeModbusGateway::MODULE_ID, 'initial' => ['DeviceID' => 85]]]],
    'schlägt ein ModBus Gateway mit Geräte-ID 85 vor');
check(AESS_ReadRegisters($sys, 16, 2) === '', 'Diagnose-Lesen ohne Elter liefert leer');
[$sys, $gw] = alphaWorld();
check(AESS_ReadRegisters($sys, 258, 1) === '028F', 'Diagnose-Lesen liefert den Registerinhalt hexadezimal');

section('Cloud: Leseaufträge im Abstand');
[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
$cloud->wallboxes = ['EV0000000001' => ['model' => 'SMILE-EVCT11', 'status' => 2, 'power' => 0.0]];
$box = alphaDevice(ALPHA_WALLBOX, $sys, ['Serial' => 'EV0000000001']);
$bat = alphaDevice(ALPHA_BATTERY, $sys, ['CloudControl' => true]);
check(World::timer($sys, 'CloudTick')['interval'] === 5000, 'Cloud-Takt läuft mit Zugangsdaten');
Kernel::advance(5);
check($cloud->endpoints() === ['getEvChargerConfigList'] && World::attr($sys, 'Wallboxes') === '[{"sn":"EV0000000001","model":"SMILE-EVCT11"}]', 'erster Aufruf holt die Wallbox-Liste');
check(World::value($sys, 'CloudState') === 1, 'Cloud verbunden');
Kernel::advance(60);
$gaps = [];
for ($i = 1; $i < count($cloud->calls); $i++) {
    $gaps[] = $cloud->calls[$i]['at'] - $cloud->calls[$i - 1]['at'];
}
check(min($gaps) >= 10, 'zwischen zwei Aufrufen liegen mindestens 10 s (kleinster Abstand ' . min($gaps) . ' s)');
Kernel::advance(40); // drei Aufträge mehr als früher (Summen, Tageswerte, Stammdaten)
$seen = array_values(array_unique($cloud->endpoints()));
$want = ['getEvChargerConfigList', 'getChargeConfigInfo', 'getDisChargeConfigInfo', 'getSumDataForCustomer', 'getOneDateEnergyBySn', 'getEssList',
    'getLastPowerData', 'getEvChargerCurrentsBySn', 'getEvChargerStatusBySn'];
check(count($seen) === count($want) && array_diff($want, $seen) === [], 'alle Leseaufträge sind gelaufen: ' . implode(',', $seen));
check(World::value($box, 'Status') === 2 && World::value($box, 'Charging') === false && World::value($box, 'Current') === 16 && World::value($box, 'Power') === 0.0, 'Wallbox: Fahrzeug verbunden, 16 A');
check(World::value($bat, 'GridCharge') === false && World::value($bat, 'ChargeLimit') === 100 && World::value($bat, 'DischargeLimit') === 10
    && World::value($bat, 'ChargeStart1') === AlphaTimeGrid::toTimestamp('00:00'), 'Speicher: Konfiguration aus der Cloud');
check(!str_contains(Kernel::allText(), $cloud->secret), 'das AppSecret steht weder im Log noch im Debug');

section('Cloud: Wallbox steuern');
$n = count($cloud->calls);
check(RequestAction(World::varId($box, 'Charging'), true) && World::value($box, 'Charging') === true && count($cloud->calls) === $n, 'Befehl wird eingereiht, die Variable zeigt ihn sofort');
Kernel::advance(15);
$last = array_values(array_filter($cloud->calls, static fn(array $c): bool => $c['endpoint'] === 'remoteControlEvCharger'));
check(count($last) === 1 && $last[0]['params'] === ['sysSn' => $cloud->sysSn, 'evchargerSn' => 'EV0000000001', 'controlMode' => 1], 'ein Startbefehl an die Cloud');
$cloud->wallboxes['EV0000000001']['power'] = 7200.0;
Kernel::advance(70);
check(World::value($box, 'Status') === 3 && World::value($box, 'Charging') === true && World::value($box, 'Power') === 7200.0, 'Status „lädt“ und Leistung kommen nach');
check(count(array_keys($cloud->endpoints(), 'remoteControlEvCharger', true)) === 1, 'der Befehl wurde nicht wiederholt');
$cloud->staleSeconds = 40;
check(RequestAction(World::varId($box, 'Current'), 40) && World::value($box, 'Current') === 32, 'Ladestrom wird auf 6–32 A begrenzt');
RequestAction(World::varId($box, 'Current'), 10);
Kernel::advance(35);
check($cloud->amps === 10 && count(array_keys($cloud->endpoints(), 'setEvChargerCurrentsBySn', true)) === 1, 'zwei Änderungen kurz nacheinander ergeben einen Aufruf mit dem letzten Wert');
check(World::value($box, 'Current') === 10, 'liest die Cloud noch den alten Strom, bleibt der Sollwert stehen');
Kernel::advance(400);
check(World::value($box, 'Current') === 10, 'bestätigter Wert bleibt');
RequestAction(World::varId($box, 'Charging'), false);
Kernel::advance(60);
check(World::value($box, 'Status') === 6 && World::value($box, 'Charging') === false, 'Stoppen: Status „wird beendet“');

section('Cloud: Lade- und Entladefenster');
$cloud->staleSeconds = 0;
$n = count(array_keys($cloud->endpoints(), 'updateChargeConfigInfo', true));
RequestAction(World::varId($bat, 'GridCharge'), true);
RequestAction(World::varId($bat, 'ChargeStart1'), mktime(1, 7, 0, 7, 14, 2026));
RequestAction(World::varId($bat, 'ChargeEnd1'), mktime(5, 0, 0, 7, 14, 2026));
RequestAction(World::varId($bat, 'ChargeLimit'), 90);
check(World::value($bat, 'ChargeStart1') === AlphaTimeGrid::toTimestamp('01:00') && World::value($bat, 'ChargeLimit') === 90, 'Variablen zeigen die Eingabe, Uhrzeit aufs Raster gerundet');
Kernel::advance(15);
check(count(array_keys($cloud->endpoints(), 'updateChargeConfigInfo', true)) === $n, 'in der Ruhezeit geht noch nichts an die Cloud');
Kernel::advance(30);
check(count(array_keys($cloud->endpoints(), 'updateChargeConfigInfo', true)) === $n + 1, 'vier Änderungen ergeben einen Schreibaufruf');
check($cloud->charge === ['gridCharge' => 1, 'batHighCap' => 90, 'timeChaf1' => '01:00', 'timeChae1' => '05:00', 'timeChaf2' => '00:00', 'timeChae2' => '00:00'],
    'geschrieben wird die ganze Konfiguration: geänderte Felder plus gelesener Rest');
RequestAction(World::varId($bat, 'DischargeLimit'), 3);
Kernel::advance(45);
check($cloud->discharge['batUseCap'] === 5 && $cloud->discharge['ctrDis'] === 0 && World::value($bat, 'DischargeLimit') === 5, 'Entladegrenze auf 5 % begrenzt, übrige Felder unverändert');
$cloud->charge['batHighCap'] = 80; // Änderung in der App des Herstellers
Kernel::advance(400);
check(World::value($bat, 'ChargeLimit') === 80 && World::value($bat, 'GridCharge') === true, 'Änderung von außen kommt beim nächsten Lesen an');

section('Cloud: Befehl während eines laufenden Schreibens');
$n = count(array_keys($cloud->endpoints(), 'updateChargeConfigInfo', true));
RequestAction(World::varId($bat, 'ChargeLimit'), 70);
$hook = null;
$hook = static function (string $endpoint) use (&$hook, $cloud, $bat): void {
    if ($endpoint === 'updateChargeConfigInfo') {
        RequestAction(World::varId($bat, 'ChargeLimit'), 60);
    } else {
        $cloud->onCall = $hook; // der Haken löst einmal aus: bis zum Schreibaufruf wieder scharf stellen
    }
};
$cloud->onCall = $hook;
Kernel::advance(90);
check(count(array_keys($cloud->endpoints(), 'updateChargeConfigInfo', true)) === $n + 2 && $cloud->charge['batHighCap'] === 60 && World::value($bat, 'ChargeLimit') === 60,
    'die Änderung mitten im Aufruf geht nicht verloren, sie wird nachgeschrieben');

section('Cloud: Schreiben ohne gelesenen Stand, Ablehnung');
[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
$bat = alphaDevice(ALPHA_BATTERY, $sys, ['CloudControl' => true]);
RequestAction(World::varId($bat, 'ChargeLimit'), 85);
Kernel::advance(60);
$order = array_values(array_intersect($cloud->endpoints(), ['getChargeConfigInfo', 'updateChargeConfigInfo']));
check($order === ['getChargeConfigInfo', 'updateChargeConfigInfo', 'getChargeConfigInfo'] && $cloud->charge['batHighCap'] === 85 && $cloud->charge['timeChaf1'] === '00:00',
    'erst lesen, dann schreiben, dann zurücklesen');
$cloud->failCode = 6008;
RequestAction(World::varId($bat, 'ChargeLimit'), 50);
Kernel::advance(40);
$cloud->failCode = null;
Kernel::advance(70);
check($cloud->charge['batHighCap'] === 85 && World::value($bat, 'ChargeLimit') === 85 && count(World::logLines('/Änderung abgelehnt.*Einstellung fehlgeschlagen/')) === 1,
    'abgelehnte Änderung: Variable springt auf den Stand der Cloud zurück, eine Warnung');
check(World::value($sys, 'CloudState') === 1, 'eine abgelehnte Einstellung ändert den Cloud-Zustand nicht');

[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
$bat = alphaDevice(ALPHA_BATTERY, $sys, ['CloudControl' => true]);
$cloud->failCode = 6026;
RequestAction(World::varId($bat, 'ChargeLimit'), 75);
Kernel::advance(60);
check(!in_array('updateChargeConfigInfo', $cloud->endpoints(), true), 'solange das Lesen scheitert, wird nicht geschrieben');
$cloud->failCode = null;
Kernel::advance(100);
check($cloud->charge['batHighCap'] === 75, 'das Lesen wird nach einer Minute wiederholt, dann geht die Änderung hinaus (nicht erst nach dem Intervall)');

section('Cloud: Drosselung, falsche Zugangsdaten, kein Netz');
[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
$cloud->spacing = 25;
Kernel::advance(20);
check(World::value($sys, 'CloudState') === 4, 'zu schnell: Zustand „gedrosselt“');
$n = count($cloud->calls);
Kernel::advance(50);
check(count($cloud->calls) === $n, '60 s lang kein weiterer Aufruf');
$cloud->spacing = 10;
Kernel::advance(30);
check(count($cloud->calls) > $n && World::value($sys, 'CloudState') === 1, 'danach geht es weiter');
IPS_SetProperty($sys, 'AppSecret', 'falsch');
IPS_ApplyChanges($sys);
Kernel::advance(60);
check(World::value($sys, 'CloudState') === 2 && count(World::logLines('/Alpha-ESS-Cloud: Signaturprüfung fehlgeschlagen/')) === 1, 'falsches AppSecret: „Zugang abgelehnt“, einmal im Log');
check(IPS_GetInstance($sys)['InstanceStatus'] === 102 && World::value($sys, 'ModbusState') === 1, 'Modbus läuft davon unberührt weiter');
$cloud->offline = true;
Kernel::advance(300); // nach dem Fehlversuch ist erst mit dem nächsten Intervall wieder ein Aufruf fällig
check(World::value($sys, 'CloudState') === 3, 'kein Netz: „nicht erreichbar“');

section('Nur Cloud: Leistungen ohne Modbus');
[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
$inv = alphaDevice(ALPHA_INVERTER, $sys);
$bat = alphaDevice(ALPHA_BATTERY, $sys);
Kernel::advance(10);
check(in_array('SOH', World::idents($bat), true) && in_array('WindowControl', World::idents($bat), true) && in_array('InverterTemperature', World::idents($inv), true),
    'mit Modbus: Zusatzwerte vorhanden');
$gw->requests = [];
IPS_SetProperty($sys, 'CloudOnly', true);
IPS_ApplyChanges($sys);
check(World::timer($sys, 'Poll')['interval'] === 0 && World::value($sys, 'ModbusState') === 3, 'kein Modbus-Takt, Zustand „nicht genutzt“');
check(array_intersect(['Voltage', 'Current'], World::idents($bat)) === [] && array_intersect(['Frequency', 'Mode', 'PV1Voltage'], World::idents($inv)) === [],
    'Spannung, Strom, Netzfrequenz und Betriebsart verschwinden: die Cloud liefert sie nicht');
check(array_intersect(['SOH', 'WindowControl', 'WindowChargeStart1', 'MinCellVoltage'], World::idents($bat)) === []
    && array_intersect(['InverterTemperature', 'GridVoltageL1', 'BackupPower', 'PV1Current', 'SystemFault'], World::idents($inv)) === [],
    'die Modbus-Zusatzwerte verschwinden ebenso');
$late = alphaDevice(ALPHA_BATTERY, $sys);
check(!in_array('Voltage', World::idents($late), true) && in_array('SOC', World::idents($late), true), 'ein später angelegter Speicher erfährt den Modus beim Anschluss');
Kernel::advance(120);
check($gw->requests === [], 'das Gateway wird nicht gefragt');
check(in_array('getLastPowerData', $cloud->endpoints(), true), 'Leistungsdaten werden auch ohne Wallbox gelesen');
check(World::value($inv, 'PVPower') === 3500.0 && World::value($inv, 'PV1Power') === 1800.0 && World::value($inv, 'PV2Power') === 1700.0, 'PV gesamt und je String aus der Cloud');
check(World::value($inv, 'GridPower') === -500.0 && World::value($inv, 'GridPowerL1') === -400.0 && World::value($inv, 'LoadPower') === 1500.0, 'Netz und Verbrauch aus der Cloud');
check(World::value($bat, 'SOC') === 65.5 && World::value($bat, 'Power') === -1500.0 && World::value($bat, 'State') === 1, 'Speicher: Ladestand, Leistung, lädt');
check(!in_array('SOC', World::idents($inv), true) && !in_array('PVPower', World::idents($bat), true), 'auch hier bekommt jedes Gerät nur seine Werte');
$cloud->power['pbat'] = 2200;
Kernel::advance(120);
check(World::value($bat, 'State') === 2 && World::value($bat, 'Power') === 2200.0, 'positive Batterieleistung: entlädt');
$cloud->power = ['soc' => 50];
Kernel::advance(120);
check(World::value($bat, 'SOC') === 50.0 && World::value($inv, 'PVPower') === 3500.0 && Kernel::$warnings === [], 'fehlende Felder: der letzte Wert bleibt, keine Warnung');
IPS_RequestAction($sys, 'Poll', 0);
check($gw->requests === [], '„Jetzt lesen“ fragt im Cloud-Modus kein Gateway');
$n = count(array_keys($cloud->endpoints(), 'getLastPowerData', true));
Kernel::advance(200);
$m = count(array_keys($cloud->endpoints(), 'getLastPowerData', true)) - $n;
check($m >= 9 && $m <= 11, "Leistungswerte etwa alle 20 s ($m Abrufe in 200 s)");
IPS_SetProperty($sys, 'CloudInterval', 5);
IPS_ApplyChanges($sys);
$n = count(array_keys($cloud->endpoints(), 'getLastPowerData', true));
Kernel::advance(150);
$m = count(array_keys($cloud->endpoints(), 'getLastPowerData', true)) - $n;
check($m <= 11, "nie schneller als alle 15 s ($m Abrufe in 150 s)");
IPS_SetProperty($sys, 'CloudOnly', false);
IPS_ApplyChanges($sys);
check(World::timer($sys, 'Poll')['interval'] === 10000 && World::value($sys, 'ModbusState') === 0, 'zurück auf Modbus: Takt läuft wieder');
Kernel::advance(10);
check(World::value($bat, 'Voltage') === 51.2 && World::value($inv, 'PV1Voltage') === 387.1 && World::value($inv, 'Frequency') === 50.01 && World::value($inv, 'Mode') === 2 && Kernel::$warnings === [],
    'zurück auf Modbus: die Variablen sind wieder da und bekommen Werte');

section('Firmware ohne die neuen Lesebereiche');
[$sys, $gw] = alphaWorld();
foreach ([[35, 54], [2048, 2052], [2127, 2137], [2256, 2261]] as [$from, $to]) {
    for ($a = $from; $a <= $to; $a++) {
        unset($gw->registers[$a]);
    }
}
$inv = alphaDevice(ALPHA_INVERTER, $sys);
$bat = alphaDevice(ALPHA_BATTERY, $sys);
Kernel::advance(10);
check(World::value($sys, 'ModbusState') === 1 && World::value($inv, 'PVPower') === 3500.0 && World::value($inv, 'InverterTemperature') === 38.2,
    'die bekannten Bereiche kommen weiter an');
check(array_intersect(['GridReactivePower', 'FeedInLimit', 'SystemFault'], World::idents($inv)) === [] && !in_array('WindowControl', World::idents($bat), true),
    'aus fehlenden Bereichen entstehen keine leeren Variablen');

section('Nur Cloud: alles, was die Cloud liefert');
[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
$cloud->wallboxes = ['EV0000000001' => ['model' => 'SMILE-EVCT11', 'status' => 2, 'power' => 0.0]];
$inv = alphaDevice(ALPHA_INVERTER, $sys);
$bat = alphaDevice(ALPHA_BATTERY, $sys);
$box = alphaDevice(ALPHA_WALLBOX, $sys, ['Serial' => 'EV0000000001']);
IPS_SetProperty($sys, 'CloudOnly', true);
IPS_ApplyChanges($sys);
Kernel::advance(200);
check(World::value($inv, 'PVEnergy') === 5.5 && World::value($inv, 'GridConsumptionEnergy') === 30.71 && World::value($inv, 'GridFeedInEnergy') === 0.01,
    'Zählerstände des Wechselrichters aus den Summen');
check(World::value($bat, 'ChargeEnergy') === 0.1 && World::value($bat, 'DischargeEnergy') === 0.1, 'Zählerstände des Speichers aus den Summen');
check(World::value($inv, 'InverterPower') === 2000.0 && World::value($inv, 'InverterPowerL1') === 700.0, 'Wechselrichterleistung gesamt und je Phase');
check(World::value($inv, 'PVEnergyToday') === 1.3 && World::value($inv, 'GridConsumptionToday') === 30.71 && World::value($inv, 'SelfSufficiency') === 10.14
    && World::value($inv, 'LoadEnergy') === 32.0, 'Tageswerte, Verbrauch gesamt und Autarkie');
check(World::value($bat, 'ChargeToday') === 0.1 && World::value($bat, 'Capacity') === 7.68 && World::value($bat, 'UsableShare') === 95.0, 'Speicher: heute, Kapazität, nutzbarer Anteil');
check(World::value($sys, 'EmsStatus') === 'Normal' && World::value($sys, 'InverterModel') === 'SMILE-G3-T6-INV' && World::value($sys, 'PVPeak') === 8.0
    && World::value($sys, 'IncomeTotal') === 1.32 && World::value($sys, 'CO2') === 2.75, 'Anlage: EMS-Status, Modell, Spitzenleistung, Ertrag, CO2');
check(!in_array('IncomeToday', World::idents($sys), true), 'ohne Wert entsteht keine Variable (Ertrag heute ist leer)');
check(World::value($box, 'EnergyToday') === 2.5, 'Wallbox: Energie heute, bei genau einer Wallbox');
check($cloud->lastDate === date('Y-m-d', AlphaClock::now()), 'Tageswerte für das heutige Datum');
check(Kernel::$warnings === [], 'keine Warnungen');

section('Modbus mit Cloud: Zusatzwerte ja, Zähler nicht doppelt');
[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
$inv = alphaDevice(ALPHA_INVERTER, $sys);
Kernel::advance(200);
check(World::value($inv, 'PVEnergy') === 9876.5 && World::value($inv, 'SelfSufficiency') === 10.14 && World::value($inv, 'PVEnergyToday') === 1.3,
    'PV-Ertrag bleibt beim Modbus-Zähler, Autarkie und Tageswerte kommen dazu');
check(World::value($inv, 'LoadEnergyToday') === 32.0 && World::value($inv, 'SelfConsumptionToday') === 99.2 && World::value($inv, 'SelfSufficiencyToday') === 4.0,
    'mit Cloud: Verbrauch, Eigenverbrauch und Autarkie heute aus den Tageswerten der Cloud');

section('Ohne Cloud: keine leeren Zusatzvariablen');
[$sys, $gw] = alphaWorld();
$inv = alphaDevice(ALPHA_INVERTER, $sys);
$bat = alphaDevice(ALPHA_BATTERY, $sys);
Kernel::advance(30);
check(!in_array('UsableCapacity', World::idents($bat), true) && !in_array('EmsStatus', World::idents($sys), true), 'nur Modbus: keine Variablen, die nur die Cloud kennt');
check(World::value($bat, 'Capacity') === 15.1 && World::value($bat, 'SOH') === 98.7 && World::value($bat, 'MinCellTemperature') === -1.5
    && World::value($bat, 'MaxCellVoltage') === 3.345 && World::value($bat, 'GridChargeEnergy') === 45.6 && World::value($bat, 'GridChargeToday') === 0.0
    && World::value($bat, 'Modules') === 3 && World::value($bat, 'ReserveSOC') === 10.0, 'nur Modbus: Speicher-Zusatzwerte');
check(World::value($bat, 'WindowControl') === 1 && World::value($bat, 'WindowChargeLimit') === 90.0
    && World::value($bat, 'WindowChargeStart1') === AlphaTimeGrid::toTimestamp('01:00') && World::value($bat, 'WindowChargeEnd1') === AlphaTimeGrid::toTimestamp('05:00'),
    'nur Modbus: Zeitsteuerung der Anlage lesend');
check(World::value($inv, 'InverterTemperature') === 38.2 && World::value($inv, 'InverterCurrentL1') === -3.5 && World::value($inv, 'GridVoltageL1') === 230.1
    && World::value($inv, 'GridPowerFactor') === -0.95 && World::value($inv, 'GridReactivePower') === -120.0 && World::value($inv, 'PVCapacity') === 9800.0
    && World::value($inv, 'FeedInLimit') === 70.0 && World::value($inv, 'SystemPVEnergy') === 9900.0 && World::value($inv, 'SystemFault') === 0
    && World::value($inv, 'PV1Current') === 4.6 && World::value($inv, 'BackupPower') === 0.0, 'nur Modbus: Wechselrichter-, Netz-, Notstrom- und Anlagenwerte');
// Zähler der Registerkarte: Bezug 2345,67, Einspeisung 1234,56, PV 9876,5, Laden 432,1, Entladen 321,0
check(GetValue(World::varId($inv, 'LoadEnergy')) === 10876.51 && GetValue(World::varId($inv, 'SelfConsumption')) === 87.5
    && GetValue(World::varId($inv, 'SelfSufficiency')) === 78.4, 'nur Modbus: Verbrauch, Eigenverbrauch und Autarkie aus den Zählern');
check(GetValue(World::varId($inv, 'PVEnergyToday')) === 0.0 && GetValue(World::varId($bat, 'ChargeToday')) === 0.0, 'nur Modbus: Tageswerte beginnen bei 0');
$gw->set32(1086, 98766);   // PV-Ertrag 9876,5 → 9876,6 kWh
$gw->set32(18, 234587);    // Netzbezug 2345,67 → 2345,87 kWh
Kernel::advance(15);
check(GetValue(World::varId($inv, 'PVEnergyToday')) === 0.1 && GetValue(World::varId($inv, 'LoadEnergyToday')) === 0.3
    && GetValue(World::varId($inv, 'SelfConsumptionToday')) === 100.0 && GetValue(World::varId($inv, 'SelfSufficiencyToday')) === 33.3,
    'nur Modbus: Verbrauch, Eigenverbrauch und Autarkie heute');
check(GetValue(World::varId($inv, 'PV1Voltage')) === 387.1 && GetValue(World::varId($inv, 'PV2Voltage')) === 402.2 && !in_array('PV3Voltage', World::idents($inv), true),
    'nur Modbus: Spannung je eingerichtetem String');
check(GetValue(World::varId($inv, 'InverterPowerL1')) === 800.0 && GetValue(World::varId($inv, 'InverterPowerL3')) === 500.0, 'nur Modbus: Wechselrichterleistung je Phase kommt vom Modbus');

section('Nur Cloud ohne Wallbox: Leistungsdaten nur im Cloud-Modus');
[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
Kernel::advance(120);
check(!in_array('getLastPowerData', $cloud->endpoints(), true), 'mit Modbus und ohne Wallbox kein überflüssiger Leistungsabruf');

section('Befehle ohne Cloud-Zugang');
[$sys, $gw] = alphaWorld();
$box = alphaDevice(ALPHA_WALLBOX, $sys, ['Serial' => 'EV0000000001']);
$try = alphaAction($box, 'Charging', true);
check(!$try['ok'] && $try['message'] === 'Der Cloud-Zugang ist in der Alpha-ESS-Anlage nicht eingerichtet' && World::value($box, 'Charging') === false,
    'Befehl scheitert mit verständlicher Meldung, Variable bleibt');
IPS_DisconnectInstance($box);
$try = alphaAction($box, 'Current', 10);
check(!$try['ok'] && $try['message'] === 'Keine Alpha-ESS-Anlage verbunden' && World::value($box, 'Current') === 0, 'ohne Anlage: eigene Meldung');

done();

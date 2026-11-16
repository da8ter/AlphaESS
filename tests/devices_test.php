<?php

declare(strict_types=1);

// Geräteinstanzen und Konfigurator: Variablen, Darstellungen, Eigenschaften, Zuordnung der Pakete.
require __DIR__ . '/bootstrap.php';

/** Erlaubte Parameter je Darstellung (IPS_GetPresentation, Symcon 9.1, gelesen 10.11.2026). */
const ALLOWED = [
    VARIABLE_PRESENTATION_SLIDER => ['CUSTOM_GRADIENT', 'DECIMAL_SEPARATOR', 'DIGITS', 'GRADIENT_TYPE', 'ICON', 'INTERVALS', 'INTERVALS_ACTIVE',
        'MAX', 'MIN', 'PERCENTAGE', 'PREFIX', 'STEP_SIZE', 'SUFFIX', 'THOUSANDS_SEPARATOR', 'USAGE_TYPE'],
    VARIABLE_PRESENTATION_DATE_TIME => ['DATE', 'DAY_OF_THE_WEEK', 'MONTH_TEXT', 'TIME'],
    VARIABLE_PRESENTATION_SWITCH => ['GLOW_COLOR', 'GLOW_INTENSITY', 'ICON_FALSE', 'ICON_TRUE', 'USAGE_TYPE', 'USE_ICON_FALSE'],
];
const INTERVAL_KEYS = ['IntervalMinValue', 'IntervalMaxValue', 'ConstantActive', 'ConstantValue', 'ConversionFactor', 'PrefixActive', 'PrefixValue',
    'SuffixActive', 'SuffixValue', 'DigitsActive', 'DigitsValue', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue', 'ContentColorActive', 'ContentColorValue'];

/** Prüft jede Variable einer Instanz gegen die Parameterlisten; liefert die Verstöße. */
function presentationFaults(int $instance): array
{
    $faults = [];
    foreach (World::idents($instance) as $ident) {
        $p = World::variable($instance, $ident)['presentation'] ?? [];
        $guid = $p['PRESENTATION'] ?? '';
        if ($guid === '') {
            $faults[] = "$ident ohne Darstellung";
        }
        foreach (isset(ALLOWED[$guid]) ? array_diff(array_keys($p), ALLOWED[$guid], ['PRESENTATION']) : [] as $key) {
            $faults[] = "$ident: $key";
        }
        foreach (json_decode((string) ($p['INTERVALS'] ?? '[]'), true) as $interval) {
            foreach (array_diff(array_keys($interval), INTERVAL_KEYS) as $key) {
                $faults[] = "$ident: Intervall $key";
            }
        }
    }
    return $faults;
}

section('Wechselrichter');
[$sys, $gw] = alphaWorld();
$inv = alphaDevice(ALPHA_INVERTER, $sys);
check(World::idents($inv) === ['Frequency', 'GridConsumptionEnergy', 'GridFeedInEnergy', 'GridPower', 'GridPowerL1', 'GridPowerL2', 'GridPowerL3',
    'InverterPower', 'LoadPower', 'Mode', 'PV1Power', 'PV1Voltage', 'PV2Power', 'PV2Voltage', 'PVEnergy', 'PVPower'], 'Variablen mit zwei Strings: ' . implode(',', World::idents($inv)));
$v = World::variable($inv, 'PVPower');
check($v['name'] === 'PV-Leistung' && $v['presentation'] === ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'solar-panel', 'SUFFIX' => ' W', 'DIGITS' => 0],
    'PV-Leistung: übersetzter Name, Wertanzeige in Watt');
check(World::variable($inv, 'PV2Power')['name'] === 'PV-String 2' && World::variable($inv, 'GridFeedInEnergy')['presentation']['DIGITS'] === 2, 'Stringname und Zähler mit zwei Stellen');
IPS_SetProperty($inv, 'PVStrings', 4);
IPS_SetProperty($inv, 'PVMeter', true);
IPS_ApplyChanges($inv);
check(in_array('PV4Power', World::idents($inv), true) && !in_array('PV5Power', World::idents($inv), true) && in_array('PVMeterPower', World::idents($inv), true), 'vier Strings und PV-Zähler nach Einstellung');
$gw->set32(1063, 900);
$gw->set32(161, 400);
Kernel::advance(10);
check(World::value($inv, 'PV3Power') === 900.0 && World::value($inv, 'PVMeterPower') === 400.0 && World::value($inv, 'PVPower') === 4800.0 && World::value($inv, 'LoadPower') === 1900.0,
    'dritter String und AC-gekoppelte PV zählen zu Erzeugung und Verbrauch');
IPS_SetProperty($inv, 'PVStrings', 0);
IPS_SetProperty($inv, 'PVMeter', false);
IPS_ApplyChanges($inv);
check(array_values(array_filter(World::idents($inv), static fn(string $i): bool => preg_match('/^PV\dPower$|^PVMeter/', $i) === 1)) === [], 'ohne Strings keine String-Variablen');
check(presentationFaults($inv) === [], 'nur erlaubte Darstellungsparameter');
check(json_decode((string) Kernel::callPublic($inv, 'GetCompatibleParents', []), true) === ['type' => 'connect', 'moduleIDs' => [ALPHA_SYSTEM]], 'schlägt eine Alpha-ESS-Anlage als Elter vor');

section('Speicher');
$bat = alphaDevice(ALPHA_BATTERY, $sys);
check(World::idents($bat) === ['ChargeEnergy', 'ChargeToday', 'Current', 'DischargeEnergy', 'DischargeToday', 'Power', 'SOC', 'State', 'Voltage'],
    'ohne Cloud-Steuerung nur Messwerte (Tageswerte ohne Cloud aus den Zählern)');
$state = json_decode((string) World::variable($bat, 'State')['presentation']['INTERVALS'], true);
check(array_column($state, 'ConstantValue') === ['Ruht', 'Lädt', 'Entlädt'] && array_column($state, 'IntervalMinValue') === [0, 1, 2], 'Zustand als Wertanzeige mit drei beschrifteten Intervallen');
IPS_SetProperty($bat, 'CloudControl', true);
IPS_ApplyChanges($bat);
check(count(World::idents($bat)) === 21, 'mit Cloud-Steuerung zwölf Einstellungen dazu');
$clock = World::variable($bat, 'ChargeStart1');
check($clock['type'] === VARIABLETYPE_INTEGER && $clock['presentation'] === ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'DATE' => 0, 'TIME' => 1] && $clock['action'] === $bat,
    'Fensterzeit: Datum/Uhrzeit ohne Datum, schaltbar');
$limit = World::variable($bat, 'DischargeLimit')['presentation'];
check($limit['PRESENTATION'] === VARIABLE_PRESENTATION_SLIDER && $limit['MIN'] === 5 && $limit['MAX'] === 100 && $limit['STEP_SIZE'] === 1 && $limit['SUFFIX'] === ' %', 'Grenze als Schieberegler 5–100 %');
check(World::variable($bat, 'GridCharge')['type'] === VARIABLETYPE_BOOLEAN && World::variable($bat, 'GridCharge')['presentation']['PRESENTATION'] === VARIABLE_PRESENTATION_SWITCH, 'Netzladen als Schalter');
check(presentationFaults($bat) === [], 'nur erlaubte Darstellungsparameter');
$try = alphaAction($bat, 'GridCharge', true);
check(!$try['ok'] && str_contains($try['message'], 'Cloud-Zugang') && World::value($bat, 'GridCharge') === false, 'ohne Cloud-Zugang scheitert die Einstellung mit Meldung, der Wert bleibt');
IPS_SetProperty($bat, 'CloudControl', false);
IPS_ApplyChanges($bat);
check(count(World::idents($bat)) === 9, 'Cloud-Steuerung aus: Einstellungen wieder weg');

section('Wallboxen');
[$sys, $gw] = alphaWorld();
$cloud = alphaCloud($sys);
$cloud->wallboxes = ['EV-A' => ['model' => 'SMILE-EVCT11', 'status' => 3, 'power' => 7000.0], 'EV-B' => ['model' => 'SMILE-EVCS7', 'status' => 1, 'power' => 0.0]];
$a = alphaDevice(ALPHA_WALLBOX, $sys, ['Serial' => 'EV-A']);
$b = alphaDevice(ALPHA_WALLBOX, $sys, ['Serial' => ' EV-B ']);
$none = alphaDevice(ALPHA_WALLBOX, $sys);
check(IPS_GetInstance($none)['InstanceStatus'] === 104 && IPS_GetInstance($a)['InstanceStatus'] === 102, 'ohne Seriennummer inaktiv, sonst aktiv');
Kernel::advance(120);
check(World::value($a, 'Status') === 3 && World::value($a, 'Charging') === true && World::value($a, 'Power') === 7000.0, 'Wallbox A lädt mit 7 kW');
check(World::value($b, 'Status') === 1 && World::value($b, 'Charging') === false && World::value($b, 'Power') === 0.0, 'Wallbox B ist frei (Seriennummer mit Leerzeichen erkannt)');
check(World::value($none, 'Status') === 0 && World::value($none, 'Current') === 0, 'die Wallbox ohne Seriennummer bekommt nichts');
check(World::value($a, 'Current') === 16 && World::value($b, 'Current') === 16, 'der Ladestrom gilt für die Anlage und steht an beiden');
$status = json_decode((string) World::variable($a, 'Status')['presentation']['INTERVALS'], true);
check(array_column($status, 'IntervalMinValue') === [0, 1, 2, 3, 4, 5, 6, 9] && $status[3]['ConstantValue'] === 'Laden' && $status[7]['ConstantValue'] === 'Störung', 'Statusanzeige mit acht Zuständen');
check(World::variable($a, 'Current')['presentation']['MIN'] === 6 && World::variable($a, 'Current')['presentation']['MAX'] === 32 && presentationFaults($a) === [], 'Ladestrom 6–32 A, nur erlaubte Parameter');
RequestAction(World::varId($b, 'Charging'), true);
Kernel::advance(40);
check($cloud->wallboxes['EV-B']['status'] === 3 && $cloud->wallboxes['EV-A']['status'] === 3, 'Start geht an die richtige Wallbox');
check(IPS_RequestAction($a, 'Quatsch', 1) === false, 'unbekannte Aktion wird abgelehnt');

section('Konfigurator');
$cfg = alphaDevice(ALPHA_CONFIGURATOR, $sys);
$inv = alphaDevice(ALPHA_INVERTER, $sys);
$form = json_decode((string) Kernel::callPublic($cfg, 'GetConfigurationForm', []), true);
$rows = $form['actions'][0]['values'];
check(array_column($rows, 'name') === ['Wechselrichter', 'Speicher', 'Wallbox SMILE-EVCT11', 'Wallbox SMILE-EVCS7'], 'bietet Wechselrichter, Speicher und beide Wallboxen an');
check($rows[0]['instanceID'] === $inv && $rows[1]['instanceID'] === 0 && $rows[2]['instanceID'] === $a && $rows[3]['instanceID'] === $b, 'vorhandene Instanzen werden erkannt');
check($rows[1]['create'] === ['moduleID' => ALPHA_BATTERY, 'name' => 'Alpha ESS Speicher', 'configuration' => []]
    && $rows[3]['create']['configuration'] === ['Serial' => 'EV-B'] && $rows[3]['create']['moduleID'] === ALPHA_WALLBOX, 'Anlegen mit Modul, Name und Seriennummer');
check($form['elements'][0]['visible'] === false && $form['elements'][1]['visible'] === false, 'keine Hinweise bei verbundener Anlage mit Cloud');
[$sys2, $gw2] = alphaWorld();
$cfg2 = alphaDevice(ALPHA_CONFIGURATOR, $sys2);
$inv2 = alphaDevice(ALPHA_INVERTER, $sys2);
$form = json_decode((string) Kernel::callPublic($cfg2, 'GetConfigurationForm', []), true);
check(count($form['actions'][0]['values']) === 2 && $form['elements'][1]['visible'] === true, 'ohne Cloud: nur Wechselrichter und Speicher, Hinweis auf die Wallboxen');
$lonely = Kernel::createInstance(ALPHA_CONFIGURATOR);
$form = json_decode((string) Kernel::callPublic($lonely, 'GetConfigurationForm', []), true);
check($form['actions'][0]['values'] === [] && $form['elements'][0]['visible'] === true, 'ohne Anlage: leere Liste und Hinweis');
$other = alphaDevice(ALPHA_BATTERY, $sys2);
$form = json_decode((string) Kernel::callPublic($cfg2, 'GetConfigurationForm', []), true);
check($form['actions'][0]['values'][1]['instanceID'] === $other && $form['actions'][0]['values'][0]['instanceID'] === $inv2, 'Instanzen einer anderen Anlage zählen nicht mit');

done();

<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AlphaClock.php';
require_once __DIR__ . '/../libs/AlphaDataFlow.php';
require_once __DIR__ . '/../libs/AlphaRegisters.php';
require_once __DIR__ . '/../libs/AlphaModbusGateway.php';
require_once __DIR__ . '/../libs/AlphaTimeGrid.php';
require_once __DIR__ . '/../libs/AlphaCloudApi.php';
require_once __DIR__ . '/../libs/AlphaCloudQueue.php';
require_once __DIR__ . '/../libs/AlphaHttpTransport.php';
require_once __DIR__ . '/../libs/AlphaPresentations.php';
require_once __DIR__ . '/libs/AlphaSystemModbus.php';
require_once __DIR__ . '/libs/AlphaSystemCloud.php';
require_once __DIR__ . '/libs/AlphaSystemForward.php';

/*
 * Eine Alpha-ESS-Anlage: liest Wechselrichter und Speicher lokal über das Symcon ModBus Gateway,
 * spricht für Wallbox und Lade-/Entladefenster mit der Alpha-ESS-Cloud und verteilt die Werte
 * an die Geräteinstanzen. Beide Wege arbeiten unabhängig voneinander.
 * „Nur Cloud“: kein Modbus; alle Werte kommen aus der Cloud. Netzfrequenz, Betriebsart, Spannung und Strom
 * des Speichers liefert sie nicht, Wechselrichter und Speicher entfernen diese Variablen dann.
 */
class AlphaESSSystem extends IPSModuleStrict
{
    use AlphaSystemModbus;
    use AlphaSystemCloud;
    use AlphaSystemForward;

    private const MODBUS_DEVICE_ID = 85;

    /**
     * Angaben zur Anlage aus der Cloud; die Variablen entstehen erst mit dem ersten Wert.
     * @var array<string,array{0:string,1:string,2:int,3:string,4:string,5:int,6:int}> Wert => [Ident, Name, Typ, Icon, Suffix, Stellen, Position]
     */
    private const CLOUD_INFO = [
        'ems_status'     => ['EmsStatus', 'EMS status', VARIABLETYPE_STRING, 'circle-info', '', 0, 30],
        'inverter_model' => ['InverterModel', 'Inverter model', VARIABLETYPE_STRING, 'tag', '', 0, 40],
        'battery_model'  => ['BatteryModel', 'Battery model', VARIABLETYPE_STRING, 'tag', '', 0, 41],
        'pv_peak'        => ['PVPeak', 'PV peak power', VARIABLETYPE_FLOAT, 'solar-panel', ' kWp', 1, 42],
        'inverter_rated' => ['InverterRated', 'Inverter rated power', VARIABLETYPE_FLOAT, 'bolt', ' kW', 1, 43],
        'income_total'   => ['IncomeTotal', 'Income total', VARIABLETYPE_FLOAT, 'coins', '', 2, 50],
        'income_today'   => ['IncomeToday', 'Income today', VARIABLETYPE_FLOAT, 'coins', '', 2, 51],
        'trees'          => ['Trees', 'Trees planted (equivalent)', VARIABLETYPE_FLOAT, 'tree', '', 2, 52],
        'co2'            => ['CO2', 'CO2 saved', VARIABLETYPE_FLOAT, 'leaf', ' kg', 2, 53],
    ];
    private const CLOUD_TICK_MS = 5000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('CloudOnly', false);
        $this->RegisterPropertyInteger('PollInterval', 10);
        $this->RegisterPropertyString('AppID', '');
        $this->RegisterPropertyString('AppSecret', '');
        $this->RegisterPropertyString('SystemSN', '');
        $this->RegisterPropertyInteger('CloudInterval', 20);
        $this->RegisterPropertyInteger('CloudConfigInterval', 300);

        $this->RegisterAttributeString('Wallboxes', '[]');

        $this->RegisterTimer('Poll', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Poll\', 0);');
        $this->RegisterTimer('CloudTick', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'CloudTick\', 0);');

        $this->RegisterVariableInteger('ModbusState', $this->Translate('Modbus connection'), AlphaPresentations::state('network-wired', [
            [0, $this->Translate('Not connected'), AlphaPresentations::GREY],
            [1, $this->Translate('Connected'), AlphaPresentations::GREEN],
            [2, $this->Translate('No answer'), AlphaPresentations::RED],
            [3, $this->Translate('Not used (cloud only)'), AlphaPresentations::GREY],
        ]), 10);
        $this->RegisterVariableInteger('CloudState', $this->Translate('Cloud connection'), AlphaPresentations::state('cloud', [
            [0, $this->Translate('Not set up'), AlphaPresentations::GREY],
            [1, $this->Translate('Connected'), AlphaPresentations::GREEN],
            [2, $this->Translate('Access denied'), AlphaPresentations::RED],
            [3, $this->Translate('Not reachable'), AlphaPresentations::RED],
            [4, $this->Translate('Throttled'), AlphaPresentations::ORANGE],
        ]), 20);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->SetBuffer('ModbusFails', '0');
        $this->SetBuffer('ModbusRetryAt', '0');
        $this->SetBuffer('CloudQueue', '');
        $this->SetBuffer('Cfg_charge', '');
        $this->SetBuffer('Cfg_discharge', '');

        $cloudOnly = $this->ReadPropertyBoolean('CloudOnly');
        $this->SetTimerInterval('Poll', $cloudOnly ? 0 : max(2, $this->ReadPropertyInteger('PollInterval')) * 1000);
        if ($cloudOnly) {
            $this->setState('ModbusState', 3);
        } elseif ($this->GetValue('ModbusState') === 3) {
            $this->setState('ModbusState', 0);
        }
        // Wechselrichter und Speicher entfernen oder ergänzen ihre Modbus-Variablen
        foreach ([AlphaDataFlow::KIND_INVERTER, AlphaDataFlow::KIND_BATTERY] as $kind) {
            $this->SendDataToChildren(AlphaDataFlow::toChildren($kind, '', ['cloud_only' => $cloudOnly]));
        }
        $this->SetTimerInterval('CloudTick', $this->cloudConfigured() ? self::CLOUD_TICK_MS : 0);
        if (!$this->cloudConfigured()) {
            $this->setState('CloudState', 0);
        }
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetCompatibleParents(): string
    {
        return (string) json_encode(['type' => 'connect', 'modules' => [[
            'moduleID' => AlphaDataFlow::MODBUS_GATEWAY,
            'initial'  => ['DeviceID' => self::MODBUS_DEVICE_ID],
        ]]]);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        try {
            match ($Ident) {
                'Poll'      => $this->pollModbus(),
                'CloudTick' => $this->cloudTick(),
                default     => throw new \InvalidArgumentException('Unknown ident ' . $Ident),
            };
        } catch (\Throwable $e) {
            $this->LogMessage($e->getMessage(), KL_ERROR);
        }
    }

    /** Diagnose: liest Register roh und gibt den Inhalt hexadezimal zurück (leer bei Fehler). */
    public function ReadRegisters(int $Address, int $Quantity): string
    {
        $quantity = max(1, min(125, $Quantity));
        $data = AlphaModbusGateway::payload(@$this->SendDataToParent(AlphaModbusGateway::request($Address, $quantity)), $quantity);
        return $data === null ? '' : strtoupper(bin2hex($data));
    }

    private function setState(string $ident, int $state): void
    {
        if ($this->GetValue($ident) !== $state) {
            $this->SetValue($ident, $state);
        }
    }

    /**
     * Merkt den letzten Stand je Gerät und Quelle und schickt die Werte an die Geräteinstanzen.
     * @param array<string,mixed> $values
     */
    private function publish(string $kind, string $serial, string $source, array $values): void
    {
        if ($values === []) {
            return;
        }
        $name = $this->cacheName($kind, $serial, $source);
        $cache = json_decode($this->GetBuffer($name), true);
        $this->SetBuffer($name, (string) json_encode(array_merge(is_array($cache) ? $cache : [], $values)));
        $this->SendDataToChildren(AlphaDataFlow::toChildren($kind, $serial, $values));
    }

    /**
     * Angaben zur Anlage selbst (Cloud). Die Variable entsteht mit dem ersten Wert; Erträge tragen die
     * Währung der Cloud, sobald sie eine nennt.
     * @param array<string,float|string> $values
     */
    private function systemInfo(array $values): void
    {
        $currency = isset($values['currency']) ? ' ' . $values['currency'] : '';
        foreach (self::CLOUD_INFO as $key => [$ident, $name, $type, $icon, $suffix, $digits, $position]) {
            if (!isset($values[$key])) {
                continue;
            }
            $presentation = $type === VARIABLETYPE_STRING ? AlphaPresentations::text($icon)
                : AlphaPresentations::value($icon, str_starts_with($key, 'income_') ? $currency : $suffix, $digits);
            $this->MaintainVariable($ident, $this->Translate($name), $type, $presentation, $position, true);
            $value = $type === VARIABLETYPE_STRING ? (string) $values[$key] : (float) $values[$key];
            if ($this->GetValue($ident) !== $value) {
                $this->SetValue($ident, $value);
            }
        }
    }

    private function cacheName(string $kind, string $serial, string $source): string
    {
        return 'Last_' . $kind . '_' . $serial . '_' . $source;
    }

    /** @return array<string,mixed> */
    private function cached(string $kind, string $serial): array
    {
        $values = [];
        foreach (['modbus', 'cloud'] as $source) {
            $part = json_decode($this->GetBuffer($this->cacheName($kind, $serial, $source)), true);
            $values = array_merge($values, is_array($part) ? $part : []);
        }
        return $values;
    }
}

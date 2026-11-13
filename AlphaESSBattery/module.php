<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AlphaDataFlow.php';
require_once __DIR__ . '/../libs/AlphaTimeGrid.php';
require_once __DIR__ . '/../libs/AlphaPresentations.php';
require_once __DIR__ . '/../libs/AlphaDevice.php';

/*
 * Speicher einer Alpha-ESS-Anlage. Messwerte kommen vom System über Modbus; die Lade- und
 * Entladefenster liest und schreibt das System über die Cloud (nur mit „Cloud-Steuerung“).
 */
class AlphaESSBattery extends IPSModuleStrict
{
    use AlphaDevice;

    /** @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int}> Wert => [Ident, Name, Icon, Suffix, Stellen, Position] */
    private const VARIABLES = [
        'battery_soc'              => ['SOC', 'State of charge', 'battery-half', ' %', 1, 10],
        'battery_power'            => ['Power', 'Battery power', 'bolt', ' W', 0, 20],
        'battery_voltage'          => ['Voltage', 'Voltage', 'bolt', ' V', 1, 40],
        'battery_current'          => ['Current', 'Current', 'bolt', ' A', 1, 41],
        'battery_charge_energy'    => ['ChargeEnergy', 'Energy charged', 'arrow-down', ' kWh', 1, 50],
        'battery_discharge_energy' => ['DischargeEnergy', 'Energy discharged', 'arrow-up', ' kWh', 1, 51],
    ];

    private const COUNTERS = ['battery_charge_energy', 'battery_discharge_energy'];

    /** @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int}> nur aus der Cloud, entstehen mit dem ersten Wert */
    private const CLOUD_VARIABLES = [
        'battery_charge_today'      => ['ChargeToday', 'Energy charged today', 'arrow-down', ' kWh', 2, 52],
        'battery_discharge_today'   => ['DischargeToday', 'Energy discharged today', 'arrow-up', ' kWh', 2, 53],
        'battery_grid_charge_today' => ['GridChargeToday', 'Charged from grid today', 'plug', ' kWh', 2, 54],
        'battery_capacity'          => ['Capacity', 'Capacity', 'battery-full', ' kWh', 2, 60],
        'battery_usable_capacity'   => ['UsableCapacity', 'Usable capacity', 'battery-three-quarters', ' kWh', 2, 61],
        'battery_usable_share'      => ['UsableShare', 'Usable share', 'percent', ' %', 0, 62],
    ];

    /** @var array<string,array{0:string,1:string,2:string,3:int}> Wert => [Ident, Name, Art, Position] */
    private const CONTROLS = [
        'grid_charge'       => ['GridCharge', 'Charge from grid', 'switch', 100],
        'charge_start1'     => ['ChargeStart1', 'Charging window 1 start', 'clock', 101],
        'charge_end1'       => ['ChargeEnd1', 'Charging window 1 end', 'clock', 102],
        'charge_start2'     => ['ChargeStart2', 'Charging window 2 start', 'clock', 103],
        'charge_end2'       => ['ChargeEnd2', 'Charging window 2 end', 'clock', 104],
        'charge_limit'      => ['ChargeLimit', 'Charge up to', 'limit', 105],
        'discharge_control' => ['DischargeControl', 'Discharge time control', 'switch', 110],
        'discharge_start1'  => ['DischargeStart1', 'Discharging window 1 start', 'clock', 111],
        'discharge_end1'    => ['DischargeEnd1', 'Discharging window 1 end', 'clock', 112],
        'discharge_start2'  => ['DischargeStart2', 'Discharging window 2 start', 'clock', 113],
        'discharge_end2'    => ['DischargeEnd2', 'Discharging window 2 end', 'clock', 114],
        'discharge_limit'   => ['DischargeLimit', 'Discharge down to', 'limit', 115],
    ];

    private const LIMIT_MIN = 5;
    private const LIMIT_MAX = 100;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('CloudControl', false);

        foreach (self::VARIABLES as [$ident, $name, $icon, $suffix, $digits, $position]) {
            $this->RegisterVariableFloat($ident, $this->Translate($name), AlphaPresentations::value($icon, $suffix, $digits), $position);
        }
        $this->RegisterVariableInteger('State', $this->Translate('Battery state'), AlphaPresentations::state('battery-half', [
            [0, $this->Translate('Idle'), AlphaPresentations::GREY],
            [1, $this->Translate('Charging'), AlphaPresentations::GREEN],
            [2, $this->Translate('Discharging'), AlphaPresentations::ORANGE],
        ]), 30);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $control = $this->ReadPropertyBoolean('CloudControl');
        foreach (self::CONTROLS as [$ident, $name, $kind, $position]) {
            [$type, $presentation] = match ($kind) {
                'switch' => [VARIABLETYPE_BOOLEAN, AlphaPresentations::toggle('plug')],
                'clock'  => [VARIABLETYPE_INTEGER, AlphaPresentations::clock()],
                default  => [VARIABLETYPE_INTEGER, AlphaPresentations::slider('battery-half', ' %', self::LIMIT_MIN, self::LIMIT_MAX)],
            };
            $this->MaintainVariable($ident, $this->Translate($name), $type, $presentation, $position, $control);
            if ($control) {
                $this->EnableAction($ident);
            }
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->alphaFilter(AlphaDataFlow::KIND_BATTERY);
        $this->applyValues($this->alphaState(AlphaDataFlow::KIND_BATTERY));
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        } elseif ($Message === FM_CONNECT) {
            $this->applyValues($this->alphaState(AlphaDataFlow::KIND_BATTERY));
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $values = $this->alphaValues($JSONString, AlphaDataFlow::KIND_BATTERY);
        if ($values !== null) {
            $this->applyValues($values);
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        foreach (self::CONTROLS as $key => [$ident, , $kind]) {
            if ($ident !== $Ident) {
                continue;
            }
            $setting = match ($kind) {
                'switch' => (bool) $Value,
                'clock'  => AlphaTimeGrid::toClock((int) $Value),
                default  => max(self::LIMIT_MIN, min(self::LIMIT_MAX, (int) $Value)),
            };
            $answer = $this->alphaSend('BatteryConfig', ['Values' => [$key => $setting]]);
            if (empty($answer['ok'])) {
                $this->alphaFail($answer, 'The setting could not be changed');
                return;
            }
            $this->SetValue($Ident, $kind === 'clock' ? AlphaTimeGrid::toTimestamp((string) $setting) : $setting);
            return;
        }
        throw new \InvalidArgumentException('Unknown ident ' . $Ident);
    }

    /** @param array<string,mixed> $values */
    private function applyValues(array $values): void
    {
        foreach (self::VARIABLES as $key => [$ident]) {
            if (!is_numeric($values[$key] ?? null)) {
                continue;
            }
            if (in_array($key, self::COUNTERS, true)) {
                $this->alphaCounter($ident, (float) $values[$key]);
            } else {
                $this->SetValue($ident, (float) $values[$key]);
            }
        }
        if (is_numeric($values['battery_state'] ?? null) && $this->GetValue('State') !== (int) $values['battery_state']) {
            $this->SetValue('State', (int) $values['battery_state']);
        }
        $this->alphaCloudValues(self::CLOUD_VARIABLES, $values);
        if (!$this->ReadPropertyBoolean('CloudControl')) {
            return;
        }
        foreach (self::CONTROLS as $key => [$ident, , $kind]) {
            if (!isset($values[$key])) {
                continue;
            }
            $value = match ($kind) {
                'switch' => (bool) $values[$key],
                'clock'  => AlphaTimeGrid::toTimestamp((string) $values[$key]),
                default  => (int) $values[$key],
            };
            if ($this->GetValue($ident) !== $value) {
                $this->SetValue($ident, $value);
            }
        }
    }
}

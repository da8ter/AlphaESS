<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AlphaDataFlow.php';
require_once __DIR__ . '/../libs/AlphaTimeGrid.php';
require_once __DIR__ . '/../libs/AlphaDispatch.php';
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
        'battery_charge_energy'    => ['ChargeEnergy', 'Energy charged', 'arrow-down', ' kWh', 1, 50],
        'battery_discharge_energy' => ['DischargeEnergy', 'Energy discharged', 'arrow-up', ' kWh', 1, 51],
    ];

    /** @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int}> nur über Modbus, fehlen im Modus „Nur Cloud“ */
    private const MODBUS_VARIABLES = [
        'battery_voltage' => ['Voltage', 'Voltage', 'bolt', ' V', 1, 40],
        'battery_current' => ['Current', 'Current', 'bolt', ' A', 1, 41],
    ];

    private const COUNTERS = ['battery_charge_energy', 'battery_discharge_energy'];

    /**
     * Weitere Modbus-Werte; die Variable entsteht mit dem ersten Wert und entfällt im Modus „Nur Cloud“.
     * Belegung und Skalierung nach der Registerliste, an einer Anlage noch nicht alle geprüft.
     * @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int,6?:string}>
     */
    private const MODBUS_EXTRA = [
        'battery_soh'                  => ['SOH', 'State of health', 'heart-pulse', ' %', 1, 42],
        'battery_grid_charge_energy'   => ['GridChargeEnergy', 'Charged from grid', 'plug', ' kWh', 1, 55],
        'battery_modules'              => ['Modules', 'Battery modules', 'layer-group', '', 0, 63, 'int'],
        'battery_min_cell_voltage'     => ['MinCellVoltage', 'Lowest cell voltage', 'bolt', ' V', 3, 70],
        'battery_max_cell_voltage'     => ['MaxCellVoltage', 'Highest cell voltage', 'bolt', ' V', 3, 71],
        'battery_min_cell_temperature' => ['MinCellTemperature', 'Lowest cell temperature', 'temperature-half', ' °C', 1, 72],
        'battery_max_cell_temperature' => ['MaxCellTemperature', 'Highest cell temperature', 'temperature-half', ' °C', 1, 73],
        'battery_status'               => ['BatteryStatus', 'Battery status code', 'circle-info', '', 0, 80, 'int'],
        'battery_warning'              => ['BatteryWarning', 'Battery warning code', 'triangle-exclamation', '', 0, 81, 'int'],
        'battery_fault'                => ['BatteryFault', 'Battery fault code', 'circle-exclamation', '', 0, 82, 'int'],
        'window_reserve_soc'           => ['ReserveSOC', 'Backup reserve', 'battery-quarter', ' %', 0, 90],
    ];

    /**
     * Zeitsteuerung der Anlage über Modbus, nur lesend. Mit „Cloud-Steuerung“ zeigen die schaltbaren
     * Cloud-Variablen dasselbe; diese hier gibt es dann nicht.
     * @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int,6?:string}>
     */
    private const MODBUS_WINDOWS = [
        'window_charge_limit'     => ['WindowChargeLimit', 'Charge up to (system)', 'battery-full', ' %', 0, 120],
        'window_charge_start1'    => ['WindowChargeStart1', 'Charging window 1 start (system)', '', '', 0, 121, 'clock'],
        'window_charge_end1'      => ['WindowChargeEnd1', 'Charging window 1 end (system)', '', '', 0, 122, 'clock'],
        'window_charge_start2'    => ['WindowChargeStart2', 'Charging window 2 start (system)', '', '', 0, 123, 'clock'],
        'window_charge_end2'      => ['WindowChargeEnd2', 'Charging window 2 end (system)', '', '', 0, 124, 'clock'],
        'window_discharge_start1' => ['WindowDischargeStart1', 'Discharging window 1 start (system)', '', '', 0, 125, 'clock'],
        'window_discharge_end1'   => ['WindowDischargeEnd1', 'Discharging window 1 end (system)', '', '', 0, 126, 'clock'],
        'window_discharge_start2' => ['WindowDischargeStart2', 'Discharging window 2 start (system)', '', '', 0, 127, 'clock'],
        'window_discharge_end2'   => ['WindowDischargeEnd2', 'Discharging window 2 end (system)', '', '', 0, 128, 'clock'],
    ];

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
        $this->RegisterPropertyBoolean('ModbusControl', false);
        $this->RegisterAttributeBoolean('CloudOnly', false);

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

        $this->alphaModbusValues(self::MODBUS_VARIABLES, [], !$this->alphaCloudOnly());
        $control = $this->ReadPropertyBoolean('CloudControl');
        $this->modbusExtras([], $this->alphaCloudOnly());
        $this->dispatchVariables($this->alphaCloudOnly());
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
        if (isset(self::DISPATCH[$Ident])) {
            $this->dispatchAction($Ident, $Value);
            return;
        }
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

    /** Speichersteuerung über Modbus: Variable => Feld im Befehl an die Anlage */
    private const DISPATCH = ['Dispatch' => 'Mode', 'DispatchPower' => 'Power', 'DispatchSOC' => 'Soc'];

    /** Die drei Variablen der Speichersteuerung gibt es nur mit „Über Modbus steuern“ und Modbus. */
    private function dispatchVariables(bool $cloudOnly): void
    {
        $keep = $this->ReadPropertyBoolean('ModbusControl') && !$cloudOnly;
        $this->MaintainVariable('Dispatch', $this->Translate('Battery control'), VARIABLETYPE_INTEGER, AlphaPresentations::enumeration('sliders', [
            [AlphaDispatch::AUTO, $this->Translate('Automatic (self-consumption)'), AlphaPresentations::GREY],
            [AlphaDispatch::NO_DISCHARGE, $this->Translate('Do not discharge'), AlphaPresentations::BLUE],
            [AlphaDispatch::HOLD, $this->Translate('Hold (neither charge nor discharge)'), AlphaPresentations::BLUE],
            [AlphaDispatch::CHARGE, $this->Translate('Charge from grid'), AlphaPresentations::GREEN],
            [AlphaDispatch::DISCHARGE, $this->Translate('Discharge'), AlphaPresentations::ORANGE],
        ]), 200, $keep);
        $this->MaintainVariable('DispatchPower', $this->Translate('Control power'), VARIABLETYPE_INTEGER,
            AlphaPresentations::slider('bolt', ' W', 0, 10000, 100), 201, $keep);
        $this->MaintainVariable('DispatchSOC', $this->Translate('Control target state of charge'), VARIABLETYPE_INTEGER,
            AlphaPresentations::slider('battery-half', ' %', AlphaDispatch::SOC_MIN, AlphaDispatch::SOC_MAX), 202, $keep);
        if ($keep) {
            foreach (array_keys(self::DISPATCH) as $ident) {
                $this->EnableAction($ident);
            }
        }
    }

    /** @param array<string,mixed> $values */
    private function dispatchValues(array $values): void
    {
        if (array_key_exists('cloud_only', $values)) {
            $this->dispatchVariables($this->alphaCloudOnly()); // Wechsel des Modus „Nur Cloud“
        }
        if (!$this->ReadPropertyBoolean('ModbusControl') || $this->alphaCloudOnly()) {
            return;
        }
        foreach (['Dispatch' => 'dispatch_mode', 'DispatchPower' => 'dispatch_power', 'DispatchSOC' => 'dispatch_soc'] as $ident => $key) {
            if (is_numeric($values[$key] ?? null) && $this->GetValue($ident) !== (int) $values[$key]) {
                $this->SetValue($ident, (int) $values[$key]);
            }
        }
    }

    private function dispatchAction(string $ident, mixed $value): void
    {
        $command = ['Mode' => $this->GetValue('Dispatch'), 'Power' => $this->GetValue('DispatchPower'), 'Soc' => $this->GetValue('DispatchSOC')];
        $command[self::DISPATCH[$ident]] = (int) $value;
        $answer = $this->alphaSend('Dispatch', $command);
        if (empty($answer['ok'])) {
            $this->alphaFail($answer, 'The battery control could not be changed');
            return;
        }
        $this->dispatchValues(['dispatch_mode' => $answer['values']['mode'] ?? $command['Mode'],
            'dispatch_power' => $answer['values']['power'] ?? $command['Power'], 'dispatch_soc' => $answer['values']['soc'] ?? $command['Soc']]);
    }

    /**
     * Zusatzwerte und die Zeitsteuerung der Anlage aus Modbus.
     * @param array<string,mixed> $values
     */
    private function modbusExtras(array $values, bool $cloudOnly): void
    {
        $this->alphaModbusExtra(self::MODBUS_EXTRA, $values, $cloudOnly);
        $windows = !$cloudOnly && !$this->ReadPropertyBoolean('CloudControl');
        $this->alphaModbusExtra(self::MODBUS_WINDOWS, $windows ? $values : [], !$windows);
        // 0 aus, 1 Laden, 2 Entladen, 3 beides zeitgesteuert (Registerliste)
        if (!$windows) {
            $this->MaintainVariable('WindowControl', '', VARIABLETYPE_INTEGER, [], 0, false);
        } elseif (is_numeric($values['window_control'] ?? null)) {
            $this->MaintainVariable('WindowControl', $this->Translate('Time control (system)'), VARIABLETYPE_INTEGER, AlphaPresentations::state('clock', [
                [0, $this->Translate('Off'), AlphaPresentations::GREY],
                [1, $this->Translate('Charging only'), AlphaPresentations::GREEN],
                [2, $this->Translate('Discharging only'), AlphaPresentations::ORANGE],
                [3, $this->Translate('Charging and discharging'), AlphaPresentations::BLUE],
            ]), 119, true);
            $this->SetValue('WindowControl', (int) $values['window_control']);
        }
    }

    /** @param array<string,mixed> $values */
    private function applyValues(array $values): void
    {
        $this->alphaModbusValues(self::MODBUS_VARIABLES, $values, !$this->alphaCloudOnly($values));
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
        $this->modbusExtras($values, $this->alphaCloudOnly());
        $this->dispatchValues($values);
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

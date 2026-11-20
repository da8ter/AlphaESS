<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AlphaDataFlow.php';
require_once __DIR__ . '/../libs/AlphaPresentations.php';
require_once __DIR__ . '/../libs/AlphaFaults.php';
require_once __DIR__ . '/../libs/AlphaDevice.php';

// Wechselrichter einer Alpha-ESS-Anlage: Erzeugung, Netz, Verbrauch. Werte kommen vom System (Modbus oder Cloud).
class AlphaESSInverter extends IPSModuleStrict
{
    use AlphaDevice;

    private const MAX_STRINGS = 6;

    /** @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int}> Wert => [Ident, Name, Icon, Suffix, Stellen, Position] */
    private const VARIABLES = [
        'pv_power'                => ['PVPower', 'PV power', 'solar-panel', ' W', 0, 10],
        'grid_power'              => ['GridPower', 'Grid power', 'plug', ' W', 0, 30],
        'grid_power_l1'           => ['GridPowerL1', 'Grid power L1', 'plug', ' W', 0, 31],
        'grid_power_l2'           => ['GridPowerL2', 'Grid power L2', 'plug', ' W', 0, 32],
        'grid_power_l3'           => ['GridPowerL3', 'Grid power L3', 'plug', ' W', 0, 33],
        'load_power'              => ['LoadPower', 'Consumption', 'house', ' W', 0, 40],
        'inverter_power'          => ['InverterPower', 'Inverter power', 'bolt', ' W', 0, 50],
        'grid_feed_in_energy'     => ['GridFeedInEnergy', 'Grid feed-in', 'arrow-up', ' kWh', 2, 60],
        'grid_consumption_energy' => ['GridConsumptionEnergy', 'Grid consumption', 'arrow-down', ' kWh', 2, 61],
        'pv_energy'               => ['PVEnergy', 'PV energy', 'solar-panel', ' kWh', 1, 62],
    ];

    /** @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int}> nur über Modbus, fehlen im Modus „Nur Cloud“ */
    private const MODBUS_VARIABLES = [
        'grid_frequency' => ['Frequency', 'Grid frequency', 'wave-square', ' Hz', 2, 70],
    ];

    private const COUNTERS = ['grid_feed_in_energy', 'grid_consumption_energy', 'pv_energy'];

    /**
     * Weitere Modbus-Werte; die Variable entsteht mit dem ersten Wert und entfällt im Modus „Nur Cloud“.
     * Belegung und Skalierung nach der Registerliste, an einer Anlage noch nicht alle geprüft.
     * @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int,6?:string}>
     */
    private const MODBUS_EXTRA = [
        'grid_voltage_l1'        => ['GridVoltageL1', 'Grid voltage L1', 'plug', ' V', 0, 100],
        'grid_voltage_l2'        => ['GridVoltageL2', 'Grid voltage L2', 'plug', ' V', 0, 101],
        'grid_voltage_l3'        => ['GridVoltageL3', 'Grid voltage L3', 'plug', ' V', 0, 102],
        'grid_current_l1'        => ['GridCurrentL1', 'Grid current L1', 'plug', ' A', 1, 103],
        'grid_current_l2'        => ['GridCurrentL2', 'Grid current L2', 'plug', ' A', 1, 104],
        'grid_current_l3'        => ['GridCurrentL3', 'Grid current L3', 'plug', ' A', 1, 105],
        'grid_reactive_power_l1' => ['GridReactivePowerL1', 'Grid reactive power L1', 'plug', ' var', 0, 106],
        'grid_reactive_power_l2' => ['GridReactivePowerL2', 'Grid reactive power L2', 'plug', ' var', 0, 107],
        'grid_reactive_power_l3' => ['GridReactivePowerL3', 'Grid reactive power L3', 'plug', ' var', 0, 108],
        'grid_reactive_power'    => ['GridReactivePower', 'Grid reactive power', 'plug', ' var', 0, 109],
        'grid_apparent_power_l1' => ['GridApparentPowerL1', 'Grid apparent power L1', 'plug', ' VA', 0, 110],
        'grid_apparent_power_l2' => ['GridApparentPowerL2', 'Grid apparent power L2', 'plug', ' VA', 0, 111],
        'grid_apparent_power_l3' => ['GridApparentPowerL3', 'Grid apparent power L3', 'plug', ' VA', 0, 112],
        'grid_apparent_power'    => ['GridApparentPower', 'Grid apparent power', 'plug', ' VA', 0, 113],
        'grid_power_factor_l1'   => ['GridPowerFactorL1', 'Grid power factor L1', 'plug', '', 2, 114],
        'grid_power_factor_l2'   => ['GridPowerFactorL2', 'Grid power factor L2', 'plug', '', 2, 115],
        'grid_power_factor_l3'   => ['GridPowerFactorL3', 'Grid power factor L3', 'plug', '', 2, 116],
        'grid_power_factor'      => ['GridPowerFactor', 'Grid power factor', 'plug', '', 2, 117],
        'inverter_voltage_l1'    => ['InverterVoltageL1', 'Inverter voltage L1', 'bolt', ' V', 1, 120],
        'inverter_voltage_l2'    => ['InverterVoltageL2', 'Inverter voltage L2', 'bolt', ' V', 1, 121],
        'inverter_voltage_l3'    => ['InverterVoltageL3', 'Inverter voltage L3', 'bolt', ' V', 1, 122],
        'inverter_current_l1'    => ['InverterCurrentL1', 'Inverter current L1', 'bolt', ' A', 1, 123],
        'inverter_current_l2'    => ['InverterCurrentL2', 'Inverter current L2', 'bolt', ' A', 1, 124],
        'inverter_current_l3'    => ['InverterCurrentL3', 'Inverter current L3', 'bolt', ' A', 1, 125],
        'inverter_temperature'   => ['InverterTemperature', 'Inverter temperature', 'temperature-half', ' °C', 1, 126],
        'backup_power'           => ['BackupPower', 'Backup power', 'car-battery', ' W', 0, 130],
        'backup_power_l1'        => ['BackupPowerL1', 'Backup power L1', 'car-battery', ' W', 0, 131],
        'backup_power_l2'        => ['BackupPowerL2', 'Backup power L2', 'car-battery', ' W', 0, 132],
        'backup_power_l3'        => ['BackupPowerL3', 'Backup power L3', 'car-battery', ' W', 0, 133],
        'backup_voltage_l1'      => ['BackupVoltageL1', 'Backup voltage L1', 'car-battery', ' V', 1, 134],
        'backup_voltage_l2'      => ['BackupVoltageL2', 'Backup voltage L2', 'car-battery', ' V', 1, 135],
        'backup_voltage_l3'      => ['BackupVoltageL3', 'Backup voltage L3', 'car-battery', ' V', 1, 136],
        'backup_current_l1'      => ['BackupCurrentL1', 'Backup current L1', 'car-battery', ' A', 1, 137],
        'backup_current_l2'      => ['BackupCurrentL2', 'Backup current L2', 'car-battery', ' A', 1, 138],
        'backup_current_l3'      => ['BackupCurrentL3', 'Backup current L3', 'car-battery', ' A', 1, 139],
        'system_pv_energy'       => ['SystemPVEnergy', 'PV energy of the system', 'solar-panel', ' kWh', 1, 140],
        'pv_energy_ac_coupled'   => ['PVEnergyACCoupled', 'PV energy (AC-coupled)', 'solar-panel', ' kWh', 1, 141],
        'pv_capacity'            => ['PVCapacity', 'PV capacity', 'solar-panel', ' W', 0, 142],
        'pv_capacity_ac_coupled' => ['PVCapacityACCoupled', 'PV capacity (AC-coupled)', 'solar-panel', ' W', 0, 143],
        'feed_in_limit'          => ['FeedInLimit', 'Feed-in limit', 'arrow-up', ' %', 0, 144],
        'inverter_warning1'      => ['InverterWarning1', 'Inverter warning code 1', 'triangle-exclamation', '', 0, 150, 'int'],
        'inverter_warning2'      => ['InverterWarning2', 'Inverter warning code 2', 'triangle-exclamation', '', 0, 151, 'int'],
        'inverter_fault1'        => ['InverterFault1', 'Inverter fault code 1', 'circle-exclamation', '', 0, 152, 'int'],
        'inverter_fault2'        => ['InverterFault2', 'Inverter fault code 2', 'circle-exclamation', '', 0, 153, 'int'],
        'system_fault'           => ['SystemFault', 'System fault code', 'circle-exclamation', '', 0, 154, 'int'],
    ];

    /** @var array<string,array{0:string,1:string,2:string,3:string,4:int,5:int}> entstehen mit dem ersten Wert (Phasen auch über Modbus, sonst nur Cloud) */
    private const CLOUD_VARIABLES = [
        'inverter_power_l1'      => ['InverterPowerL1', 'Inverter power L1', 'bolt', ' W', 0, 51],
        'inverter_power_l2'      => ['InverterPowerL2', 'Inverter power L2', 'bolt', ' W', 0, 52],
        'inverter_power_l3'      => ['InverterPowerL3', 'Inverter power L3', 'bolt', ' W', 0, 53],
        'pv_energy_today'        => ['PVEnergyToday', 'PV energy today', 'solar-panel', ' kWh', 2, 63],
        'grid_feed_in_today'     => ['GridFeedInToday', 'Grid feed-in today', 'arrow-up', ' kWh', 2, 64],
        'grid_consumption_today' => ['GridConsumptionToday', 'Grid consumption today', 'arrow-down', ' kWh', 2, 65],
        'load_energy'            => ['LoadEnergy', 'Consumption total', 'house', ' kWh', 1, 66],
        'self_consumption'       => ['SelfConsumption', 'Self-consumption', 'recycle', ' %', 1, 90],
        'self_sufficiency'       => ['SelfSufficiency', 'Self-sufficiency', 'house-circle-check', ' %', 1, 91],
        'load_energy_today'      => ['LoadEnergyToday', 'Consumption today', 'house', ' kWh', 2, 67],
        'self_consumption_today' => ['SelfConsumptionToday', 'Self-consumption today', 'recycle', ' %', 1, 92],
        'self_sufficiency_today' => ['SelfSufficiencyToday', 'Self-sufficiency today', 'house-circle-check', ' %', 1, 93],
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('PVStrings', 2);
        $this->RegisterPropertyBoolean('PVMeter', false);
        $this->RegisterAttributeBoolean('CloudOnly', false);

        foreach (self::VARIABLES as [$ident, $name, $icon, $suffix, $digits, $position]) {
            $this->RegisterVariableFloat($ident, $this->Translate($name), AlphaPresentations::value($icon, $suffix, $digits), $position);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $strings = $this->strings();
        for ($i = 1; $i <= self::MAX_STRINGS; $i++) {
            $this->MaintainVariable("PV{$i}Power", sprintf($this->Translate('PV string %d'), $i), VARIABLETYPE_FLOAT,
                AlphaPresentations::value('solar-panel', ' W'), 10 + $i, $i <= $strings);
        }
        $this->stringDetails($this->alphaCloudOnly());
        $this->alphaModbusExtra(self::MODBUS_EXTRA, [], $this->alphaCloudOnly());
        $this->MaintainVariable('PVMeterPower', $this->Translate('PV power (AC-coupled)'), VARIABLETYPE_FLOAT,
            AlphaPresentations::value('solar-panel', ' W'), 20, $this->ReadPropertyBoolean('PVMeter'));
        $this->modbusOnly([]);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->alphaFilter(AlphaDataFlow::KIND_INVERTER);
        $this->applyValues($this->alphaState(AlphaDataFlow::KIND_INVERTER));
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        } elseif ($Message === FM_CONNECT) {
            $this->applyValues($this->alphaState(AlphaDataFlow::KIND_INVERTER));
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $values = $this->alphaValues($JSONString, AlphaDataFlow::KIND_INVERTER);
        if ($values !== null) {
            $this->applyValues($values);
        }
        return '';
    }

    private function strings(): int
    {
        return max(0, min(self::MAX_STRINGS, $this->ReadPropertyInteger('PVStrings')));
    }

    /**
     * Netzfrequenz und Betriebsart gibt es nur über Modbus.
     * @param array<string,mixed> $values
     */
    private function modbusOnly(array $values): void
    {
        $keep = !$this->alphaCloudOnly($values);
        $this->alphaModbusValues(self::MODBUS_VARIABLES, $values, $keep);
        // Betriebsmodus laut Registerliste (Note5): 0 Warten, 1 Normalbetrieb, 2 Notstrom, 3 Bypass, 4 Fehler,
        // 5 DC-Betrieb, 6 Selbsttest, 7 Prüfung, 8–10 Firmware-Update (Master, Slave, ARM)
        $this->MaintainVariable('Mode', $this->Translate('Operating mode'), VARIABLETYPE_INTEGER, AlphaPresentations::state('circle-info', [
            [0, $this->Translate('Waiting'), AlphaPresentations::GREY],
            [1, $this->Translate('Normal operation'), AlphaPresentations::GREEN],
            [2, $this->Translate('Backup power'), AlphaPresentations::BLUE],
            [3, $this->Translate('Bypass'), AlphaPresentations::ORANGE],
            [4, $this->Translate('Fault'), AlphaPresentations::RED],
            [5, $this->Translate('DC mode'), AlphaPresentations::ORANGE],
            [6, $this->Translate('Self test'), AlphaPresentations::GREY],
            [7, $this->Translate('Grid check'), AlphaPresentations::GREY],
            [8, $this->Translate('Firmware update (master)'), AlphaPresentations::BLUE],
            [9, $this->Translate('Firmware update (slave)'), AlphaPresentations::BLUE],
            [10, $this->Translate('Firmware update (ARM)'), AlphaPresentations::BLUE],
        ]), 80, $keep);
        if ($keep && is_numeric($values['inverter_mode'] ?? null)) {
            $this->SetValue('Mode', (int) $values['inverter_mode']);
        }
    }

    /** Spannung und Strom je String gibt es nur über Modbus; im Modus „Nur Cloud“ entfallen sie. */
    private function stringDetails(bool $cloudOnly): void
    {
        for ($i = 1, $n = $this->strings(); $i <= self::MAX_STRINGS; $i++) {
            $keep = $i <= $n && !$cloudOnly;
            $this->MaintainVariable("PV{$i}Voltage", sprintf($this->Translate('PV string %d voltage'), $i), VARIABLETYPE_FLOAT,
                AlphaPresentations::value('bolt', ' V', 1), 20 + $i, $keep);
            $this->MaintainVariable("PV{$i}Current", sprintf($this->Translate('PV string %d current'), $i), VARIABLETYPE_FLOAT,
                AlphaPresentations::value('bolt', ' A', 1), 26 + $i, $keep);
        }
    }

    /**
     * Gesetzte Fehler- und Warnbits als Text („100012 PV_Reverse“), Nummern laut Installationshandbuch.
     * @param array<string,mixed> $values
     */
    private function faultTexts(array $values, bool $cloudOnly): void
    {
        foreach (['InverterFaults' => ['inverter_fault1', 'inverter_fault2', 'Inverter faults', 'circle-exclamation', 155],
            'InverterWarnings' => ['inverter_warning1', 'inverter_warning2', 'Inverter warnings', 'triangle-exclamation', 156]] as $ident => [$first, $second, $name, $icon, $position]) {
            if ($cloudOnly) {
                $this->MaintainVariable($ident, '', VARIABLETYPE_STRING, [], 0, false);
                continue;
            }
            if (!is_numeric($values[$first] ?? null) || !is_numeric($values[$second] ?? null)) {
                continue;
            }
            $text = $ident === 'InverterFaults' ? AlphaFaults::faults((int) $values[$first], (int) $values[$second])
                : AlphaFaults::warnings((int) $values[$first], (int) $values[$second]);
            $text = $text === '' ? $this->Translate('None') : $text;
            $this->MaintainVariable($ident, $this->Translate($name), VARIABLETYPE_STRING, AlphaPresentations::text($icon), $position, true);
            $this->SetValue($ident, $text);
        }
    }

    /** @param array<string,mixed> $values */
    private function applyValues(array $values): void
    {
        $this->modbusOnly($values);
        $cloudOnly = $this->alphaCloudOnly();
        $this->stringDetails($cloudOnly);
        for ($i = 1, $n = $cloudOnly ? 0 : $this->strings(); $i <= $n; $i++) {
            foreach (['Voltage' => 'voltage', 'Current' => 'current'] as $suffix => $field) {
                if (is_numeric($values["pv{$i}_{$field}"] ?? null)) {
                    $this->SetValue("PV{$i}{$suffix}", (float) $values["pv{$i}_{$field}"]);
                }
            }
        }
        $this->alphaModbusExtra(self::MODBUS_EXTRA, $values, $cloudOnly);
        $this->faultTexts($values, $cloudOnly);
        foreach (self::VARIABLES as $key => [$ident]) {
            if (!isset($values[$key]) || !is_numeric($values[$key])) {
                continue;
            }
            if (in_array($key, self::COUNTERS, true)) {
                $this->alphaCounter($ident, (float) $values[$key]);
            } else {
                $this->SetValue($ident, (float) $values[$key]);
            }
        }
        for ($i = 1, $n = $this->strings(); $i <= $n; $i++) {
            if (is_numeric($values["pv{$i}_power"] ?? null)) {
                $this->SetValue("PV{$i}Power", (float) $values["pv{$i}_power"]);
            }
        }
        if ($this->ReadPropertyBoolean('PVMeter') && is_numeric($values['pv_meter_power'] ?? null)) {
            $this->SetValue('PVMeterPower', (float) $values['pv_meter_power']);
        }
        $this->alphaCloudValues(self::CLOUD_VARIABLES, $values, ['load_energy']);
    }
}

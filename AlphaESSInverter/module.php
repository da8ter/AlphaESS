<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AlphaDataFlow.php';
require_once __DIR__ . '/../libs/AlphaPresentations.php';
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
        $this->stringVoltages($this->alphaCloudOnly());
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
        $this->MaintainVariable('Mode', $this->Translate('Operating mode'), VARIABLETYPE_INTEGER, AlphaPresentations::value('circle-info', ''), 80, $keep);
        if ($keep && is_numeric($values['inverter_mode'] ?? null)) {
            $this->SetValue('Mode', (int) $values['inverter_mode']);
        }
    }

    /** Spannung je String gibt es nur über Modbus; im Modus „Nur Cloud“ entfällt sie. */
    private function stringVoltages(bool $cloudOnly): void
    {
        for ($i = 1, $n = $this->strings(); $i <= self::MAX_STRINGS; $i++) {
            $this->MaintainVariable("PV{$i}Voltage", sprintf($this->Translate('PV string %d voltage'), $i), VARIABLETYPE_FLOAT,
                AlphaPresentations::value('bolt', ' V', 1), 20 + $i, $i <= $n && !$cloudOnly);
        }
    }

    /** @param array<string,mixed> $values */
    private function applyValues(array $values): void
    {
        $this->modbusOnly($values);
        $cloudOnly = $this->alphaCloudOnly();
        $this->stringVoltages($cloudOnly);
        for ($i = 1, $n = $cloudOnly ? 0 : $this->strings(); $i <= $n; $i++) {
            if (is_numeric($values["pv{$i}_voltage"] ?? null)) {
                $this->SetValue("PV{$i}Voltage", (float) $values["pv{$i}_voltage"]);
            }
        }
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

<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AlphaDataFlow.php';
require_once __DIR__ . '/../libs/AlphaPresentations.php';
require_once __DIR__ . '/../libs/AlphaDevice.php';

// Wechselrichter einer Alpha-ESS-Anlage: Erzeugung, Netz, Verbrauch. Werte kommen vom System (Modbus).
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
        'grid_frequency'          => ['Frequency', 'Grid frequency', 'wave-square', ' Hz', 2, 70],
    ];

    private const COUNTERS = ['grid_feed_in_energy', 'grid_consumption_energy', 'pv_energy'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('PVStrings', 2);
        $this->RegisterPropertyBoolean('PVMeter', false);

        foreach (self::VARIABLES as [$ident, $name, $icon, $suffix, $digits, $position]) {
            $this->RegisterVariableFloat($ident, $this->Translate($name), AlphaPresentations::value($icon, $suffix, $digits), $position);
        }
        $this->RegisterVariableInteger('Mode', $this->Translate('Operating mode'), AlphaPresentations::value('circle-info', ''), 80);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $strings = $this->strings();
        for ($i = 1; $i <= self::MAX_STRINGS; $i++) {
            $this->MaintainVariable("PV{$i}Power", sprintf($this->Translate('PV string %d'), $i), VARIABLETYPE_FLOAT,
                AlphaPresentations::value('solar-panel', ' W'), 10 + $i, $i <= $strings);
        }
        $this->MaintainVariable('PVMeterPower', $this->Translate('PV power (AC-coupled)'), VARIABLETYPE_FLOAT,
            AlphaPresentations::value('solar-panel', ' W'), 20, $this->ReadPropertyBoolean('PVMeter'));

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

    /** @param array<string,mixed> $values */
    private function applyValues(array $values): void
    {
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
        if (is_numeric($values['inverter_mode'] ?? null)) {
            $this->SetValue('Mode', (int) $values['inverter_mode']);
        }
    }
}

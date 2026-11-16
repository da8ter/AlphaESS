<?php

declare(strict_types=1);

// Modbus-Teil des Systems: liest die Registerblöcke über das ModBus Gateway und verteilt die Werte.
trait AlphaSystemModbus
{
    /** Pause nach dem n-ten Fehlzyklus in Folge; der erste wird beim nächsten Takt wiederholt */
    private const MODBUS_BACKOFF = [0, 30, 60, 120];
    /** Ab dieser Dauer gilt ein Fehlversuch als Zeitüberschreitung des Gateways */
    private const MODBUS_SLOW_SECONDS = 1.0;
    /** Abstand für die selten veränderlichen Bereiche (AlphaRegisters::SLOW_BLOCKS) */
    private const MODBUS_SLOW_BLOCKS_INTERVAL = 300;

    private function pollModbus(): void
    {
        if ($this->ReadPropertyBoolean('CloudOnly')) {
            return; // auch „Jetzt lesen“ fragt dann kein Gateway
        }
        $lock = 'AESS_' . $this->InstanceID . '_Poll';
        if (!IPS_SemaphoreEnter($lock, 1)) {
            return; // der vorige Zyklus wartet noch auf das Gerät
        }
        try {
            $this->pollModbusLocked();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function pollModbusLocked(): void
    {
        $now = AlphaClock::now();
        if ($now < (int) $this->GetBuffer('ModbusRetryAt')) {
            return;
        }
        if ((int) IPS_GetInstance($this->InstanceID)['ConnectionID'] === 0) {
            $this->setState('ModbusState', 0);
            return;
        }

        $values = [];
        $read = 0;
        $slowDue = $now >= (int) $this->GetBuffer('ModbusSlowAt');
        foreach (AlphaRegisters::BLOCKS as $name => $block) {
            if (!$slowDue && in_array($name, AlphaRegisters::SLOW_BLOCKS, true)) {
                continue;
            }
            $start = microtime(true);
            $answer = @$this->SendDataToParent(AlphaModbusGateway::request($block['address'], $block['quantity']));
            $data = AlphaModbusGateway::payload($answer, $block['quantity']);
            if ($data === null) {
                $this->SendDebug('Modbus', 'Block ' . $name . ' not read', 0);
                // Ein stummes Gerät kostet je Block den Timeout des Gateways: dann nicht weiterfragen.
                if (microtime(true) - $start > self::MODBUS_SLOW_SECONDS || !$this->HasActiveParent()) {
                    break;
                }
                continue;
            }
            $values += AlphaRegisters::decode($name, $data);
            $read++;
        }

        $fails = (int) $this->GetBuffer('ModbusFails');
        if ($read === 0) {
            $fails++;
            $this->SetBuffer('ModbusFails', (string) $fails);
            $this->SetBuffer('ModbusRetryAt', (string) ($now + self::MODBUS_BACKOFF[min($fails, count(self::MODBUS_BACKOFF)) - 1]));
            if ($fails === 2) {
                $this->LogMessage($this->Translate('No answer from the Alpha ESS system via Modbus'), KL_WARNING);
            }
            $this->setState('ModbusState', 2);
            return;
        }
        if ($fails >= 2) {
            $this->LogMessage($this->Translate('Modbus connection to the Alpha ESS system restored'), KL_NOTIFY);
        }
        $this->SetBuffer('ModbusFails', '0');
        $this->SetBuffer('ModbusRetryAt', '0');
        if ($slowDue) {
            $this->SetBuffer('ModbusSlowAt', (string) ($now + self::MODBUS_SLOW_BLOCKS_INTERVAL));
        }
        $this->setState('ModbusState', 1);

        $parts = AlphaRegisters::split(AlphaRegisters::derive($values));
        $extra = $this->energyWithoutCloud($values, $now);
        $this->publish(AlphaDataFlow::KIND_INVERTER, '', 'modbus', $parts['inverter'] + $extra['inverter']);
        $dispatch = $this->dispatchState();
        $this->publish(AlphaDataFlow::KIND_BATTERY, '', 'modbus', $parts['battery'] + $extra['battery']
            + ['dispatch_mode' => $dispatch['mode'], 'dispatch_power' => $dispatch['power'], 'dispatch_soc' => $dispatch['soc']]);
        $this->dispatchRefresh($now);
    }

    /**
     * Ohne Cloud-Zugang errechnet die Anlage Verbrauch, Eigenverbrauch, Autarkie und Tageswerte selbst
     * aus den Zählern. Mit Zugang liefert die Cloud diese Werte; zwei Quellen stritten sonst um eine Variable.
     * @param array<string,mixed> $values
     * @return array{inverter:array<string,float>,battery:array<string,float>}
     */
    private function energyWithoutCloud(array $values, int $now): array
    {
        $none = ['inverter' => [], 'battery' => []];
        $counters = AlphaEnergy::counters($values);
        if ($counters === null || $this->cloudConfigured()) {
            return $none;
        }
        $start = json_decode($this->ReadAttributeString('DayStart'), true);
        [$today, $start] = AlphaEnergy::today($counters, is_array($start) ? $start : [], date('Y-m-d', $now));
        $json = (string) json_encode($start);
        if ($json !== $this->ReadAttributeString('DayStart')) {
            $this->WriteAttributeString('DayStart', $json);
        }
        return ['inverter' => $today['inverter'] + AlphaEnergy::totals($counters) + AlphaEnergy::todayTotals($today['inverter'] + $today['battery']),
            'battery' => $today['battery']];
    }
}

<?php

declare(strict_types=1);

// Modbus-Teil des Systems: liest die Registerblöcke über das ModBus Gateway und verteilt die Werte.
trait AlphaSystemModbus
{
    /** Pause nach dem n-ten Fehlzyklus in Folge; der erste wird beim nächsten Takt wiederholt */
    private const MODBUS_BACKOFF = [0, 30, 60, 120];
    /** Ab dieser Dauer gilt ein Fehlversuch als Zeitüberschreitung des Gateways */
    private const MODBUS_SLOW_SECONDS = 1.0;

    private function pollModbus(): void
    {
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
        foreach (AlphaRegisters::BLOCKS as $name => $block) {
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
        $this->setState('ModbusState', 1);

        $parts = AlphaRegisters::split(AlphaRegisters::derive($values));
        $this->publish(AlphaDataFlow::KIND_INVERTER, '', 'modbus', $parts['inverter']);
        $this->publish(AlphaDataFlow::KIND_BATTERY, '', 'modbus', $parts['battery']);
    }
}

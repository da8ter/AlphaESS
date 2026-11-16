<?php

declare(strict_types=1);

/*
 * Speichersteuerung über Modbus (Dispatch). Ein Befehl gilt DURATION Sekunden; solange eine andere
 * Betriebsart als „Automatisch“ gewählt ist, erneuert der Modbus-Takt ihn alle REFRESH Sekunden.
 * Fällt Symcon oder die Verbindung aus, kehrt die Anlage so nach spätestens DURATION Sekunden in
 * ihren Normalbetrieb zurück.
 */
trait AlphaSystemDispatch
{
    private const DISPATCH_DURATION = 300;
    private const DISPATCH_REFRESH = 120;
    private const DISPATCH_DEFAULT = ['mode' => AlphaDispatch::AUTO, 'power' => 3000, 'soc' => 100];

    /** @return array{mode:int,power:int,soc:int} */
    private function dispatchState(): array
    {
        $state = json_decode($this->ReadAttributeString('Dispatch'), true);
        $state = is_array($state) ? $state + self::DISPATCH_DEFAULT : self::DISPATCH_DEFAULT;
        return ['mode' => (int) $state['mode'], 'power' => (int) $state['power'], 'soc' => (int) $state['soc']];
    }

    /**
     * Neuer Befehl einer Geräteinstanz. Geschrieben wird sofort, wenn sich die Betriebsart ändert oder
     * eine aktive Betriebsart neue Werte bekommt; sonst merkt sich die Anlage nur Leistung und Ziel.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function dispatchCommand(array $data): array
    {
        if ($this->ReadPropertyBoolean('CloudOnly') || (int) IPS_GetInstance($this->InstanceID)['ConnectionID'] === 0) {
            throw new \RuntimeException($this->Translate('The battery can only be controlled via Modbus'));
        }
        $old = $this->dispatchState();
        $new = [
            'mode'  => (int) ($data['Mode'] ?? $old['mode']),
            'power' => max(0, min(AlphaDispatch::POWER_MAX, (int) ($data['Power'] ?? $old['power']))),
            'soc'   => max(AlphaDispatch::SOC_MIN, min(AlphaDispatch::SOC_MAX, (int) ($data['Soc'] ?? $old['soc']))),
        ];
        if (!AlphaDispatch::valid($new['mode'])) {
            throw new \InvalidArgumentException('Unknown mode');
        }
        if ($new['mode'] !== $old['mode'] || ($new['mode'] !== AlphaDispatch::AUTO && $new !== $old)) {
            $lock = 'AESS_' . $this->InstanceID . '_Poll';
            if (!IPS_SemaphoreEnter($lock, 5000)) {
                throw new \RuntimeException($this->Translate('The system is busy, please try again'));
            }
            try {
                $ok = $this->dispatchWrite($new, AlphaClock::now());
            } finally {
                IPS_SemaphoreLeave($lock);
            }
            if (!$ok) {
                throw new \RuntimeException($this->Translate('The system did not accept the command'));
            }
        }
        $this->WriteAttributeString('Dispatch', (string) json_encode($new));
        $this->publish(AlphaDataFlow::KIND_BATTERY, '', 'modbus', ['dispatch_mode' => $new['mode'], 'dispatch_power' => $new['power'], 'dispatch_soc' => $new['soc']]);
        return ['values' => $new];
    }

    /** @param array{mode:int,power:int,soc:int} $state */
    private function dispatchWrite(array $state, int $now): bool
    {
        $values = AlphaDispatch::registers($state['mode'], $state['power'], $state['soc'], self::DISPATCH_DURATION);
        $answer = @$this->SendDataToParent(AlphaModbusGateway::write(AlphaDispatch::ADDRESS, $values));
        $ok = AlphaModbusGateway::written($answer, AlphaDispatch::ADDRESS, count($values));
        $this->SendDebug('Dispatch', implode(',', $values) . ($ok ? ' ok' : ' not confirmed'), 0);
        if ($ok) {
            $this->SetBuffer('DispatchSent', (string) $now);
        }
        return $ok;
    }

    /** Im Modbus-Takt (unter dessen Sperre): einen laufenden Befehl rechtzeitig erneuern. */
    private function dispatchRefresh(int $now): void
    {
        $state = $this->dispatchState();
        if ($state['mode'] === AlphaDispatch::AUTO || $now - (int) $this->GetBuffer('DispatchSent') < self::DISPATCH_REFRESH) {
            return;
        }
        if (!$this->dispatchWrite($state, $now)) {
            $this->LogMessage($this->Translate('The battery control command could not be renewed'), KL_WARNING);
        }
    }
}

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
 */
class AlphaESSSystem extends IPSModuleStrict
{
    use AlphaSystemModbus;
    use AlphaSystemCloud;
    use AlphaSystemForward;

    private const MODBUS_DEVICE_ID = 85;
    private const CLOUD_TICK_MS = 5000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('PollInterval', 10);
        $this->RegisterPropertyString('AppID', '');
        $this->RegisterPropertyString('AppSecret', '');
        $this->RegisterPropertyString('SystemSN', '');
        $this->RegisterPropertyInteger('CloudInterval', 60);
        $this->RegisterPropertyInteger('CloudConfigInterval', 300);

        $this->RegisterAttributeString('Wallboxes', '[]');

        $this->RegisterTimer('Poll', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Poll\', 0);');
        $this->RegisterTimer('CloudTick', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'CloudTick\', 0);');

        $this->RegisterVariableInteger('ModbusState', $this->Translate('Modbus connection'), AlphaPresentations::state('network-wired', [
            [0, $this->Translate('Not connected'), AlphaPresentations::GREY],
            [1, $this->Translate('Connected'), AlphaPresentations::GREEN],
            [2, $this->Translate('No answer'), AlphaPresentations::RED],
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

        $this->SetTimerInterval('Poll', max(2, $this->ReadPropertyInteger('PollInterval')) * 1000);
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

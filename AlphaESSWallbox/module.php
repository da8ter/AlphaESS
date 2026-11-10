<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AlphaDataFlow.php';
require_once __DIR__ . '/../libs/AlphaPresentations.php';
require_once __DIR__ . '/../libs/AlphaDevice.php';

// Wallbox einer Alpha-ESS-Anlage. Status und Steuerung laufen über die Cloud des Systems.
class AlphaESSWallbox extends IPSModuleStrict
{
    use AlphaDevice;

    private const CURRENT_MIN = 6;
    private const CURRENT_MAX = 32;
    /** Zustände, in denen ein Ladevorgang läuft oder freigegeben ist (laden, pausiert) */
    private const SESSION_STATES = [3, 4, 5];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Serial', '');

        $this->RegisterVariableInteger('Status', $this->Translate('Status'), AlphaPresentations::state('charging-station', [
            [0, $this->Translate('Unknown'), AlphaPresentations::GREY],
            [1, $this->Translate('Available'), AlphaPresentations::GREEN],
            [2, $this->Translate('Vehicle connected'), AlphaPresentations::BLUE],
            [3, $this->Translate('Charging'), AlphaPresentations::GREEN],
            [4, $this->Translate('Paused by wallbox'), AlphaPresentations::ORANGE],
            [5, $this->Translate('Paused by vehicle'), AlphaPresentations::ORANGE],
            [6, $this->Translate('Finishing'), AlphaPresentations::BLUE],
            [9, $this->Translate('Fault'), AlphaPresentations::RED],
        ]), 10);
        $this->RegisterVariableFloat('Power', $this->Translate('Charging power'), AlphaPresentations::value('bolt', ' W'), 20);
        $this->RegisterVariableBoolean('Charging', $this->Translate('Charging'), AlphaPresentations::toggle('charging-station'), 30);
        $this->EnableAction('Charging');
        $this->RegisterVariableInteger('Current', $this->Translate('Charging current'),
            AlphaPresentations::slider('gauge', ' A', self::CURRENT_MIN, self::CURRENT_MAX), 40);
        $this->EnableAction('Current');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $serial = $this->serial();
        $this->SetSummary($serial);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->alphaFilter(AlphaDataFlow::KIND_WALLBOX, $serial);
        if ($serial === '') {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        $this->applyValues($this->alphaState(AlphaDataFlow::KIND_WALLBOX, $serial));
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        } elseif ($Message === FM_CONNECT && $this->serial() !== '') {
            $this->applyValues($this->alphaState(AlphaDataFlow::KIND_WALLBOX, $this->serial()));
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $values = $this->alphaValues($JSONString, AlphaDataFlow::KIND_WALLBOX, $this->serial());
        if ($values !== null && $this->serial() !== '') {
            $this->applyValues($values);
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $serial = $this->serial();
        [$answer, $shown] = match ($Ident) {
            'Charging' => [$this->alphaSend('WallboxControl', ['Serial' => $serial, 'Start' => (bool) $Value]), (bool) $Value],
            'Current'  => [$this->alphaSend('WallboxCurrent', ['Amps' => (int) $Value]), max(self::CURRENT_MIN, min(self::CURRENT_MAX, (int) $Value))],
            default    => throw new \InvalidArgumentException('Unknown ident ' . $Ident),
        };
        if (empty($answer['ok'])) {
            throw new \RuntimeException((string) ($answer['error'] ?? $this->Translate('The command could not be sent')));
        }
        $this->SetValue($Ident, $shown);
    }

    private function serial(): string
    {
        return trim($this->ReadPropertyString('Serial'));
    }

    /** @param array<string,mixed> $values */
    private function applyValues(array $values): void
    {
        if (is_numeric($values['status'] ?? null)) {
            $status = (int) $values['status'];
            if ($this->GetValue('Status') !== $status) {
                $this->SetValue('Status', $status);
            }
            $charging = in_array($status, self::SESSION_STATES, true);
            if ($this->GetValue('Charging') !== $charging) {
                $this->SetValue('Charging', $charging);
            }
        }
        if (is_numeric($values['power'] ?? null)) {
            $this->SetValue('Power', (float) $values['power']);
        }
        if (is_numeric($values['current'] ?? null) && $this->GetValue('Current') !== (int) $values['current']) {
            $this->SetValue('Current', (int) $values['current']);
        }
    }
}

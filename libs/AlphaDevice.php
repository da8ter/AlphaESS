<?php

declare(strict_types=1);

// Gemeinsamer Teil der Geräteinstanzen: Anschluss an das System, Empfang und Befehle.
trait AlphaDevice
{
    public function GetCompatibleParents(): string
    {
        return (string) json_encode(['type' => 'connect', 'moduleIDs' => [AlphaDataFlow::MODULE_SYSTEM]]);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> immer mit Schlüssel ok
     */
    private function alphaSend(string $action, array $params = []): array
    {
        if ((int) IPS_GetInstance($this->InstanceID)['ConnectionID'] === 0) {
            return ['ok' => false, 'error' => $this->Translate('No Alpha ESS system connected')];
        }
        $answer = @$this->SendDataToParent(AlphaDataFlow::toSystem($action, $params));
        $data = is_string($answer) ? json_decode($answer, true) : null;
        return is_array($data) && isset($data['ok']) ? $data : ['ok' => false, 'error' => $this->Translate('The Alpha ESS system did not answer')];
    }

    /** Nur Pakete für diese Geräteart (und Seriennummer) kommen an. */
    private function alphaFilter(string $kind, string $serial = ''): void
    {
        $json = substr((string) json_encode($serial), 1, -1);
        $this->SetReceiveDataFilter('.*"Kind":"' . $kind . '","Serial":"' . preg_quote($json, '/') . '".*');
    }

    /** @return array<string,mixed>|null die Werte, wenn das Paket für dieses Gerät ist */
    private function alphaValues(string $json, string $kind, string $serial = ''): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['Kind'] ?? '') !== $kind || (string) ($data['Serial'] ?? '') !== $serial || !is_array($data['Values'] ?? null)) {
            return null;
        }
        return $data['Values'];
    }

    /** @return array<string,mixed> letzter bekannter Stand vom System, leer ohne Anschluss */
    private function alphaState(string $kind, string $serial = ''): array
    {
        $answer = $this->alphaSend('GetState', ['Kind' => $kind, 'Serial' => $serial]);
        return !empty($answer['ok']) && is_array($answer['values'] ?? null) ? $answer['values'] : [];
    }

    /** Zählerstände laufen nur vorwärts; 0 und Rücksprünge sind Lesefehler und würden das Archiv verfälschen. */
    private function alphaCounter(string $ident, float $value): void
    {
        if ($value > 0.0 && $value >= (float) $this->GetValue($ident)) {
            $this->SetValue($ident, $value);
        }
    }
}

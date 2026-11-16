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

    /**
     * Meldet eine gescheiterte Aktion. Gemessen 10.11.2026 (Symcon 9.1): Eine Ausgabe in RequestAction
     * lässt die Aktion scheitern und erscheint als Meldung; eine Ausnahme brächte den Stacktrace mit.
     * @param array<string,mixed> $answer
     */
    private function alphaFail(array $answer, string $fallback): void
    {
        echo (string) ($answer['error'] ?? $this->Translate($fallback));
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

    /**
     * Werte, die nur die Cloud liefert: die Variable entsteht mit dem ersten Wert, damit eine Anlage
     * ohne Cloud-Zugang keine leeren Variablen trägt.
     * @param array<string,array{0:string,1:string,2:string,3:string,4:int,5:int}> $table Wert => [Ident, Name, Icon, Suffix, Stellen, Position]
     * @param array<string,mixed> $values
     * @param list<string> $counters
     */
    private function alphaCloudValues(array $table, array $values, array $counters = []): void
    {
        foreach ($table as $key => [$ident, $name, $icon, $suffix, $digits, $position]) {
            if (!is_numeric($values[$key] ?? null)) {
                continue;
            }
            $this->MaintainVariable($ident, $this->Translate($name), VARIABLETYPE_FLOAT, AlphaPresentations::value($icon, $suffix, $digits), $position, true);
            if (in_array($key, $counters, true)) {
                $this->alphaCounter($ident, (float) $values[$key]);
            } else {
                $this->SetValue($ident, (float) $values[$key]);
            }
        }
    }

    /**
     * Zusatzwerte, die nur Modbus liefert: die Variable entsteht mit dem ersten Wert (ein Lesebereich, den die
     * Firmware nicht kennt, hinterlässt so keine leeren Variablen) und entfällt im Modus „Nur Cloud“.
     * Art: float, int (Codes, Anzahl) oder clock (volle Stunde als Uhrzeit).
     * @param array<string,array{0:string,1:string,2:string,3:string,4:int,5:int,6?:string}> $table Wert => [Ident, Name, Icon, Suffix, Stellen, Position, Art]
     * @param array<string,mixed> $values
     */
    private function alphaModbusExtra(array $table, array $values, bool $cloudOnly): void
    {
        foreach ($table as $key => $row) {
            [$ident, $name, $icon, $suffix, $digits, $position] = $row;
            $kind = $row[6] ?? 'float';
            if ($cloudOnly) {
                $this->MaintainVariable($ident, '', VARIABLETYPE_FLOAT, [], 0, false);
                continue;
            }
            if (!is_numeric($values[$key] ?? null)) {
                continue;
            }
            $value = (float) $values[$key];
            if ($kind === 'clock' && ($value < 0 || $value > 23)) {
                continue;
            }
            [$type, $presentation, $value] = match ($kind) {
                'int'   => [VARIABLETYPE_INTEGER, AlphaPresentations::value($icon, $suffix), (int) $value],
                'clock' => [VARIABLETYPE_INTEGER, AlphaPresentations::clock(), AlphaTimeGrid::toTimestamp(sprintf('%02d:00', (int) $value))],
                default => [VARIABLETYPE_FLOAT, AlphaPresentations::value($icon, $suffix, $digits), $value],
            };
            $this->MaintainVariable($ident, $this->Translate($name), $type, $presentation, $position, true);
            if ($this->GetValue($ident) !== $value) {
                $this->SetValue($ident, $value);
            }
        }
    }

    /**
     * Läuft die Anlage nur über die Cloud? Das System schickt die Angabe mit seinem Stand und nach jeder
     * Änderung; die Instanz merkt sie sich, damit sie schon vor dem ersten Paket die richtigen Variablen trägt.
     * @param array<string,mixed> $values
     */
    private function alphaCloudOnly(array $values = []): bool
    {
        if (is_bool($values['cloud_only'] ?? null) && $values['cloud_only'] !== $this->ReadAttributeBoolean('CloudOnly')) {
            $this->WriteAttributeBoolean('CloudOnly', $values['cloud_only']);
        }
        return $this->ReadAttributeBoolean('CloudOnly');
    }

    /**
     * Messwerte, die nur Modbus liefert: im Modus „Nur Cloud“ gibt es die Variablen nicht, sonst blieben
     * sie mit einem alten Wert stehen.
     * @param array<string,array{0:string,1:string,2:string,3:string,4:int,5:int}> $table Wert => [Ident, Name, Icon, Suffix, Stellen, Position]
     * @param array<string,mixed> $values
     */
    private function alphaModbusValues(array $table, array $values, bool $keep): void
    {
        foreach ($table as $key => [$ident, $name, $icon, $suffix, $digits, $position]) {
            $this->MaintainVariable($ident, $this->Translate($name), VARIABLETYPE_FLOAT, AlphaPresentations::value($icon, $suffix, $digits), $position, $keep);
            if ($keep && is_numeric($values[$key] ?? null)) {
                $this->SetValue($ident, (float) $values[$key]);
            }
        }
    }

    /** Zählerstände laufen nur vorwärts; 0 und Rücksprünge sind Lesefehler und würden das Archiv verfälschen. */
    private function alphaCounter(string $ident, float $value): void
    {
        if ($value > 0.0 && $value >= (float) $this->GetValue($ident)) {
            $this->SetValue($ident, $value);
            return;
        }
        // Sonst bleibt der Zähler ohne Spur stehen; so zeigt die Fehlersuche, was verworfen wurde.
        $this->SendDebug('Counter', $ident . ': ' . $value . ' rejected, kept ' . $this->GetValue($ident), 0);
    }
}

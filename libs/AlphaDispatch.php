<?php

declare(strict_types=1);

/*
 * Dispatch der Anlage: ein zeitlich begrenzter Steuerbefehl über Modbus, Register 0x0880–0x088A.
 * Belegung nach der Herstellerbeschreibung und der HA-Integration ha-alphaess-modbus (entwickelt an
 * einer SMILE-G3-T10): Start, Wirkleistung (32 Bit, Versatz 32000, kleiner = laden), Blindleistung
 * (Versatz 10000), Modus, Ziel-SOC (0,392 %/Bit), Dauer in s (32 Bit), Flussrichtung, PV-Schalter.
 * Läuft die Dauer ab, ohne dass ein neuer Befehl kommt, kehrt die Anlage in ihren Normalbetrieb zurück.
 * Reine Logik ohne Symcon.
 */
final class AlphaDispatch
{
    public const ADDRESS = 0x0880;

    /** Gewählte Betriebsarten; 0 ist der Normalbetrieb der Anlage (kein Dispatch) */
    public const AUTO = 0;
    public const NO_DISCHARGE = 1;
    public const HOLD = 2;
    public const CHARGE = 3;
    public const DISCHARGE = 4;

    public const POWER_MAX = 20000;
    public const SOC_MIN = 10;
    public const SOC_MAX = 100;

    private const POWER_OFFSET = 32000;
    private const REACTIVE_OFFSET = 10000;
    /** Dauer beim Zurücksetzen: Reste eines Befehls laufen so schnell aus */
    private const RESET_SECONDS = 90;

    public static function valid(int $mode): bool
    {
        return $mode >= self::AUTO && $mode <= self::DISCHARGE;
    }

    /**
     * Die elf Registerwerte eines Befehls.
     * @return list<int>
     */
    public static function registers(int $mode, int $power, int $soc, int $seconds): array
    {
        $power = max(0, min(self::POWER_MAX, $power));
        $soc = max(self::SOC_MIN, min(self::SOC_MAX, $soc));
        [$start, $dispatchMode, $active, $target, $seconds] = match ($mode) {
            // Laden nur aus PV, kein Entladen, kein Netzladen
            self::NO_DISCHARGE => [1, 1, self::POWER_OFFSET, 0, $seconds],
            // Lastfolge mit Leistung 0: weder laden noch entladen
            self::HOLD         => [1, 3, self::POWER_OFFSET, 0, $seconds],
            // Ladestand regeln: mit der Leistung bis zum Ziel laden bzw. entladen
            self::CHARGE       => [1, 2, self::POWER_OFFSET - $power, (int) ($soc / 0.392), $seconds],
            self::DISCHARGE    => [1, 2, self::POWER_OFFSET + $power, (int) ($soc / 0.392), $seconds],
            default            => [0, 0, self::POWER_OFFSET, 0, self::RESET_SECONDS],
        };
        return [$start, $active >> 16, $active & 0xFFFF, 0, self::REACTIVE_OFFSET, $dispatchMode, min(255, $target),
            ($seconds >> 16) & 0xFFFF, $seconds & 0xFFFF, 255, 0];
    }
}

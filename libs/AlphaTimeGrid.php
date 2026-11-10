<?php

declare(strict_types=1);

/*
 * Uhrzeiten der Lade- und Entladefenster. Die Cloud kennt „HH:mm“ im 15-Minuten-Raster,
 * die Variable (Darstellung Datum/Uhrzeit) hält einen Zeitstempel. Damit die Anzeige nicht
 * mit der Sommerzeit wandert, liegt jede Uhrzeit auf demselben festen Wintertag.
 */
final class AlphaTimeGrid
{
    private const STEP_MINUTES = 15;
    private const LAST_SLOT = 1425; // 23:45

    public static function valid(string $clock): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $clock) === 1;
    }

    /** Rundet auf das Raster; 23:53 wird 23:45, nicht 00:00. */
    public static function snap(string $clock): string
    {
        if (!self::valid($clock)) {
            return '00:00';
        }
        $minutes = (int) substr($clock, 0, 2) * 60 + (int) substr($clock, 3, 2);
        $minutes = min(self::LAST_SLOT, (int) round($minutes / self::STEP_MINUTES) * self::STEP_MINUTES);
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function toTimestamp(string $clock): int
    {
        $clock = self::snap($clock);
        return (int) mktime((int) substr($clock, 0, 2), (int) substr($clock, 3, 2), 0, 1, 1, 2000);
    }

    /** Uhrzeit eines beliebigen Zeitstempels (z. B. aus der Visu), aufs Raster gerundet. */
    public static function toClock(int $timestamp): string
    {
        return self::snap(date('H:i', $timestamp));
    }
}

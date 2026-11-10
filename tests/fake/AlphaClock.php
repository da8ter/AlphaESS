<?php

declare(strict_types=1);

// Uhr des Prüfstands: folgt dem Kern (Kernel::advance), damit Timer, Abstände und Drosselung zusammenpassen.
final class AlphaClock
{
    public static function now(): int
    {
        return Kernel::now();
    }
}

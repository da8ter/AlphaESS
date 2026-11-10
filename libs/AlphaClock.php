<?php

declare(strict_types=1);

// Einzige Zeitquelle der Bibliothek. Der Prüfstand lädt vorher eine eigene Klasse mit stellbarer Uhr.
if (!class_exists('AlphaClock')) {
    final class AlphaClock
    {
        public static function now(): int
        {
            return time();
        }
    }
}

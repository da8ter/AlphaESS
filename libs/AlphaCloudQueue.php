<?php

declare(strict_types=1);

/*
 * Auftragsliste für die Cloud: wählt je Takt höchstens einen Aufruf, hält den Mindestabstand,
 * drosselt nach „zu viele Anfragen“ und bündelt Schreibaufträge. Reine Logik; der Zustand ist
 * ein Array, das der Aufrufer im Puffer der Instanz hält.
 *
 * Zustand: nextAllowed (frühester nächster Aufruf), backoff (aktuelle Drosselzeit in s),
 * last (Auftrag => letzter Lauf), force (Auftrag => frühester erzwungener Lauf),
 * pending (Schreibauftrag => args, at, settle, sent).
 */
final class AlphaCloudQueue
{
    public const SPACING = 10;
    private const BACKOFF_START = 60;
    private const BACKOFF_MAX = 600;
    private const RETRY_FORCED = 60;
    /** So lange überdeckt ein gesendeter Sollwert einen abweichenden gelesenen Stand */
    private const CONFIRM_TIMEOUT = 120;

    /** @return array<string,mixed> */
    public static function fresh(): array
    {
        return ['nextAllowed' => 0, 'backoff' => 0, 'last' => [], 'force' => [], 'pending' => []];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,int> $reads Leseauftrag => Intervall in Sekunden
     * @param list<string> $blocked Schreibaufträge, denen noch die Grundlage fehlt
     * @return string|null der jetzt fällige Auftrag
     */
    public static function next(array $state, array $reads, int $now, array $blocked = []): ?string
    {
        if ($now < (int) $state['nextAllowed']) {
            return null;
        }
        $write = null;
        foreach ($state['pending'] as $key => $p) {
            if ((int) $p['sent'] === 0 && !in_array($key, $blocked, true) && $p['at'] + $p['settle'] <= $now
                && ($write === null || $p['at'] < $state['pending'][$write]['at'])) {
                $write = $key;
            }
        }
        if ($write !== null) {
            return (string) $write;
        }
        $best = null;
        $bestDue = PHP_INT_MAX;
        foreach ($reads as $job => $interval) {
            // Ein nie gelaufener Auftrag ist sofort fällig, unabhängig von seinem Intervall.
            $due = $state['force'][$job] ?? (isset($state['last'][$job]) ? (int) $state['last'][$job] + $interval : 0);
            if ($due <= $now && $due < $bestDue) {
                $best = $job;
                $bestDue = $due;
            }
        }
        return $best;
    }

    /**
     * Bucht einen ausgeführten Aufruf.
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function done(array $state, string $job, int $now, bool $rateLimited, bool $ok = true): array
    {
        if ($rateLimited) {
            $state['backoff'] = $state['backoff'] > 0 ? min(self::BACKOFF_MAX, $state['backoff'] * 2) : self::BACKOFF_START;
            $state['nextAllowed'] = $now + $state['backoff'];
            return $state;
        }
        $state['backoff'] = 0;
        $state['nextAllowed'] = $now + self::SPACING;
        $state['last'][$job] = $now;
        if (!$ok && isset($state['force'][$job])) {
            // Ein erzwungenes Lesen (z. B. nach einem Schreiben) soll nicht an einem Fehler verloren gehen.
            $state['force'][$job] = $now + self::RETRY_FORCED;
        } else {
            unset($state['force'][$job]);
        }
        return $state;
    }

    /**
     * Merkt einen Schreibauftrag vor; ein vorhandener gleichen Namens wird ergänzt und neu gestartet.
     * @param array<string,mixed> $state
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function enqueue(array $state, string $key, array $args, int $now, int $settle): array
    {
        $old = $state['pending'][$key]['args'] ?? [];
        $state['pending'][$key] = ['args' => array_merge($old, $args), 'at' => $now, 'settle' => $settle, 'sent' => 0];
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function sent(array $state, string $key, int $now): array
    {
        if (isset($state['pending'][$key])) {
            $state['pending'][$key]['sent'] = $now;
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function drop(array $state, string $key): array
    {
        unset($state['pending'][$key]);
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function force(array $state, string $job, int $at): array
    {
        $state['force'][$job] = $at;
        return $state;
    }

    /**
     * Legt einen ausstehenden Sollwert über den gelesenen Stand, bis die Cloud ihn bestätigt.
     * @param array<string,mixed> $state
     * @param array<string,mixed> $read gelesene Werte
     * @return array{0:array<string,mixed>,1:array<string,mixed>} [anzuzeigende Werte, neuer Zustand]
     */
    public static function overlay(array $state, string $key, array $read, int $now): array
    {
        $p = $state['pending'][$key] ?? null;
        if ($p === null) {
            return [$read, $state];
        }
        $confirmed = (int) $p['sent'] > 0 && array_intersect_key($read, $p['args']) == $p['args'];
        $expired = (int) $p['sent'] > 0 && $now - (int) $p['sent'] > self::CONFIRM_TIMEOUT;
        if ($confirmed || $expired) {
            unset($state['pending'][$key]);
            return [$read, $state];
        }
        return [array_merge($read, $p['args']), $state];
    }
}

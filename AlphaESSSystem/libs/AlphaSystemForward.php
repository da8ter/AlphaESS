<?php

declare(strict_types=1);

// Befehle der Geräteinstanzen. Cloud-Befehle werden nur eingereiht; ausgeführt werden sie im Takt.
trait AlphaSystemForward
{
    private const SETTLE_CONFIG = 20;
    private const SETTLE_CURRENT = 3;
    private const CURRENT_MIN = 6;
    private const CURRENT_MAX = 32;

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        try {
            return (string) json_encode(['ok' => true] + $this->handleAction(is_array($data) ? $data : []));
        } catch (\Throwable $e) {
            return (string) json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function handleAction(array $data): array
    {
        $now = AlphaClock::now();
        switch ((string) ($data['Action'] ?? '')) {
            case 'GetState':
                return ['values' => $this->cached((string) ($data['Kind'] ?? ''), (string) ($data['Serial'] ?? '')) + ['cloud_only' => $this->ReadPropertyBoolean('CloudOnly')]];

            case 'Dispatch':
                return $this->dispatchCommand($data);

            case 'ListDevices':
                return ['wallboxes' => $this->wallboxes(), 'cloud' => $this->cloudConfigured()];

            case 'WallboxControl':
                $serial = $this->requireCloud((string) ($data['Serial'] ?? ''));
                $start = !empty($data['Start']);
                $this->cloudQueue(static fn(array $s): array => [AlphaCloudQueue::enqueue($s, 'set_control:' . $serial, ['start' => $start], $now, 0), null]);
                return [];

            case 'WallboxCurrent':
                $this->requireCloud('-');
                $amps = max(self::CURRENT_MIN, min(self::CURRENT_MAX, (int) ($data['Amps'] ?? 0)));
                $this->cloudQueue(static fn(array $s): array => [AlphaCloudQueue::enqueue($s, 'set_current', ['amps' => $amps], $now, self::SETTLE_CURRENT), null]);
                return ['amps' => $amps];

            case 'BatteryConfig':
                $this->requireCloud('-');
                $groups = [];
                foreach ((array) ($data['Values'] ?? []) as $key => $value) {
                    $which = AlphaCloudApi::configOf((string) $key);
                    if ($which === '') {
                        throw new \InvalidArgumentException('Unknown setting ' . $key);
                    }
                    $groups[$which][$key] = AlphaCloudApi::normalize((string) $key, $value);
                }
                $this->cloudQueue(static function (array $s) use ($groups, $now): array {
                    foreach ($groups as $which => $fields) {
                        $s = AlphaCloudQueue::enqueue($s, 'set_' . $which, $fields, $now, self::SETTLE_CONFIG);
                    }
                    return [$s, null];
                });
                return ['values' => array_merge(...array_values($groups ?: [[]]))];
        }
        throw new \InvalidArgumentException('Unknown action');
    }

    private function requireCloud(string $serial): string
    {
        if (!$this->cloudConfigured()) {
            throw new \RuntimeException($this->Translate('The Alpha ESS cloud access is not set up'));
        }
        if ($serial === '') {
            throw new \RuntimeException($this->Translate('The serial number of the wallbox is missing'));
        }
        return $serial;
    }
}

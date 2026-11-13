<?php

declare(strict_types=1);

/*
 * Cloud-Teil des Systems: ein fester Takt führt höchstens einen Aufruf der Open API aus.
 * Die Auftragsliste (AlphaCloudQueue) liegt im Puffer; Befehle der Geräte reihen nur ein.
 */
trait AlphaSystemCloud
{
    private const CLOUD_TIMEOUT = 8;
    private const CLOUD_LIST_INTERVAL = 3600;
    /** Wartezeit, bevor nach einem Schreiben zurückgelesen wird */
    private const CLOUD_READBACK = 15;

    private function cloudConfigured(): bool
    {
        return trim($this->ReadPropertyString('AppID')) !== '' && trim($this->ReadPropertyString('AppSecret')) !== ''
            && trim($this->ReadPropertyString('SystemSN')) !== '';
    }

    /** @return list<array{sn:string,model:string}> */
    private function wallboxes(): array
    {
        $list = json_decode($this->ReadAttributeString('Wallboxes'), true);
        return is_array($list) ? array_values($list) : [];
    }

    /** @return array<string,int> Leseauftrag => Intervall in Sekunden */
    private function cloudReads(): array
    {
        $fast = max(20, $this->ReadPropertyInteger('CloudInterval'));
        $slow = max(60, $this->ReadPropertyInteger('CloudConfigInterval'));
        // Summen, Tageswerte und Stammdaten ändern sich langsam: im Takt der Konfiguration
        $reads = ['evlist' => self::CLOUD_LIST_INTERVAL, 'charge' => $slow, 'discharge' => $slow, 'sum' => $slow, 'today' => $slow, 'ess' => $slow];
        if ($this->ReadPropertyBoolean('CloudOnly')) {
            $reads['power'] = $fast; // ohne Modbus die einzige Quelle für Leistung und Ladestand
        }
        $boxes = $this->wallboxes();
        if ($boxes !== []) {
            $reads['power'] = $fast;
            $reads['evcurrent'] = $slow;
            foreach ($boxes as $box) {
                $reads['evstatus:' . $box['sn']] = $fast;
            }
        }
        return $reads;
    }

    /**
     * Ändert die Auftragsliste unter einer Sperre, weil Takt und Gerätebefehle parallel laufen.
     * @param callable(array<string,mixed>):array{0:array<string,mixed>,1:mixed} $change
     */
    private function cloudQueue(callable $change): mixed
    {
        $lock = 'AESS_' . $this->InstanceID . '_Queue';
        if (!IPS_SemaphoreEnter($lock, 2000)) {
            throw new \RuntimeException('Cloud queue is busy');
        }
        try {
            $state = json_decode($this->GetBuffer('CloudQueue'), true);
            [$state, $result] = $change(is_array($state) ? $state : AlphaCloudQueue::fresh());
            $this->SetBuffer('CloudQueue', (string) json_encode($state));
            return $result;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function cloudTick(): void
    {
        if (!$this->cloudConfigured()) {
            return;
        }
        $lock = 'AESS_' . $this->InstanceID . '_Tick';
        if (!IPS_SemaphoreEnter($lock, 1)) {
            return; // der vorige Aufruf läuft noch
        }
        try {
            $this->cloudTickLocked();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function cloudTickLocked(): void
    {
        $now = AlphaClock::now();
        $picked = $this->cloudQueue(function (array $state) use ($now): array {
            // Eine Konfiguration wird nur auf Basis eines gelesenen Stands geschrieben.
            $blocked = [];
            foreach (['charge', 'discharge'] as $which) {
                if (isset($state['pending']['set_' . $which]) && $this->GetBuffer('Cfg_' . $which) === '') {
                    $blocked[] = 'set_' . $which;
                    $state['force'][$which] ??= 0;
                }
            }
            $job = AlphaCloudQueue::next($state, $this->cloudReads(), $now, $blocked);
            if ($job === null) {
                return [$state, null];
            }
            $state['nextAllowed'] = $now + AlphaCloudQueue::SPACING;
            $pending = $state['pending'][$job] ?? null;
            return [$state, ['job' => $job, 'args' => $pending['args'] ?? [], 'at' => $pending['at'] ?? 0]];
        });
        if ($picked === null) {
            return;
        }

        $job = (string) $picked['job'];
        [$name, $serial] = explode(':', $job, 2) + [1 => ''];
        $isWrite = str_starts_with($name, 'set_');
        $args = $picked['args'];
        if ($name === 'today') {
            $args = ['date' => date('Y-m-d', $now)];
        }
        if ($name === 'set_charge' || $name === 'set_discharge') {
            $base = json_decode($this->GetBuffer('Cfg_' . substr($name, 4)), true);
            $args = array_merge(is_array($base) ? $base : [], $args);
        }

        $request = AlphaCloudApi::build($job, trim($this->ReadPropertyString('SystemSN')), $args);
        if ($request === null) {
            return;
        }
        $headers = AlphaCloudApi::headers(trim($this->ReadPropertyString('AppID')), trim($this->ReadPropertyString('AppSecret')), $now);
        $http = AlphaHttpTransport::send($request['method'], $request['url'], $headers, $request['body'], self::CLOUD_TIMEOUT);
        $result = AlphaCloudApi::parse($http['status'], $http['body'], $http['error']);
        $this->SendDebug('Cloud', $name . ': ' . ($result['ok'] ? 'ok' : $result['code'] . ' ' . $result['error']), 0);

        $limited = $result['code'] === AlphaCloudApi::RATE_LIMITED;
        $readback = match ($name) {
            'set_control' => 'evstatus:' . $serial,
            'set_current' => 'evcurrent',
            default       => substr($name, 4),
        };
        $this->cloudQueue(function (array $state) use ($job, $name, $now, $limited, $isWrite, $result, $picked, $readback): array {
            $state = AlphaCloudQueue::done($state, $job, $now, $limited, $result['ok']);
            // Wurde der Auftrag während des Aufrufs geändert, bleibt er für den nächsten Lauf stehen.
            if ($isWrite && !$limited && ($state['pending'][$job]['at'] ?? -1) === $picked['at']) {
                $state = $result['ok'] && $name !== 'set_control' ? AlphaCloudQueue::sent($state, $job, $now) : AlphaCloudQueue::drop($state, $job);
                $state = AlphaCloudQueue::force($state, $readback, $result['ok'] ? $now + self::CLOUD_READBACK : $now);
            }
            return [$state, null];
        });

        $this->cloudReport($result);
        if ($result['ok'] && !$isWrite) {
            $this->cloudApply($name, $serial, $result['data'], $now);
        } elseif ($isWrite && !$result['ok'] && !$limited) {
            $this->LogMessage($this->Translate('The Alpha ESS cloud rejected the change') . ': ' . $this->Translate($result['error']), KL_WARNING);
        }
    }

    /** @param array{ok:bool,code:int,error:string,data:mixed} $result */
    private function cloudReport(array $result): void
    {
        $before = $this->GetValue('CloudState');
        if ($result['ok']) {
            $this->setState('CloudState', 1);
        } elseif ($result['code'] === AlphaCloudApi::RATE_LIMITED) {
            $this->setState('CloudState', 4);
        } elseif (AlphaCloudApi::isAuthError($result['code'])) {
            $this->setState('CloudState', 2);
            if ($before !== 2) {
                $this->LogMessage($this->Translate('Alpha ESS cloud') . ': ' . $this->Translate($result['error']), KL_ERROR);
            }
        } elseif ($result['code'] === 0 || $result['code'] >= 500 && $result['code'] < 600 || in_array($result['code'], [6026, 6042], true)) {
            $this->setState('CloudState', 3);
        }
        // Übrige Codes betreffen nur den einen Aufruf (z. B. keine Daten) und ändern den Zustand nicht.
    }

    private function cloudApply(string $name, string $serial, mixed $data, int $now): void
    {
        switch ($name) {
            case 'evlist':
                $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];
                $boxes = [];
                foreach ($rows as $row) {
                    $sn = is_array($row) ? trim((string) ($row['evchargerSn'] ?? '')) : '';
                    if ($sn !== '') {
                        $boxes[] = ['sn' => $sn, 'model' => (string) ($row['evchargerModel'] ?? '')];
                    }
                }
                $json = (string) json_encode($boxes);
                if ($json !== $this->ReadAttributeString('Wallboxes')) {
                    $this->WriteAttributeString('Wallboxes', $json);
                }
                break;
            case 'evstatus':
                // Das Handbuch nennt data eine Liste; ein einzelnes Objekt wird ebenso angenommen.
                $row = is_array($data) && array_is_list($data) ? ($data[0] ?? null) : $data;
                if (is_array($row) && is_numeric($row['evchargerStatus'] ?? null)) {
                    $this->publish(AlphaDataFlow::KIND_WALLBOX, $serial, 'cloud', ['status' => (int) $row['evchargerStatus']]);
                }
                break;
            case 'evcurrent':
                $amps = is_array($data) ? ($data['currentsetting'] ?? null) : $data;
                if (is_numeric($amps)) {
                    $shown = $this->cloudQueue(static function (array $state) use ($amps, $now): array {
                        [$values, $state] = AlphaCloudQueue::overlay($state, 'set_current', ['amps' => (int) $amps], $now);
                        return [$state, $values];
                    });
                    foreach ($this->wallboxes() as $box) {
                        $this->publish(AlphaDataFlow::KIND_WALLBOX, $box['sn'], 'cloud', ['current' => (int) $shown['amps']]);
                    }
                }
                break;
            case 'power':
                if ($this->ReadPropertyBoolean('CloudOnly')) {
                    $parts = AlphaRegisters::split(AlphaCloudApi::powerValues($data));
                    $this->publish(AlphaDataFlow::KIND_INVERTER, '', 'cloud', $parts['inverter']);
                    $this->publish(AlphaDataFlow::KIND_BATTERY, '', 'cloud', $parts['battery']);
                }
                $boxes = $this->wallboxes();
                foreach ($boxes as $i => $box) {
                    $power = is_array($data) ? ($data['pevDetail']['ev' . ($i + 1) . 'Power'] ?? (count($boxes) === 1 ? ($data['pev'] ?? null) : null)) : null;
                    if (is_numeric($power)) {
                        $this->publish(AlphaDataFlow::KIND_WALLBOX, $box['sn'], 'cloud', ['power' => (float) $power]);
                    }
                }
                break;
            case 'sum':
                $sum = AlphaCloudApi::sumValues($data, $this->ReadPropertyBoolean('CloudOnly'));
                $this->publish(AlphaDataFlow::KIND_INVERTER, '', 'cloud', $sum['inverter']);
                $this->publish(AlphaDataFlow::KIND_BATTERY, '', 'cloud', $sum['battery']);
                $this->systemInfo($sum['system']);
                break;
            case 'today':
                $today = AlphaCloudApi::todayValues($data);
                $this->publish(AlphaDataFlow::KIND_INVERTER, '', 'cloud', $today['inverter']);
                $this->publish(AlphaDataFlow::KIND_BATTERY, '', 'cloud', $today['battery']);
                // Die Cloud zählt alle Wallboxen zusammen: zuordnen lässt es sich nur bei einer
                $boxes = $this->wallboxes();
                if (count($boxes) === 1) {
                    $this->publish(AlphaDataFlow::KIND_WALLBOX, $boxes[0]['sn'], 'cloud', $today['wallbox']);
                }
                break;
            case 'ess':
                $ess = AlphaCloudApi::essValues($data, trim($this->ReadPropertyString('SystemSN')));
                $this->publish(AlphaDataFlow::KIND_BATTERY, '', 'cloud', $ess['battery']);
                $this->systemInfo($ess['system']);
                break;
            case 'charge':
            case 'discharge':
                $config = AlphaCloudApi::fromCloud($name, $data);
                if ($config === []) {
                    $this->SendDebug('Cloud', $name . ': configuration incomplete', 0);
                    break;
                }
                $this->SetBuffer('Cfg_' . $name, (string) json_encode($config));
                $shown = $this->cloudQueue(static function (array $state) use ($name, $config, $now): array {
                    [$values, $state] = AlphaCloudQueue::overlay($state, 'set_' . $name, $config, $now);
                    return [$state, $values];
                });
                $this->publish(AlphaDataFlow::KIND_BATTERY, '', 'cloud', $shown);
                break;
        }
    }
}

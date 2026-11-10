<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AlphaDataFlow.php';
require_once __DIR__ . '/../libs/AlphaDevice.php';

// Legt die Geräteinstanzen einer Anlage an: Wechselrichter, Speicher und die Wallboxen, die das System kennt.
class AlphaESSConfigurator extends IPSModuleStrict
{
    use AlphaDevice;

    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm(): string
    {
        $system = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $rows = [];
        $list = [];
        if ($system > 0) {
            $rows[] = $this->row($system, AlphaDataFlow::MODULE_INVERTER, $this->Translate('Inverter'), '');
            $rows[] = $this->row($system, AlphaDataFlow::MODULE_BATTERY, $this->Translate('Battery'), '');
            $list = $this->alphaSend('ListDevices');
            foreach (is_array($list['wallboxes'] ?? null) ? $list['wallboxes'] : [] as $box) {
                $serial = (string) ($box['sn'] ?? '');
                if ($serial !== '') {
                    $rows[] = $this->row($system, AlphaDataFlow::MODULE_WALLBOX, trim($this->Translate('Wallbox') . ' ' . ($box['model'] ?? '')), $serial);
                }
            }
        }

        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $form['actions'][0]['values'] = $rows;
        $form['elements'][0]['visible'] = $system === 0;
        $form['elements'][1]['visible'] = $system > 0 && empty($list['cloud']);
        return (string) json_encode($form);
    }

    /** @return array<string,mixed> */
    private function row(int $system, string $moduleId, string $name, string $serial): array
    {
        return [
            'name'       => $name,
            'serial'     => $serial,
            'instanceID' => $this->existing($system, $moduleId, $serial),
            'create'     => [
                'moduleID'      => $moduleId,
                'name'          => 'Alpha ESS ' . $name,
                'configuration' => $serial === '' ? new \stdClass() : ['Serial' => $serial],
            ],
        ];
    }

    private function existing(int $system, string $moduleId, string $serial): int
    {
        foreach (IPS_GetInstanceListByModuleID($moduleId) as $id) {
            if ((int) IPS_GetInstance($id)['ConnectionID'] !== $system) {
                continue;
            }
            if ($serial === '' || trim((string) @IPS_GetProperty($id, 'Serial')) === $serial) {
                return $id;
            }
        }
        return 0;
    }
}

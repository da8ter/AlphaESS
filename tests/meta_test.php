<?php

declare(strict_types=1);

// Statische Prüfungen der Bibliothek: Datenfluss in module.json, Übersetzungen, Dateilängen, keine Interna.
require __DIR__ . '/bootstrap.php';

$lib = dirname(__DIR__);
const MODBUS_TX = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
const MODBUS_CHILD = '{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}';
const DEVICE_TX = '{D47CC3DD-E236-42BE-A84E-5C7D88033D0A}';
const DEVICE_RX = '{F0D93D9B-AD5D-4D1D-A5FA-BDDEFA7E1860}';

$modules = [];
foreach (glob($lib . '/*/module.json') as $file) {
    $modules[basename(dirname($file))] = json_decode((string) file_get_contents($file), true);
}

section('module.json');
check(array_keys($modules) === ['AlphaESSBattery', 'AlphaESSConfigurator', 'AlphaESSInverter', 'AlphaESSSystem', 'AlphaESSWallbox'], 'fünf Module');
$faults = [];
foreach ($modules as $dir => $m) {
    if (str_replace(' ', '', $m['name']) !== $dir || str_contains($m['name'], '-')) {
        $faults[] = "$dir: Name passt nicht zur Klasse";
    }
    if ($m['vendor'] !== 'Alpha ESS' || $m['aliases'] !== []) {
        $faults[] = "$dir: vendor oder aliases";
    }
    if (preg_match('/class ' . $dir . ' extends IPSModuleStrict\b/', (string) file_get_contents("$lib/$dir/module.php")) !== 1) {
        $faults[] = "$dir: Klasse fehlt oder ist nicht Strict";
    }
    if (!str_starts_with((string) file_get_contents("$lib/$dir/module.php"), "<?php\n\ndeclare(strict_types=1);")) {
        $faults[] = "$dir: strict_types fehlt";
    }
}
check($faults === [], 'Name = Klasse = Ordner, Hersteller, Strict' . ($faults === [] ? '' : ': ' . implode('; ', $faults)));
check(count(array_unique(array_column($modules, 'prefix'))) === 5 && count(array_unique(array_column($modules, 'id'))) === 5, 'Präfixe und GUIDs eindeutig');
$sys = $modules['AlphaESSSystem'];
check($sys['type'] === 2 && $sys['parentRequirements'] === [MODBUS_TX] && in_array(MODBUS_CHILD, $sys['implemented'], true),
    'System hängt am ModBus Gateway: sendet dessen Paket und implementiert dessen Kind-Schnittstelle');
check($sys['childRequirements'] === [DEVICE_RX] && in_array(DEVICE_TX, $sys['implemented'], true), 'System nimmt Gerätebefehle an und verlangt die Geräteschnittstelle');
$faults = [];
foreach (array_diff_key($modules, ['AlphaESSSystem' => 1]) as $dir => $m) {
    // gemessen: Verbinden klappt nur, wenn das Kind die childRequirements des Elters implementiert
    if ($m['parentRequirements'] !== [DEVICE_TX] || $m['implemented'] !== [DEVICE_RX] || $m['childRequirements'] !== []) {
        $faults[] = $dir;
    }
}
check($faults === [], 'Geräte und Konfigurator passen zum System' . implode(',', $faults));
check($modules['AlphaESSConfigurator']['type'] === 4 && $modules['AlphaESSInverter']['type'] === 3, 'Modultypen');
$consts = (string) file_get_contents($lib . '/libs/AlphaDataFlow.php');
$missing = array_filter(array_merge(array_column($modules, 'id'), [MODBUS_TX, DEVICE_TX, DEVICE_RX]), static fn(string $g): bool => $g !== $modules['AlphaESSConfigurator']['id'] && !str_contains($consts, $g));
check($missing === [], 'die GUIDs im Code stimmen mit module.json überein');

section('Übersetzungen');
$collect = static function (mixed $node, array &$out) use (&$collect): void {
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if (in_array($key, ['caption', 'label'], true) && is_string($value) && $value !== '') {
            $out[] = $value;
        }
        $collect($value, $out);
    }
};
$faults = [];
foreach ($modules as $dir => $m) {
    $locale = json_decode((string) file_get_contents("$lib/$dir/locale.json"), true)['translations'];
    $texts = [$m['name']];
    $collect(json_decode((string) file_get_contents("$lib/$dir/form.json"), true), $texts);
    $sources = array_merge(["$lib/$dir/module.php", "$lib/libs/AlphaDevice.php"], glob("$lib/$dir/libs/*.php"));
    foreach ($sources as $file) {
        if ($dir === 'AlphaESSSystem' && str_ends_with($file, 'AlphaDevice.php')) {
            continue;
        }
        $code = (string) file_get_contents($file);
        preg_match_all("/Translate\('((?:[^'\\\\]|\\\\.)*)'\)/", $code, $direct);
        preg_match_all("/=> \['[A-Za-z0-9]+', '([^']+)'/", $code, $tables); // Namen in den Variablentabellen
        $texts = array_merge($texts, $direct[1], $tables[1]);
    }
    if ($dir === 'AlphaESSSystem') {
        preg_match_all("/\d{4} => '([^']+)'/", (string) file_get_contents("$lib/libs/AlphaCloudApi.php"), $errors);
        $texts = array_merge($texts, $errors[1], ['No connection']);
    }
    foreach (array_unique($texts) as $text) {
        if (($locale['de'][$text] ?? '') === '' || !isset($locale['en'][$text])) {
            $faults[] = "$dir: $text";
        }
    }
    if (array_keys($locale['de']) !== array_keys($locale['en'])) {
        $faults[] = "$dir: de und en haben verschiedene Schlüssel";
    }
}
check($faults === [], 'jeder Text aus Code und Formular ist in de und en übersetzt' . ($faults === [] ? '' : ":\n  " . implode("\n  ", $faults)));
$texts = implode("\n", array_map(static fn(string $f): string => (string) file_get_contents($f), glob($lib . '/*/{locale,form}.json', GLOB_BRACE)));
check(!str_contains($texts, 'IP-Symcon'), 'Texte sagen nicht „IP-Symcon“');

section('Dateien');
$files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, FilesystemIterator::SKIP_DOTS)), '/\.(php|json|sh)$/');
$long = [];
$internal = [];
foreach ($files as $file) {
    $path = (string) $file;
    if (str_contains($path, '/.git/')) {
        continue;
    }
    $content = (string) file_get_contents($path);
    if (substr_count($content, "\n") > 500) {
        $long[] = basename($path);
    }
    // Öffentliches Repo: keine Adressen, Benutzerpfade oder echten Seriennummern
    if (preg_match('/\b(?!0\.0\.0\.0\b)\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b|\/Users\/|\/home\/[a-z]/', $content) === 1) {
        $internal[] = basename($path);
    }
}
check($long === [], 'keine Datei über 500 Zeilen' . implode(',', $long));
check($internal === [], 'keine IP-Adressen oder Benutzerpfade im Repo' . implode(',', $internal));
$icons = [];
foreach (glob($lib . '/{*,*/libs}/*.php', GLOB_BRACE) as $file) {
    preg_match_all("/(?:value|state|toggle|slider)\('([^']+)'/", (string) file_get_contents($file), $m);
    preg_match_all("/'[A-Za-z ()0-9-]+', '([a-z-]+)', ' ?[A-Za-z%]+'/", (string) file_get_contents($file), $t);
    $icons = array_merge($icons, $m[1], $t[1]);
}
$icons = array_unique($icons);
check($icons !== [] && array_filter($icons, static fn(string $i): bool => preg_match('/^[a-z]+(-[a-z]+)*$/', $i) !== 1) === [], 'Icons sind Font-Awesome-Namen in Kleinschreibung: ' . implode(' ', $icons));

// Jedes Modul lädt in Symcon nur, was es selbst per require_once erreicht (der Prüfstand lädt alles vorab).
$missing = [];
foreach (glob($lib . '/*/module.php') as $module) {
    $seen = [];
    $queue = [realpath($module)];
    while ($queue !== []) {
        $file = array_shift($queue);
        if (isset($seen[$file])) {
            continue;
        }
        $seen[$file] = true;
        preg_match_all("#require_once __DIR__ \. '([^']+)'#", (string) file_get_contents($file), $r);
        foreach ($r[1] as $rel) {
            $queue[] = (string) realpath(dirname($file) . $rel);
        }
    }
    $loaded = array_map(static fn(string $f): string => basename($f, '.php'), array_keys($seen));
    foreach (array_keys($seen) as $file) {
        preg_match_all('/\b(?:new |use )?(Alpha[A-Z]\w+)(?:::|\(|;)/', (string) file_get_contents($file), $c);
        foreach (array_unique($c[1]) as $class) {
            $known = glob($lib . '/{libs,*/libs}/' . $class . '.php', GLOB_BRACE) !== [];
            if ($known && !in_array($class, $loaded, true)) {
                $missing[] = basename(dirname($module)) . ': ' . $class;
            }
        }
    }
}
check($missing === [], 'jedes Modul bindet die Klassen ein, die es nutzt: ' . implode(', ', array_unique($missing)));

done();

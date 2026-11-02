<?php

declare(strict_types=1);

/**
 * Validate translation YAML syntax and key parity across required locales (REQ-I18N-002 / REQ-MAKE-004).
 */
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$requiredLocales = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl'];
$domain          = 'NowoStorageKitBundle';
$dir             = dirname(__DIR__) . '/src/Resources/translations';

/**
 * @param array<mixed> $tree
 *
 * @return list<string>
 */
$flatten = static function (array $tree, string $prefix = '') use (&$flatten): array {
    $keys = [];
    foreach ($tree as $key => $value) {
        $id = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            array_push($keys, ...$flatten($value, $id));
        } else {
            $keys[] = $id;
        }
    }

    return $keys;
};

$catalogues = [];
foreach ($requiredLocales as $locale) {
    $file = sprintf('%s/%s.%s.yaml', $dir, $domain, $locale);
    if (!is_file($file)) {
        fwrite(STDERR, "Missing catalogue: {$file}\n");
        exit(1);
    }
    $parsed = Yaml::parseFile($file);
    if (!is_array($parsed)) {
        fwrite(STDERR, "Invalid YAML (not a map): {$file}\n");
        exit(1);
    }
    // Leaf keys in dot notation: parity is checked per message, not only per top-level node.
    $catalogues[$locale] = $flatten($parsed);
    sort($catalogues[$locale]);
}

$reference = $catalogues['en'];
$ok        = true;
foreach ($requiredLocales as $locale) {
    if ($locale === 'en') {
        continue;
    }
    $missing = array_diff($reference, $catalogues[$locale]);
    $extra   = array_diff($catalogues[$locale], $reference);
    if ($missing !== [] || $extra !== []) {
        $ok = false;
        fwrite(STDERR, "Key parity failed for locale {$locale}\n");
        if ($missing !== []) {
            fwrite(STDERR, '  missing: ' . implode(', ', $missing) . "\n");
        }
        if ($extra !== []) {
            fwrite(STDERR, '  extra: ' . implode(', ', $extra) . "\n");
        }
    }
}

if (!$ok) {
    exit(1);
}

fwrite(STDOUT, "validate-translations: OK (" . implode(',', $requiredLocales) . ")\n");

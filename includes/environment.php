<?php
/** Load local settings without executing a PHP configuration file. Process environment wins. */
function loadAppEnvironment(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!preg_match('/\A(CONDO_[A-Z0-9_]+)\s*=\s*(.*)\z/', $line, $matches)) continue;
        if (getenv($matches[1]) !== false) continue;
        $value = trim($matches[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        putenv($matches[1] . '=' . $value);
    }
}

loadAppEnvironment(getenv('CONDO_ENV_FILE') ?: dirname(__DIR__) . '/.env');

function appSetting(string $name, string $localDefault = ''): string {
    $value = getenv($name);
    return $value === false ? $localDefault : trim($value);
}

function appIsProduction(): bool {
    return appSetting('CONDO_APP_ENV', 'local') === 'production';
}

function appUsesSecureCookies(): bool {
    return appIsProduction() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || str_starts_with(appSetting('CONDO_APP_URL'), 'https://');
}

function schemaMutationAllowed(): bool {
    return (PHP_SAPI === 'cli' && appSetting('CONDO_MIGRATION_MODE') === '1')
        || (!appIsProduction() && appSetting('CONDO_AUTO_MIGRATE', '1') !== '0');
}

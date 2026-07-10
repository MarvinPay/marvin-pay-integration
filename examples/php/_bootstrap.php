<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the vanilla-PHP examples.
 *
 * Prefers the Composer autoloader if the SDK has been installed; otherwise
 * requires the SDK source files directly (no Composer needed).
 */

$autoload = __DIR__ . '/../../sdks/php/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require __DIR__ . '/../../sdks/php/src/MarvinPayException.php';
    require __DIR__ . '/../../sdks/php/src/WebhookVerifier.php';
    require __DIR__ . '/../../sdks/php/src/MarvinPayClient.php';
}

/**
 * Read config from the environment with sensible fallbacks.
 */
function marvin_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

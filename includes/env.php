<?php

/**
 * Central environment loader.
 *
 * Parses the project `.env` file once and exposes values through env().
 * Real environment variables always win over values from the `.env` file,
 * which is the behaviour required on platforms like Railway/Render.
 */

function load_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envFile = __DIR__ . '/../.env';
    if (!is_file($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if (getenv($name) === false) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

/**
 * Read a configuration value from the environment.
 *
 * @param string $name
 * @param mixed $default
 * @return mixed
 */
function env(string $name, $default = null)
{
    load_env();
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    return $value;
}

/**
 * Read an environment boolean (accepts true/false/1/0/yes/no/on/off).
 *
 * @param string $name
 * @param bool $default
 * @return bool
 */
function env_bool(string $name, bool $default = false): bool
{
    $value = env($name, null);
    if ($value === null) {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

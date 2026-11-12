<?php
/**
 * Secret loader. Real passwords and API keys must NEVER be written into source files.
 *
 * secret('NAME', 'default') looks in this order:
 *   1. a real environment variable        (Hostinger: hPanel > Advanced > PHP Configuration, or SetEnv in .htaccess)
 *   2. config/secrets.php                 (an untracked file you create on each server; see secrets.example.php)
 *   3. the default you pass in
 */
if (!function_exists('secret')) {
    function secret(string $name, string $default = ''): string
    {
        static $file = null;

        $env = getenv($name);
        if ($env !== false && $env !== '') {
            return $env;
        }

        if ($file === null) {
            $path = __DIR__ . '/secrets.php';
            $loaded = is_file($path) ? (require $path) : [];
            $file = is_array($loaded) ? $loaded : [];
        }
        if (isset($file[$name]) && $file[$name] !== '') {
            return (string)$file[$name];
        }
        return $default;
    }
}

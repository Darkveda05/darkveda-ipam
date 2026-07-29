<?php
declare(strict_types=1);

namespace DarkVeda;

final class App
{
    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config === null) {
            $dir  = dirname(__DIR__) . '/config';
            // config/config.php holds credentials and is deliberately not shipped
            // (git-ignored, absent from the Docker image). When it is missing, fall
            // back to the template — every value in it reads an environment
            // variable first, which is how the container is configured.
            $file = is_file($dir . '/config.php')
                ? $dir . '/config.php'
                : $dir . '/config.example.php';

            if (!is_file($file)) {
                http_response_code(500);
                exit('DarkVeda IPAM: no configuration found. Copy config/config.example.php '
                   . 'to config/config.php, or supply DB_HOST/DB_NAME/DB_USER/DB_PASS as environment variables.');
            }
            self::$config = require $file;
        }
        return self::$config;
    }

    public static function boot(): void
    {
        $cfg = self::config();
        date_default_timezone_set($cfg['app']['timezone']);

        if ($cfg['app']['debug']) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
        }

        session_name($cfg['security']['session_name']);
        session_set_cookie_params([
            'lifetime' => $cfg['security']['session_lifetime'],
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }

    /**
     * Render a page inside the main layout.
     *
     * The paths are held in names prefixed with __dv so that a page defining
     * a common variable (e.g. $base or $page) cannot clobber the renderer's
     * own state and break the includes that follow it.
     */
    public static function render(string $page, array $vars = []): void
    {
        $__dvBase = dirname(__DIR__);
        $__dvPage = $page;
        extract($vars, EXTR_SKIP);
        require $__dvBase . '/partials/header.php';
        require $__dvBase . '/pages/' . $__dvPage . '.php';
        require $__dvBase . '/partials/footer.php';
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlashes(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}

/** HTML-escape shorthand. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

<?php
declare(strict_types=1);

if (!function_exists('clinic_app_config')) {
    function clinic_app_config(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $path = __DIR__ . '/app-config.php';
        if (is_file($path)) {
            $loaded = require $path;
            $config = is_array($loaded) ? $loaded : [];
            return $config;
        }

        $config = [];
        return $config;
    }
}

if (!function_exists('clinic_config')) {
    function clinic_config(string $key, mixed $default = null): mixed
    {
        $envValue = getenv($key);
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }

        $config = clinic_app_config();
        if (array_key_exists($key, $config) && $config[$key] !== '') {
            return $config[$key];
        }

        return $default;
    }
}

if (!function_exists('clinic_is_https_request')) {
    function clinic_is_https_request(): bool
    {
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
            if (is_array($visitor) && (($visitor['scheme'] ?? '') === 'https')) {
                return true;
            }
        }

        $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            return true;
        }

        $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
        if ($https === 'on' || $https === '1') {
            return true;
        }

        return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}

if (!function_exists('clinic_client_ip')) {
    function clinic_client_ip(): string
    {
        $cloudflareIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cloudflareIp !== '' && filter_var($cloudflareIp, FILTER_VALIDATE_IP)) {
            return $cloudflareIp;
        }

        $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            $parts = array_map('trim', explode(',', $forwardedFor));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }

        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

if (!function_exists('clinic_base_url')) {
    function clinic_base_url(): string
    {
        $scheme = clinic_is_https_request() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $path = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        $path = trim($path, '/');

        return $scheme . '://' . $host . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('clinic_runtime_environment')) {
    function clinic_runtime_environment(): string
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        $host = explode(':', $host)[0];

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
            return 'local';
        }

        return 'production';
    }
}

if (!function_exists('clinic_stock_placeholder_svg')) {
    function clinic_stock_placeholder_svg(): string
    {
        return clinic_base_url() . '/default-drug.svg';
    }
}

if (!function_exists('clinic_stock_categories')) {
    function clinic_stock_categories(): array
    {
        return [
            'General Medicines',
            'Antibiotics',
            'Analgesics',
            'Antipyretics',
            'Antimalarials',
            'Antihistamines',
            'Antiseptics and Disinfectants',
            'Antihypertensives',
            'Antidiabetics',
            'Respiratory Medicines',
            'Gastrointestinal Medicines',
            'Dermatological Medicines',
            'Eye and Ear Medicines',
            'Pediatric Medicines',
            'Reproductive Health',
            'Vaccines and Immunizations',
            'IV Fluids and Injections',
            'Vitamins and Supplements',
            'Medical Consumables',
            'Emergency Medicines',
            'Other',
        ];
    }
}

if (!function_exists('clinic_stock_image_src')) {
    function clinic_stock_image_src(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return clinic_stock_placeholder_svg();
        }

        if (str_starts_with($path, 'data:') || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (is_file($absolutePath)) {
            return $path;
        }

        return clinic_stock_placeholder_svg();
    }
}

if (!function_exists('clinic_bootstrap')) {
    function clinic_bootstrap(): void
    {
        $secure = clinic_is_https_request();

        if ($secure) {
            $_SERVER['HTTPS'] = 'on';
        }

        $clientIp = clinic_client_ip();
        $_SERVER['REMOTE_ADDR'] = $clientIp;

        if (!headers_sent()) {
            header('X-Frame-Options: DENY');
            header('X-XSS-Protection: 1; mode=block');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; connect-src 'self'; upgrade-insecure-requests");

            if ($secure) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

clinic_bootstrap();

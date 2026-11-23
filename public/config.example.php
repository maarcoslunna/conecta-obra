<?php
/**
 * Configuración de ConectaObra.
 *
 * 1. Copia este archivo a `config.php` (está en .gitignore, nunca se sube).
 * 2. Rellena tus valores. Usa claves de TEST de Stripe mientras desarrollas.
 * 3. Si puedes, deja el `config.php` real fuera de la carpeta pública del hosting.
 */
return [
    // --- Base de datos (MariaDB / MySQL) ---
    'host'     => 'localhost',
    'port'     => 3306,
    'dbname'   => 'conectaobra',
    'user'     => 'usuario_bd',
    'password' => 'cambia-esto',
    'charset'  => 'utf8mb4',

    // --- Stripe (suscripciones) ---
    'stripe_secret_key'     => 'sk_test_...',
    'stripe_webhook_secret' => 'whsec_...',
    'stripe_prices' => [
        'professional_monthly' => 'price_...',
        'professional_yearly'  => 'price_...',
        'premium_monthly'      => 'price_...',
        'premium_yearly'       => 'price_...',
    ],

    // --- Panel de administración ---
    'admin_email'         => 'admin@example.com',
    // Genera el hash con:  php -r "echo password_hash('tu-contraseña', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_password_hash' => '$2y$10$...',

    // --- Correo saliente (SMTP directo por SSL) ---
    'smtp_host'     => 'smtp.example.com',
    'smtp_port'     => 465,
    'smtp_user'     => 'info@example.com',
    'smtp_password' => 'cambia-esto',
    'smtp_secure'   => 'ssl',

    // --- Solo módulo de licitaciones privadas ---
    // Nombre de la BD que ese módulo espera encontrar (evita ejecutarlo contra la BD equivocada).
    'expected_dbname' => 'conectaobra_licitaciones',
    'staging_only'    => true,
];

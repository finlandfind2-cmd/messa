<?php
declare(strict_types=1);

use Messa\Core\ConfigHelper;

return [
    'public_routes' => [
        'GET' => ['/v1/health', '/oauth/yandex/start', '/oauth/yandex/callback'],
        'POST' => [
        '/v1/auth/check_login','/v1/auth/telegram/start','/v1/auth/telegram/confirm',
        '/v1/auth/login','/v1/auth/refresh','/v1/auth/password/forgot/start',
        '/v1/auth/password/forgot/confirm','/v1/bot/telegram/webhook'
        ],
    ],
    'cors' => [
        'allowed_origins' => ConfigHelper::getArray('ALLOWED_ORIGINS', ['https://app.chatzee.ru']),
        'allowed_headers' => ConfigHelper::getArray('CORS_ALLOWED_HEADERS', ['Content-Type','Authorization','Idempotency-Key','X-Request-Id']),
        'expose_headers'  => ConfigHelper::getArray('CORS_EXPOSE_HEADERS', ['Idempotency-Key','Request-Id','X-RateLimit-Remaining','X-RateLimit-Reset','X-Cursor','Retry-After']),
        'allowed_methods' => ['GET','POST','PUT','PATCH','DELETE','OPTIONS'],
        'allow_credentials' => true,
        'max_age'         => ConfigHelper::getInt('CORS_MAX_AGE', 600),
    ],
    'rate_limiting' => [
        'default_tokens'    => ConfigHelper::getInt('RL_DEFAULT_TOKENS', 20),
        'default_window_ms' => ConfigHelper::getInt('RL_DEFAULT_WINDOW_MS', 60000),
    ],
    'jwt' => [
        'access_ttl' => ConfigHelper::getInt('JWT_ACCESS_TTL_MIN', 20) * 60,
        'refresh_ttl'=> ConfigHelper::getInt('JWT_REFRESH_TTL_DAYS', 30) * 86400,
        // реальный URL API для корректной валидации iss
        'issuer'     => ConfigHelper::getString('JWT_ISS', 'https://api.chatzee.ru'),
        // оставляем строковое aud; задайте в .env (напр., app_web или app_mobile)
        'audience'   => ConfigHelper::getString('JWT_AUD', 'app_web'),
    ]
];
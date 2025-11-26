<?php
declare(strict_types=1);

return [
    'timeout' => (int)(getenv('SERVICE_PROXY_TIMEOUT') ?: 5),
    'connect_timeout' => (int)(getenv('SERVICE_PROXY_CONNECT_TIMEOUT') ?: 2),

    // Path prefixes for routing requests to dedicated services.
    'path_map' => [
        'auth' => ['/v1/auth', '/v1/me', '/oauth/yandex'],
        'chat' => [
            '/v1/chats',
            '/v1/messages',
            '/v1/dialogs',
            '/v1/groups',
            '/v1/users',
            '/v1/contacts',
            '/v1/calls',
            '/v1/updates',
        ],
        'media' => ['/v1/media'],
        'notification' => ['/v1/bot/telegram'],
        'health' => ['/v1/health', '/v1/ready'],
    ],

    // Optional overrides for service endpoints and enable flags.
    'services' => [
        'auth' => [
            'enabled' => getenv('AUTH_SERVICE_PROXY_ENABLED') !== false ? filter_var(getenv('AUTH_SERVICE_PROXY_ENABLED'), FILTER_VALIDATE_BOOLEAN) : null,
            'base_url' => getenv('AUTH_SERVICE_URL') ?: null,
        ],
        'chat' => [
            'enabled' => getenv('CHAT_SERVICE_PROXY_ENABLED') !== false ? filter_var(getenv('CHAT_SERVICE_PROXY_ENABLED'), FILTER_VALIDATE_BOOLEAN) : null,
            'base_url' => getenv('CHAT_SERVICE_URL') ?: null,
        ],
        'media' => [
            'enabled' => getenv('MEDIA_SERVICE_PROXY_ENABLED') !== false ? filter_var(getenv('MEDIA_SERVICE_PROXY_ENABLED'), FILTER_VALIDATE_BOOLEAN) : null,
            'base_url' => getenv('MEDIA_SERVICE_URL') ?: null,
        ],
        'notification' => [
            'enabled' => getenv('NOTIFICATION_SERVICE_PROXY_ENABLED') !== false ? filter_var(getenv('NOTIFICATION_SERVICE_PROXY_ENABLED'), FILTER_VALIDATE_BOOLEAN) : null,
            'base_url' => getenv('NOTIFICATION_SERVICE_URL') ?: null,
        ],
        'health' => [
            'enabled' => getenv('HEALTH_SERVICE_PROXY_ENABLED') !== false ? filter_var(getenv('HEALTH_SERVICE_PROXY_ENABLED'), FILTER_VALIDATE_BOOLEAN) : null,
            'base_url' => getenv('HEALTH_SERVICE_URL') ?: null,
        ],
    ],
];
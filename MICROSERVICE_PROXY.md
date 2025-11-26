# Прокси-мод для микросервисов

В монолите добавлен прозрачный прокси-слой, который позволяет поэтапно выносить отдельные домены в внешние сервисы, не меняя публичный API. Проксирование управляется env‑переключателями и срабатывает по префиксам путей.

## Как это работает
- Middleware `Messa\Http\Middleware\MicroserviceProxy` выполняется до локальной авторизации и контроллеров.
- Для каждого запроса определяется сервис по префиксу пути. Если для сервиса включён флаг и задан базовый URL, запрос отправляется на внешний backend и ответ возвращается клиенту «как есть».
- Если флаги не включены или URL пустой, запрос обслуживается монолитом как раньше.
- Проксируем все заголовки (кроме `Host`) и гарантируем наличие `Request-Id`/`X-Request-Id`, чтобы искать запрос по логам в Gateway и бэкендах.

## Маршруты → сервисы (по префиксам)
- **Auth**: `/v1/auth`, `/v1/me`, `/oauth/yandex`
- **Chat**: `/v1/chats`, `/v1/messages`, `/v1/dialogs`, `/v1/groups`, `/v1/users`, `/v1/contacts`, `/v1/calls`, `/v1/updates`
- **Media**: `/v1/media`
- **Notification**: `/v1/bot/telegram`
- **Health**: `/v1/health`, `/v1/ready`

Префиксы можно менять в `config/microservices.php`, чтобы проксировать только часть маршрутов (например, `GET /v1/messages` только для чтения) или добавить новые публичные endpoints без правок кода.

## Переменные окружения
| Переменная | Назначение | По умолчанию |
| --- | --- | --- |
| `SERVICE_PROXY_ENABLED` | Глобально включает проксирование для всех сервисов | `false` |
| `SERVICE_PROXY_TIMEOUT` | Таймаут запроса к внешнему сервису, сек | `5` |
| `SERVICE_PROXY_CONNECT_TIMEOUT` | Таймаут соединения, сек | `2` |
| `AUTH_SERVICE_PROXY_ENABLED` | Включить прокси для auth | `SERVICE_PROXY_ENABLED` |
| `AUTH_SERVICE_URL` | Базовый URL Auth сервиса | пусто |
| `CHAT_SERVICE_PROXY_ENABLED` | Включить прокси для chat | `SERVICE_PROXY_ENABLED` |
| `CHAT_SERVICE_URL` | Базовый URL Chat сервиса | пусто |
| `MEDIA_SERVICE_PROXY_ENABLED` | Включить прокси для media | `SERVICE_PROXY_ENABLED` |
| `MEDIA_SERVICE_URL` | Базовый URL Media сервиса | пусто |
| `NOTIFICATION_SERVICE_PROXY_ENABLED` | Включить прокси для уведомлений | `SERVICE_PROXY_ENABLED` |
| `NOTIFICATION_SERVICE_URL` | Базовый URL Notification сервиса | пусто |
| `HEALTH_SERVICE_PROXY_ENABLED` | Включить прокси для health | `SERVICE_PROXY_ENABLED` |
| `HEALTH_SERVICE_URL` | Базовый URL Health сервиса | пусто |

### `config/microservices.php`
- `path_map` — список префиксов на сервис. Если файла нет, используются значения по умолчанию выше.
- `services.*.enabled` / `services.*.base_url` — опциональные переопределения env на уровне файла, удобно для разных окружений (stage, локалка) без экспорта переменных.
- `timeout` / `connect_timeout` — значения по умолчанию для таймаутов, если env не задан.
- Конфиг читается однократно и кешируется в памяти процесса, чтобы не делать лишние файловые операции на каждом запросе.

## Советы по использованию на VPS
- Начните с включения только Auth или Media сервиса через отдельные URL, чтобы проверить сеть и таймауты.
- Держите таймауты маленькими (2–5 сек), чтобы не блокировать PHP‑FPM воркеры на медленных upstream.
- Проксируемые ответы сохраняют заголовки `Content-Type` и статус-коды; длина/Transfer-Encoding не пробрасываются.
- В логи `var/logs/app.log` пишутся ошибки подключения к upstream с названием сервиса и путём.

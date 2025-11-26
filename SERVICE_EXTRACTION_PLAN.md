# План выделения микросервисов (первый этап)

Этот план описывает конкретные шаги по выносу текущего PHP‑монолита в самостоятельные сервисы, оптимизированные под VPS. Он дополняет общий документ `MICROSERVICE_MIGRATION.md` и задаёт первый инкремент для реализации.

## Общие принципы
- **Одноответственность сервисов**: каждый сервис владеет своими данными и схемой; общение только через публичные API или очередь.
- **Шлюз как единственная точка входа**: все внешние вызовы идут через API Gateway; внутренние сервисы находятся в приватной сети/докер-сабнете.
- **Слабая связность**: HTTP/gRPC с таймаутами и ретраями; очереди для событий; минимальный набор зависимостей на каждом сервисе для экономии ресурсов VPS.
- **Готовность к деградации**: Redis/S3/ffmpeg — опциональны, с фоллбеком на in-memory/локальный диск.

## API Gateway
- **Роль**: TLS termination, rate-limit, auth passthrough, маршрутизация `/v1/*` на backend сервисы, кэш статики/аватаров.
- **Быстрый старт**: Nginx/OpenResty конфиг с upstream-ами `auth:8080`, `chat:8080`, `media:8080`, `notification:8080`.
- **Задачи первого этапа**:
  - Вынести публичные маршруты из `public/index.php` в конфиг Gateway; пробрасывать остальные запросы обратно в монолит как временный backend.
  - Включить HTTP кэш для `GET /v1/media/*` и аватаров (`AvatarService`).
  - Добавить простые лимиты по IP/токену для `/v1/auth/*` и `/v1/messages/*`.

## Auth Service
- **Исходный код**: `src/Services/AuthAggregateService.php`, `src/Services/OtpService.php`, `src/Services/JwtService.php`, `src/Services/SessionService.php`, контроллеры `AuthPasswordController.php`, `SessionsController.php`, `MeController.php`.
- **Данные**: пользователи, сессии/refresh-токены, OTP.
- **API (первый контракт)**:
  - `POST /auth/register`, `POST /auth/login`, `POST /auth/otp/verify`, `POST /auth/refresh`, `POST /auth/logout`.
  - `GET /auth/me` возвращает профиль и набор разрешений для Gateway.
- **Интеграции**: выдаёт короткоживущий JWT (подписанный секретом сервиса Auth); чаты/медиа валидируют токен через публичный ключ JWKS или отдельный `/auth/introspect`.
- **Задачи первого этапа**:
  - Выделить бизнес-логику аутентификации в отдельный PHP/Fastify сервис, оставить совместимый HTTP слой в монолите как прокси.
  - Отделить схему пользователей/сессий в собственную БД (миграции копируют данные из монолита в read-only режиме).
  - Реализовать JWKS endpoint и короткие TTL токенов для снижения нагрузки на introspection.

## Chat Service
- **Исходный код**: контроллеры `ChatsController.php`, `MessagesController.php`, `UpdatesController.php`, доменная логика `src/Domain/Messages`, сервисы работы с чатами/месседжами в `src/Services`.
- **Данные**: чаты, сообщения, реакции, пины, метки прочтения.
- **API (первый контракт)**:
  - CRUD чатов: `POST /chats`, `GET /chats`, `GET /chats/{id}`.
  - Сообщения: `POST /chats/{id}/messages`, `GET /chats/{id}/messages`, `POST /messages/{id}/read`.
  - Обновления: перевести `/v1/updates` в SSE/WebSocket endpoint `GET /updates/stream` через Gateway.
- **Задачи первого этапа**:
  - Вынести репозитории/сервисы чатов в новый сервис, оставив монолитный HTTP слой как клиент с feature flag.
  - Отделить схему БД: копия таблиц сообщений/чатов с последующим переключением записи на новый сервис.
  - Ввести внутренний брокер (Redis Streams/RabbitMQ) для уведомлений об отправке сообщений и доставки в Notification Service.

## Media Service
- **Исходный код**: `src/Services/Media`, `AvatarService.php`, `Telemost`, `S3`, контроллер `MediaController.php`.
- **Данные**: файлы оригиналов, превью/thumbnail, аватары.
- **API (первый контракт)**:
  - `POST /media/upload` (multipart + метаданные), `GET /media/{id}` с приватными ссылками, `GET /media/avatar/{userId}` публично-кэшируемый.
  - Вебхуки для статуса конвертации: `POST /media/callback`.
- **Задачи первого этапа**:
  - Обернуть S3/локальное хранилище в сервис с фоллбеком на диск; добавить health endpoint, не падающий без S3.
  - Вынести медиаворкеры (`bin/queue_media_post.php`) в отдельный процесс/контейнер с проверкой ffmpeg и очередью задач.
  - Настроить CDN/cache правила на Gateway для аватаров и статичных медиа.

## Notification Service
- **Исходный код**: `src/Services/TelegramBot.php` и связанные очереди/вебхуки.
- **Данные**: состояние ботов, подписки на события (может храниться в Redis/БД сервиса).
- **API/протокол**:
  - Входящие события по очереди из Chat/Auth/Media.
  - Вебхуки и исходящие запросы в Telegram/e-mail/SMS.
- **Задачи первого этапа**:
  - Определить формат событий (JSON через Redis Streams/RabbitMQ) и настроить продюсер в Chat/Auth сервисах.
  - Добавить retry/дедупликацию на уровне очереди, логировать в stdout для простого мониторинга на VPS.

## Health/Monitoring Service
- **Роль**: единый `/health`/`/ready` endpoint, агрегирующий статус БД/Redis/S3 по конфигу env.
- **Задачи первого этапа**:
  - Сделать lightweight HTTP сервис (можно на PHP built-in или Go) с кэшированием проверок и отключаемыми зависимостями.
  - Интегрировать с Gateway для blue/green: Gateway пингует только этот сервис, остальные проверки идут внутренне.
  - Прометеевские метрики: RPS, latency, queue depth, ffmpeg duration (экспорт через `/metrics`).

## Минимальный compose для VPS (набросок)
- Gateway (nginx/openresty) — 50–100MB RAM.
- Auth, Chat, Media, Notification — PHP-FPM/RoadRunner или Node/Fastify, каждый 100–150MB RAM c ограничением воркеров.
- Redis (общий) — 50–100MB; PostgreSQL/MySQL на том же VPS, но с разделёнными схемами.
- Фоновый воркер Media — отдельный процесс с низким приоритетом CPU.

## Следующие шаги по реализации
- Добавить фичефлаги в монолите для прокси-запросов к Auth/Chat/Media сервисам, чтобы начать поэтапно переключать трафик.
- Подготовить миграции для разделения схем БД и скрипты начальной репликации данных.
- Настроить CI-пайплайн для отдельных сервисов: линтеры, unit-тесты, контейнерные сборки и health-check.
- Зафиксировать SLIs/SLOs по сервисам и включить базовые алёрты (5xx, таймауты RPC, рост длины очередей).

## Что уже сделано в монолите для старта
- Добавлен middleware `MicroserviceProxy`, который по префиксам (`/v1/auth`, `/v1/chats`, `/v1/media`, `/v1/bot/telegram`, `/v1/health`) умеет отправлять запросы на внешние сервисы при включённых env-флагах `*_SERVICE_PROXY_ENABLED` и заданных `*_SERVICE_URL`.
- Таймауты прокси задаются через `SERVICE_PROXY_TIMEOUT`/`SERVICE_PROXY_CONNECT_TIMEOUT`, чтобы не блокировать PHP-FPM воркеры на медленных upstream.

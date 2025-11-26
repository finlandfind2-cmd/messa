<?php
declare(strict_types=1);

namespace Messa\Core;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

/**
 * Обёртка над AWS S3 (совместимо с Yandex Object Storage).
 * - client(): ленивое создание S3Client c таймаутами из .env
 * - isAlive(): health-check через HeadBucket
 * - publicBase()/publicUrl(): единый резолвер публичных URL (S3 или CDN)
 */
final class S3
{
    private static ?S3Client $client = null;

    /**
     * Возвращает singleton S3Client
     */
    public static function client(): S3Client
    {
        if (self::$client instanceof S3Client) {
            return self::$client;
        }
        $endpoint = rtrim(Env::get('S3_ENDPOINT'), '/');
        $region   = Env::get('S3_REGION', 'ru-central1');
        $key      = Env::get('S3_KEY');
        $secret   = Env::get('S3_SECRET');
        $forcePS  = Env::get('S3_FORCE_PATH_STYLE', '1') === '1';
        $timeout  = (float)Env::get('S3_HTTP_TIMEOUT', '3.0');
        $ctout    = (float)Env::get('S3_HTTP_CONNECT_TIMEOUT', '1.0');

        self::$client = new S3Client([
            'version'                 => 'latest',
            'region'                  => $region,
            'endpoint'                => $endpoint,
            'use_path_style_endpoint' => $forcePS,
            'credentials'             => ['key' => $key, 'secret' => $secret],
            'http'                    => [
                'timeout'         => $timeout,
                'connect_timeout' => $ctout,
            ],
        ]);
        return self::$client;
    }

    /**
     * Health-check: HEAD на целевой бакет
     */
    public static function isAlive(): bool
    {
        try {
            $bucket = Env::get('S3_BUCKET');
            if ($bucket === '') {
                return false;
            }
            self::client()->headBucket(['Bucket' => $bucket]);
            return true;
        } catch (AwsException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Базовый публичный URL (с учётом CDN-переключателя)
     * S3: https://storage.yandexcloud.net/{bucket}/
     * CDN: https://cdn.chatzee.ru/
     */
    public static function publicBase(): string
    {
        $useCdn = Env::get('S3_USE_CDN', '0') === '1';
        if ($useCdn) {
            $cdn = rtrim(Env::get('S3_CDN_BASE'), '/');
            return $cdn . '/';
        }
        // Можно переопределить публичную точку, иначе = S3_ENDPOINT
        $endpoint = rtrim(Env::get('S3_PUBLIC_ENDPOINT', Env::get('S3_ENDPOINT')), '/');
        $bucket   = Env::get('S3_BUCKET');
        return $endpoint . '/' . $bucket . '/';
    }

    /**
     * Полный публичный URL для объекта
     */
    public static function publicUrl(string $key): string
    {
        $key = ltrim($key, '/');
        return self::publicBase() . $key;
    }
}

<?php
declare(strict_types=1);
namespace Messa\Services\S3;

use Aws\S3\S3Client;
use Messa\Core\Env;
use Messa\Core\Logger;

final class S3Service
{
    private S3Client $cli;
    private string $bucket;

    public function __construct()
    {
        $this->bucket = (string)Env::get('S3_BUCKET');
        $this->cli = new S3Client([
            'version' => 'latest',
            'region'  => (string)Env::get('S3_REGION','ru-central1'),
            'endpoint'=> (string)Env::get('S3_ENDPOINT','https://storage.yandexcloud.net'),
            'use_path_style_endpoint' => true,
            'signature_version' => 'v4',
            'http' => [
                'connect_timeout' => (float)((string)Env::get('S3_HTTP_CONNECT_TIMEOUT','2')),
                'timeout' => (float)((string)Env::get('S3_HTTP_TIMEOUT','10')),
            ],
            'credentials' => [
                'key'    => (string)Env::get('S3_KEY'),
                'secret' => (string)Env::get('S3_SECRET'),
            ],
        ]);
    }

    public function presignPut(string $key, string $contentType, int $expiresSec=900): array
    {
        $cmd = $this->cli->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key,
            'ContentType' => $contentType,
            'ACL' => 'private',
        ]);
        $req = $this->cli->createPresignedRequest($cmd, "+{$expiresSec} seconds");
        return [
            'url' => (string)$req->getUri(),
            'headers' => [
                'Content-Type' => $contentType,
            ],
            'expires_in' => $expiresSec,
        ];
    }

    public function presignGet(string $key, int $expiresSec=600): string
    {
        $cmd = $this->cli->getCommand('GetObject', ['Bucket'=>$this->bucket, 'Key'=>$key]);
        $req = $this->cli->createPresignedRequest($cmd, "+{$expiresSec} seconds");
        return (string)$req->getUri();
    }

    public function head(string $key): array
    {
        $r = $this->cli->headObject(['Bucket'=>$this->bucket, 'Key'=>$key]);
        $etag = isset($r['ETag']) ? trim((string)$r['ETag'], "\"") : null;
        return [
            'content_length' => (int)$r['ContentLength'],
            'etag' => $etag,
        ];

    }
    /**
     * Загрузка локального файла в S3 (используется FFMPEG-постпроцессом)
     */
    public function putFile(string $key, string $localPath, string $contentType): array
    {
        $res = $this->cli->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'Body'        => fopen($localPath, 'rb'),
            'ContentType' => $contentType,
            'ACL'         => 'private',
        ]);
        $etag = isset($res['ETag']) ? trim((string)$res['ETag'], "\"") : null;
        return ['etag' => $etag];
    }

    public function delete(string $key): void
{
    try {
        $this->cli->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
    } catch (\Throwable $e) {
        // Не роняем поток из-за невозможности удалить — просто логируем при необходимости
        Logger::warning('S3 delete failed', ['key' => $key, 'err' => $e->getMessage()]);
    }
}
}

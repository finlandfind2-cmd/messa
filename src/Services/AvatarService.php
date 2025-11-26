<?php
declare(strict_types=1);
namespace Messa\Services;

use Aws\S3\S3Client;
use Aws\Credentials\Credentials;
use Messa\Core\Config;
use Ramsey\Uuid\Uuid;

final class AvatarService
{
    private S3Client $s3;
    private string $bucket;
    private int $expires;

    public function __construct()
    {
        $this->bucket = (string)Config::get('S3_BUCKET', '');
        $endpoint = (string)Config::get('S3_ENDPOINT', '');
        $region = (string)Config::get('S3_REGION', 'ru-central1');
        $key = (string)Config::get('S3_KEY', '');
        $secret = (string)Config::get('S3_SECRET', '');
        $this->expires = (int)(Config::get('S3_PRESIGN_EXPIRES', '900') ?? 900);
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => new Credentials($key, $secret),
        ]);
    }

    /** Вернёт ['key','url','expires_sec','headers'] */
    public function presignUserAvatarPut(int $userId, string $contentType = 'image/jpeg'): array
    {
        $ext = match ($contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $key = sprintf('avatars/%d/%s.%s', $userId, Uuid::uuid4()->toString(), $ext);
        $cmd = $this->s3->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $contentType,
            'ACL' => 'private',
        ]);
        $req = $this->s3->createPresignedRequest($cmd, "+" . $this->expires . " seconds");
        return [
            'key' => $key,
            'url' => (string)$req->getUri(),
            'expires_sec' => $this->expires,
            'headers' => ['Content-Type' => $contentType],
        ];
    }
}

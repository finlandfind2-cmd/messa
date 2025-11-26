<?php
declare(strict_types=1);
namespace Messa\Http\Exceptions;

final class RateLimitedException extends HttpException
{
    public function __construct(string $message = 'Слишком много запросов', array $extra = [])
    { parent::__construct(429, 'rate_limited', $message, $extra); }
}

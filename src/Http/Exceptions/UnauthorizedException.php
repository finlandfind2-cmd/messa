<?php
declare(strict_types=1);
namespace Messa\Http\Exceptions;

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Требуется аутентификация', array $extra = [])
    { parent::__construct(401, 'unauthorized', $message, $extra); }
}

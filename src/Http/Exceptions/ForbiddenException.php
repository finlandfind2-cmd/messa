<?php
declare(strict_types=1);
namespace Messa\Http\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Доступ запрещён', array $extra = [])
    { parent::__construct(403, 'forbidden', $message, $extra); }
}

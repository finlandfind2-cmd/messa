<?php
declare(strict_types=1);
namespace Messa\Http\Exceptions;

final class InternalException extends HttpException
{
    public function __construct(string $message = 'Внутренняя ошибка сервера', array $extra = [])
    { parent::__construct(500, 'internal', $message, $extra); }
}


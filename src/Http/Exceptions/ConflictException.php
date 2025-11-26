<?php
declare(strict_types=1);
namespace Messa\Http\Exceptions;

final class ConflictException extends HttpException
{
    public function __construct(string $message = 'Конфликт запроса', array $extra = [])
    { parent::__construct(409, 'conflict', $message, $extra); }
}

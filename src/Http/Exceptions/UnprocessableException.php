<?php
declare(strict_types=1);
namespace Messa\Http\Exceptions;

final class UnprocessableException extends HttpException
{
    public function __construct(string $message = 'Ошибка валидации', array $extra = [])
    { parent::__construct(422, 'unprocessable', $message, $extra); }
}

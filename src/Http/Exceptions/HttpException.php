<?php
declare(strict_types=1);
namespace Messa\Http\Exceptions;

abstract class HttpException extends \RuntimeException
{
    public function __construct(
        protected int $status,
        protected string $errorCode, // ← Переименовано
        string $message,
        protected array $extra = []
    ) {
        // В Exception::$code мы по-прежнему кладём HTTP-статус
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function code(): string
    {
        // Возвращаем наш машинный код ошибки ('forbidden', 'not_found' и т.д.)
        return $this->errorCode;
    }

    public function details(): array
    {
        return $this->extra;
    }
}
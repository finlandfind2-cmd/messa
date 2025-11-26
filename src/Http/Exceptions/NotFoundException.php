<?php
declare(strict_types=1);
namespace Messa\Http\Exceptions;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Ресурс не найден', array $extra = [])
    { parent::__construct(404, 'not_found', $message, $extra); }
}

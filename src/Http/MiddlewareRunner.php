<?php
declare(strict_types=1);
namespace Messa\Http;

final class MiddlewareRunner
{
    /** @param list<callable(Request,Response,callable):Response> $stack */
    public function __construct(private array $stack) {}

    /** @param callable(Request,Response):Response $final */
    public function run(Request $req, Response $res, callable $final): Response
    {
        $next = $final;
        foreach (array_reverse($this->stack) as $mw) {
            $currentNext = $next;
            $next = function(Request $rq, Response $rs) use ($mw, $currentNext): Response {
                return $mw($rq, $rs, $currentNext);
            };
        }
        return $next($req, $res);
    }
}

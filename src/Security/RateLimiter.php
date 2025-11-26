<?php
declare(strict_types=1);
namespace Messa\Security;

final class RateLimiter
{
    public function allow(string $feature, string $scope, float $capacity, float $refillPerSec, float $cost=1.0): array
    {
        $r = \Messa\Core\Redis::client();
        $key = "rl:bucket:$feature:$scope";
        $now = (int)floor(microtime(true) * 1000);
        $ttlMs = (int)ceil(($capacity / max(0.0001, $refillPerSec)) * 1000);
        $lua = <<<LUA
local key=KEYS[1]
local capacity=tonumber(ARGV[1])
local refill=tonumber(ARGV[2])
local now=tonumber(ARGV[3])
local cost=tonumber(ARGV[4])
local data=redis.call('HMGET', key, 'tokens', 'ts')
local tokens=tonumber(data[1])
local ts=tonumber(data[2])
if not tokens then tokens=capacity end
if not ts then ts=now end
local delta=now-ts
if delta<0 then delta=0 end
local new_tokens=tokens + (delta/1000.0)*refill
if new_tokens>capacity then new_tokens=capacity end
local allowed=0
if new_tokens>=cost then
  new_tokens=new_tokens-cost
  allowed=1
end
redis.call('HMSET', key, 'tokens', new_tokens, 'ts', now)
redis.call('PEXPIRE', key, %d)
return {allowed, tostring(new_tokens)}
LUA;
        $lua = sprintf($lua, $ttlMs);
        $resp = $r->eval($lua, 1, $key, (string)$capacity, (string)$refillPerSec, (string)$now, (string)$cost);
        $allowed = ((int)($resp[0] ?? 0)) === 1;
        $remaining = (float)($resp[1] ?? 0.0);
        return [$allowed, $remaining];
    }
}

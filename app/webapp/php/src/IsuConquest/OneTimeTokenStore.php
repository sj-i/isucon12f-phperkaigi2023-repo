<?php

namespace App\IsuConquest;

class OneTimeTokenStore
{
    public function __construct(
        private readonly \Redis $redis,
    ) {
    }

    private function createKey(int $userID): string
    {
        return "one_time_token_{$userID}";
    }

    public function get(int $userID, string $token, int $tokenType): ?array
    {
        $key = $this->createKey($userID);
        $result = $this->redis->get($key);
        if ([$token, $tokenType] !== $result) {
            return null;
        }
        $this->redis->del($key);

        return $result;
    }

    public function generate(int $userID, string $token, int $tokenType, int $expire)
    {
        $key = $this->createKey($userID);
        $this->redis->setex($key, $expire, [$token, $tokenType]);
    }
}
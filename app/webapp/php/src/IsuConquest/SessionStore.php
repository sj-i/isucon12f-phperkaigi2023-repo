<?php

namespace App\IsuConquest;

use Redis;

class SessionStore
{
    public function __construct(
        private readonly Redis $redis,
    ) {
    }

    private function createSessionKey(string $sessionID): string
    {
        return implode('_', ['user_session', $sessionID]);
    }

    public function setSession(Session $session): void
    {
        $this->redis->setex(
            $this->createSessionKey($session->sessionID),
            86400,
            $session,
        );
    }

    public function getSession(string $sessionID): ?Session
    {
        $result = $this->redis->get($this->createSessionKey($sessionID));
        if ($result === false) {
            return null;
        }
        return $result;
    }


}

<?php

namespace App\IsuConquest;

class MasterVersionStore
{
    public function __construct(
        private readonly \Redis $redis,
    ) {
    }

    public function setMasterVersion(string $masterVersion): void
    {
        $this->redis->set('master_version', $masterVersion);
    }

    public function getMasterVersion(): string
    {
        return $this->redis->get('master_version') ?: '1';
    }
}

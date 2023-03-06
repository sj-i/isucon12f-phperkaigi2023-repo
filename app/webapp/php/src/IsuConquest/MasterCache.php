<?php

namespace App\IsuConquest;
use PDO;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\CacheItem;

class MasterCache
{
    /** @var array[] */
    private array $itemMastersCache = [];

    /** @var array[] */
    private array $loginBonusMastersCache = [];

    /** @var array[] */
    private array $gachaMastersCache = [];

    /** @var array[] */
    private array $gachaItmMastersCache = [];

    private readonly PhpFilesAdapter $cache;

    public function __construct(
        private readonly PDO $db,
    ) {
        $this->cache = new PhpFilesAdapter();
    }

    public function shouldRecache(): string
    {
        $stmt = $this->db->query('SELECT master_version FROM version_masters WHERE status = 1');
        $row = $stmt->fetch();
        $cached = $this->cache->getItem('master_version');
        if (!$cached->isHit() or $row['master_version'] !== $cached->get()) {
            $this->recache($row['master_version']);
        }
        return $row['master_version'];
    }

    public function recache(string $master_version)
    {
        $this->cache->saveDeferred($this->cache->getItem('master_version')->set($master_version));
        $this->cache->saveDeferred(
            $this->cache->getItem('login_bonus_masters')
                ->set(
                    $this->db->query('SELECT * FROM login_bonus_masters')->fetchAll(PDO::FETCH_ASSOC)
                )
        );
        $this->cache->saveDeferred(
            $this->cache->getItem('item_masters')
                ->set(
                    $this->db->query('SELECT * FROM item_masters')->fetchAll(PDO::FETCH_ASSOC)
                )
        );
        $this->cache->saveDeferred(
            $this->cache->getItem('gacha_masters')
                ->set(
                    $this->db->query('SELECT * FROM gacha_masters ORDER BY display_order')->fetchAll(PDO::FETCH_ASSOC)
                )
        );
        $this->cache->saveDeferred(
            $this->cache->getItem('gacha_masters')
                ->set(
                    $this->db->query('SELECT * FROM gacha_masters ORDER BY display_order')->fetchAll(PDO::FETCH_ASSOC)
                )
        );
        $this->cache->saveDeferred(
            $this->cache->getItem('gacha_item_masters')
                ->set(
                    $this->db->query('SELECT * FROM gacha_item_masters ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC)
                )
        );
    }

    public function getLoginBonusMaster(int $requestAt): array
    {
        if (!$this->loginBonusMastersCache) {
            $this->loginBonusMastersCache = $this->cache->getItem('login_bonus_masters')->get();
        }
        $result = [];
        foreach ($this->loginBonusMastersCache as $loginBonusMaster) {
            if ($loginBonusMaster['start_at'] <= $requestAt and $requestAt <= $loginBonusMaster['end_at']) {
                $result[] = LoginBonusMaster::fromDBRow($loginBonusMaster);
            }
        }
        return $result;
    }

    public function getItemMasterById(int $id): ?ItemMaster
    {
        if (!$this->itemMastersCache) {
            $this->itemMastersCache = $this->cache->getItem('item_masters')->get();
        }
        foreach ($this->itemMastersCache as $itemMaster) {
            if ($itemMaster['id'] === $id) {
                return ItemMaster::fromDBRow($itemMaster);
            }
        }
        return null;
    }

    public function getGachaMaster(int $requestAt): array
    {
        if (!$this->gachaMastersCache) {
            $this->gachaMastersCache = $this->cache->getItem('gacha_masters')->get();
        }
        $result = [];
        foreach ($this->gachaMastersCache as $gachaMaster) {
            if ($gachaMaster['start_at'] <= $requestAt and $requestAt <= $gachaMaster['end_at']) {
                $result[] = GachaMaster::fromDBRow($gachaMaster);
            }
        }
        return $result;
    }

    public function getGachaMasterByID(int $id, int $requestAt): ?GachaMaster
    {
        if (!$this->gachaMastersCache) {
            $this->gachaMastersCache = $this->cache->getItem('gacha_masters')->get();
        }
        foreach ($this->gachaMastersCache as $gachaMaster) {
            if ($gachaMaster['id'] === $id and $gachaMaster['start_at'] <= $requestAt and $requestAt <= $gachaMaster['end_at']) {
                return GachaMaster::fromDBRow($gachaMaster);
            }
        }
        return null;
    }

    /** @return GachaItemMaster[] */
    public function getGachaItemMasterByID(int $id): array
    {
        if (!$this->gachaItmMastersCache) {
            $this->gachaItmMastersCache = $this->cache->getItem('gacha_item_masters')->get();
        }
        $result = [];
        foreach ($this->gachaItmMastersCache as $gachaItemMaster) {
            if ($gachaItemMaster['gacha_id'] === $id) {
                $result[] = GachaItemMaster::fromDBRow($gachaItemMaster);
            }
        }
        return $result;
    }
}
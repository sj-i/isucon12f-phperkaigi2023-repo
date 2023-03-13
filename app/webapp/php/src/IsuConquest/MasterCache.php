<?php

namespace App\IsuConquest;
use PDO;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

class MasterCache
{
    /** @var array[] */
    private array $itemMastersCache = [];

    /** @var array[] */
    private array $loginBonusMastersCache = [];

    /** @var array[] */
    private array $gachaMastersCache = [];

    /** @var array[] */
    private array $gachaItemMastersCache = [];

    /** @var array[] */
    private array $presentAllMastersCache = [];

    /** @var array[] */
    private array $loginBonusRewardMastersCache = [];

    private string $master_version = 'unknown';

    private readonly PhpFilesAdapter $cache;

    public function __construct(
        private readonly MasterVersionStore $masterVersionStore,
    ) {
        $this->cache = new PhpFilesAdapter();
    }

    public function shouldRecache(PDO $db): string
    {
        $currentMasterVersion = $this->masterVersionStore->getMasterVersion();
        $cached = $this->cache->getItem('master_version');
        if (!$cached->isHit() or $currentMasterVersion !== $cached->get()) {
            $this->recacheDisk($db, $currentMasterVersion);
        }
        if ($this->master_version !== $currentMasterVersion) {
            $this->itemMastersCache = [];
            $this->loginBonusMastersCache = [];
            $this->gachaMastersCache = [];
            $this->gachaItemMastersCache = [];
            $this->presentAllMastersCache = [];
            $this->loginBonusRewardMastersCache = [];
            $this->gachaItemMasterItemCache = [];
            $this->presentAllMasterItemCache = [];
            $this->loginBonusRewardMastersItemCache = [];
            $this->master_version = $currentMasterVersion;
        }
        return $currentMasterVersion;
    }

    public function recacheDisk(PDO $db, string $master_version)
    {
        $master_version_cache = $this->cache->getItem('master_version');
        $login_bonus_masters_cache = $this->cache->getItem('login_bonus_masters');
        $item_masters_cache = $this->cache->getItem('item_masters');
        $gacha_masters_cache = $this->cache->getItem('gacha_masters');
        $gacha_item_masters_cache = $this->cache->getItem('gacha_item_masters');
        $present_all_masters_cache = $this->cache->getItem('present_all_masters');
        $login_bonus_reward_masters_cache = $this->cache->getItem('login_bonus_reward_masters');

        $this->cache->saveDeferred($master_version_cache->set($master_version));
        $this->cache->saveDeferred(
            $login_bonus_masters_cache->set(
                $db->query('SELECT * FROM login_bonus_masters')->fetchAll(PDO::FETCH_ASSOC)
            )
        );
        $this->cache->saveDeferred(
            $item_masters_cache->set(
                $db->query('SELECT * FROM item_masters')->fetchAll(PDO::FETCH_ASSOC)
            )
        );
        $this->cache->saveDeferred(
            $gacha_masters_cache->set(
                $db->query('SELECT * FROM gacha_masters ORDER BY display_order')->fetchAll(PDO::FETCH_ASSOC)
            )
        );
        $this->cache->saveDeferred(
            $gacha_item_masters_cache->set(
                $db->query('SELECT * FROM gacha_item_masters ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC)
            )
        );
        $this->cache->saveDeferred(
            $present_all_masters_cache->set(
                $db->query('SELECT * FROM present_all_masters')->fetchAll(PDO::FETCH_ASSOC)
            )
        );
        $this->cache->saveDeferred(
            $login_bonus_reward_masters_cache->set(
                $db->query('SELECT * FROM login_bonus_reward_masters')->fetchAll(PDO::FETCH_ASSOC)
            )
        );
        $this->cache->commit();
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

    private array $gachaItemMasterItemCache = [];
    /** @return GachaItemMaster[] */
    public function getGachaItemMasterByID(int $id): array
    {
        if (!$this->gachaItemMastersCache) {
            $this->gachaItemMastersCache = $this->cache->getItem('gacha_item_masters')->get();
        }
        $result = [];
        if (isset($this->gachaItemMasterItemCache[$id])) {
            return $this->gachaItemMasterItemCache[$id];
        }
        foreach ($this->gachaItemMastersCache as $gachaItemMaster) {
            if ($gachaItemMaster['gacha_id'] === $id) {
                $result[] = GachaItemMaster::fromDBRow($gachaItemMaster);
            }
        }
        $this->gachaItemMasterItemCache[$id] = $result;
        return $result;
    }

    private array $presentAllMasterItemCache = [];
    /** @return PresentAllMaster[] */
    public function getPresentAllMaster(int $requestAt): array
    {
        if (!$this->presentAllMastersCache) {
            $this->presentAllMastersCache = $this->cache->getItem('present_all_masters')->get();
        }

        $result = [];
        foreach ($this->presentAllMastersCache as $presentAllMaster) {
            if ($presentAllMaster['registered_start_at'] <= $requestAt and $requestAt <= $presentAllMaster['registered_end_at']) {
                $id = $presentAllMaster['id'];
                if (isset($this->presentAllMasterItemCache[$id])) {
                    $result[] = $this->presentAllMasterItemCache[$id];
                } else {
                    $item = PresentAllMaster::fromDBRow($presentAllMaster);
                    $this->presentAllMasterItemCache[$presentAllMaster['id']] = $item;
                    $result[] = $item;
                }
            }
        }
        return $result;
    }

    private array $loginBonusRewardMastersItemCache = [];
    public function getLoginBonusRewardMasterByIDAndSequence(int $loginBonusID, int $rewardSequence): ?LoginBonusRewardMaster
    {
        if (!$this->loginBonusRewardMastersCache) {
            $this->loginBonusRewardMastersCache = $this->cache->getItem('login_bonus_reward_masters')->get();
        }

        if (isset($this->loginBonusRewardMastersItemCache[$loginBonusID][$rewardSequence])) {
            return $this->loginBonusRewardMastersItemCache[$loginBonusID][$rewardSequence];
        }
        foreach ($this->loginBonusRewardMastersCache as $loginBonusRewardMaster) {
            if ($loginBonusRewardMaster['login_bonus_id'] === $loginBonusID and $loginBonusRewardMaster['reward_sequence'] === $rewardSequence) {
                return $this->loginBonusRewardMastersItemCache[$loginBonusID][$rewardSequence] = LoginBonusRewardMaster::fromDBRow($loginBonusRewardMaster);
            }
        }
        return null;
    }
}


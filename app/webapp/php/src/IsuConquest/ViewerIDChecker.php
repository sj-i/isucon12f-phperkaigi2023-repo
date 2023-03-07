<?php

namespace App\IsuConquest;


use PDO;

class ViewerIDChecker
{
    private array $cache = [];
    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {
    }

    public function check(int $userID, string $viewerID): bool
    {
        if (isset($this->cache[$userID][$viewerID])) {
            return true;
        }
        $query = 'SELECT * FROM user_devices WHERE user_id=? AND platform_id=?';

        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $stmt->bindValue(1, $userID, PDO::PARAM_INT);
        $stmt->bindValue(2, $viewerID);
        $stmt->execute();
        if ($stmt->fetch() !== false) {
            $this->cache[$userID][$viewerID] = true;
            return true;
        }
        return false;
    }
}
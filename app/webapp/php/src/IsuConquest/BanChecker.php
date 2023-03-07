<?php

namespace App\IsuConquest;

use PDO;

class BanChecker
{
    private array $banTable = [];

    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {
    }

    public function isBan(int $userID): bool
    {
        if (isset($this->banTable[$userID])) {
            return true;
        }

        $query = 'SELECT id FROM user_bans WHERE user_id=?';
        $stmt = $this->databaseManager->selectDatabase($userID)->prepare($query);
        $stmt->bindValue(1, $userID, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row !== false) {
            $this->banTable[$userID] = true;
            return true;
        }
        return false;
    }
}
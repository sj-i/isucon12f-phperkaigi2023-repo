<?php

namespace App\IsuConquest;
use App\Application\Settings\SettingsInterface;
use PDO;

class DatabaseManager
{
    private const DB_HOSTS = [
        '172.31.13.15',
        '172.31.13.79',
//        '172.31.8.234',
//        '172.31.1.118',
    ];

    /** @var PDO[] */
    private array $dbs = [];

    public function __construct(
        private SettingsInterface $settings,
    ) {
    }

    /** @return string[] */
    public function getDbHosts(): array
    {
        return self::DB_HOSTS;
    }

    public function connectDatabase(string $host): PDO
    {
        $databaseSettings = $this->settings->get('database');

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;port=%s;charset=utf8mb4',
            $host,
            $databaseSettings['database'],
            $databaseSettings['port'],
        );

        return new PDO($dsn, $databaseSettings['user'], $databaseSettings['password'], [
            PDO::ATTR_PERSISTENT => true,
        ]);
    }

    public function initDatabase(): void
    {
        foreach (self::DB_HOSTS as $host) {
            $this->dbs[] = $this->connectDatabase($host);
        }
    }

    /** @return PDO[] */
    public function getAllDatabases(): array
    {
        if (empty($this->dbs)) {
            $this->initDatabase();
        }
        return $this->dbs;
    }

    public function selectDatabase(int $id): PDO
    {
        if (empty($this->dbs)) {
            $this->initDatabase();
        }
        return $this->dbs[$id % count($this->dbs)];
    }

    public function adminDatabase(): PDO
    {
        return $this->selectDatabase(0);
    }
}

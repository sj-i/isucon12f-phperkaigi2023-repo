<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function DI\autowire;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);

            $loggerSettings = $settings->get('logger');
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },
        PDO::class => function (ContainerInterface $c) {
            $databaseSettings = $c->get(SettingsInterface::class)->get('database');

            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;port=%s;charset=utf8mb4',
                $databaseSettings['host'],
                $databaseSettings['database'],
                $databaseSettings['port'],
            );

            return new PDO($dsn, $databaseSettings['user'], $databaseSettings['password'], [
                PDO::ATTR_PERSISTENT => true,
            ]);
        },
        HttpClientInterface::class => autowire(CurlHttpClient::class),
        Redis::class => function (ContainerInterface $c) {
            $redisSettings = $c->get(SettingsInterface::class)->get('redis');
            $redis = new \Redis();
            $redis->connect(host: $redisSettings['host'], port: (int)$redisSettings['port']);
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
            return $redis;
        }
    ]);
};

<?php

declare(strict_types=1);

use App\Application\Handlers\HttpErrorHandler;
use App\Application\Settings\SettingsInterface;
use App\IsuConquest\TransactionCleanupMiddleware;

error_reporting(E_ALL);

set_error_handler(fn (int $code, string $description, string $file, int $line): bool => match ($code) {
    E_NOTICE, E_WARNING => throw new ErrorException("$description in $file on line $line", $code),
    default => false,
});

date_default_timezone_set('Asia/Tokyo');

$psr17Factory = new Nyholm\Psr7\Factory\Psr17Factory();
$containerBuilder = new DI\ContainerBuilder();
$containerBuilder->addDefinitions([
    Psr\Http\Message\ResponseFactoryInterface::class => $psr17Factory,
    Psr\Http\Message\ServerRequestFactoryInterface::class => $psr17Factory,
    Psr\Http\Message\StreamFactoryInterface::class => $psr17Factory,
    Psr\Http\Message\UploadedFileFactoryInterface::class => $psr17Factory,
    Spiral\RoadRunner\WorkerInterface::class => Spiral\RoadRunner\Worker::create(),
    Spiral\RoadRunner\Http\PSR7WorkerInterface::class => DI\autowire(Spiral\RoadRunner\Http\PSR7Worker::class),
]);

// Set up settings
$settings = require __DIR__ . '/app/settings.php';
$settings($containerBuilder);

// Set up dependencies
$dependencies = require __DIR__ . '/app/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

$app = DI\Bridge\Slim\Bridge::create($container);

$callableResolver = $app->getCallableResolver();

// Register middleware
$middleware = require __DIR__ . '/app/middleware.php';
$middleware($app);

// Register routes
$routes = require __DIR__ . '/app/routes.php';
$routes($app);

/** @var SettingsInterface $settings */
$settings = $container->get(SettingsInterface::class);

$logError = $settings->get('logError');
$logErrorDetails = $settings->get('logErrorDetails');

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Create Error Handler
$responseFactory = $app->getResponseFactory();
$errorHandler = new HttpErrorHandler($callableResolver, $responseFactory);

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(true, $logError, $logErrorDetails);
$errorMiddleware->setDefaultErrorHandler($errorHandler);


return $app;
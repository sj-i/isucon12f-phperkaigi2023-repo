<?php

namespace App\IsuConquest;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TransactionCleanupMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $this->databaseManager->cleanup();
        return $response;
    }
}
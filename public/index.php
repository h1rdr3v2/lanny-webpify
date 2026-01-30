<?php

use Slim\Psr7\Response;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use App\Middlewares\JsonResponseMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Handlers\Strategies\RequestResponseArgs;

define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';

$builder = new ContainerBuilder();
$container = $builder->addDefinitions(ROOT . '/config/definitions.php')->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$collector = $app->getRouteCollector();
$collector->setDefaultInvocationStrategy(new RequestResponseArgs());

$app->addBodyParsingMiddleware();

// Push the error to json
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');

$errorMiddleware->setErrorHandler(
    HttpNotFoundException::class,
    function (ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails) {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'error' => [
                'message' => 'Route not found',
                'status' => 404
            ]
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
);

// Add security headers
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->withHeader('Content-Security-Policy', "default-src 'none'")
        ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
        ->withHeader('Access-Control-Allow-Origin', '*') // Adjust this based on your CORS needs
        ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->withHeader('Pragma', 'no-cache');
});

$app->add(new JsonResponseMiddleware()); // Add JSON header to all responses

require __DIR__ . '/../src/routes.php';

$app->run();

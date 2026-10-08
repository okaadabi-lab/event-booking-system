<?php

declare(strict_types=1);

use App\Http\ErrorHandler;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\DomainErrorMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Support\Config;
use App\Support\Container;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\App;

// Builds the Slim app. Used by public/index.php and by the HTTP tests, which
// dispatch requests through exactly the same middleware stack.
return static function (Config $config, ?Container $container = null): App {
    $container ??= (require __DIR__ . '/container.php')($config);
    date_default_timezone_set($config->timezone);

    $app = new App($container->make(ResponseFactoryInterface::class), $container);

    (require __DIR__ . '/routes.php')($app, $container);

    // Slim runs middleware last-added-first, so read this list bottom-up for request order:
    // security headers -> errors -> session -> CSRF -> domain errors -> body parsing -> routing.
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $app->add($container->make(DomainErrorMiddleware::class));
    $app->add($container->make(CsrfMiddleware::class));
    $app->add($container->make(SessionMiddleware::class));
    $errors = $app->addErrorMiddleware($config->debug, true, false);
    $errors->setDefaultErrorHandler($container->make(ErrorHandler::class));
    $app->add($container->make(SecurityHeadersMiddleware::class));

    return $app;
};

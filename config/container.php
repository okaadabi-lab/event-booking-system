<?php

declare(strict_types=1);

use App\Activity\ActivityLog;
use App\Auth\Authenticator;
use App\Auth\Policy;
use App\Auth\UserRepository;
use App\Booking\BookingRepository;
use App\Booking\BookingService;
use App\Catalog\CatalogRepository;
use App\Customer\CustomerRepository;
use App\Http\Controller\AuthController;
use App\Http\Controller\BookingController;
use App\Http\Controller\HealthController;
use App\Http\Controller\InquiryController;
use App\Http\Controller\OperationsController;
use App\Http\Controller\PaymentController;
use App\Http\Controller\ResourceController;
use App\Http\Controller\SetupController;
use App\Http\ErrorHandler;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\DomainErrorMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Session\SessionStore;
use App\Http\TwigExtension;
use App\Http\View;
use App\Inquiry\InquiryService;
use App\Operations\OperationsBoard;
use App\Payment\PaymentRepository;
use App\Payment\PaymentService;
use App\Resource\ResourceRepository;
use App\Resource\ResourceScheduler;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Container;
use App\Support\Database;
use App\Support\RateLimiter;
use App\Support\SystemClock;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Every service and how it is built, in one file. Factories run lazily, once.
return static function (Config $config): Container {
    $c = new Container();
    $c->instance(Config::class, $config);

    $c->set(Clock::class, fn () => new SystemClock(new DateTimeZone($config->timezone)));
    $c->set(Database::class, fn () => Database::fromConfig($config));
    $c->set(LoggerInterface::class, function () use ($config): LoggerInterface {
        $level = Level::Info;
        foreach (Level::cases() as $case) {
            if (strtolower($case->name) === $config->logLevel) {
                $level = $case;
            }
        }
        $handler = new StreamHandler($config->logPath, $level);
        $handler->setFormatter(new LineFormatter(null, null, true, true, true));

        return new Logger('app', [$handler]);
    });
    $c->set(ResponseFactoryInterface::class, fn () => new ResponseFactory());
    $c->set(Environment::class, function () use ($config): Environment {
        $twig = new Environment(new FilesystemLoader($config->rootDir . '/templates'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $config->isProduction() ? $config->rootDir . '/storage/cache/twig' : false,
        ]);
        $twig->addExtension(new TwigExtension());

        return $twig;
    });

    $c->set(Policy::class, fn () => new Policy());
    $c->set(RateLimiter::class, fn (Container $c) => new RateLimiter($c->make(Database::class), $c->make(Clock::class)));
    $c->set(ActivityLog::class, fn (Container $c) => new ActivityLog($c->make(Database::class), $c->make(Clock::class)));
    $c->set(UserRepository::class, fn (Container $c) => new UserRepository($c->make(Database::class)));
    $c->set(CustomerRepository::class, fn (Container $c) => new CustomerRepository($c->make(Database::class)));
    $c->set(CatalogRepository::class, fn (Container $c) => new CatalogRepository($c->make(Database::class)));
    $c->set(ResourceRepository::class, fn (Container $c) => new ResourceRepository($c->make(Database::class)));
    $c->set(BookingRepository::class, fn (Container $c) => new BookingRepository($c->make(Database::class)));
    $c->set(PaymentRepository::class, fn (Container $c) => new PaymentRepository($c->make(Database::class)));
    $c->set(SessionStore::class, fn (Container $c) => new SessionStore($c->make(Database::class), $c->make(Clock::class)));

    $c->set(Authenticator::class, fn (Container $c) => new Authenticator(
        $c->make(UserRepository::class),
        $c->make(RateLimiter::class),
        $config->loginMaxFailures,
        $config->loginWindowMinutes,
    ));
    $c->set(ResourceScheduler::class, fn (Container $c) => new ResourceScheduler(
        $c->make(Database::class),
        $c->make(BookingRepository::class),
        $c->make(ResourceRepository::class),
        $c->make(ActivityLog::class),
        $c->make(Clock::class),
        $config->resourceBufferMinutes,
    ));
    $c->set(BookingService::class, fn (Container $c) => new BookingService(
        $c->make(Database::class),
        $c->make(BookingRepository::class),
        $c->make(CatalogRepository::class),
        $c->make(ResourceScheduler::class),
        $c->make(ActivityLog::class),
        $c->make(Policy::class),
        $c->make(Clock::class),
        $config->depositPercent,
        $config->balanceDueDaysBeforeEvent,
    ));
    $c->set(PaymentService::class, fn (Container $c) => new PaymentService(
        $c->make(Database::class),
        $c->make(BookingRepository::class),
        $c->make(ActivityLog::class),
        $c->make(Policy::class),
        $c->make(Clock::class),
    ));
    $c->set(InquiryService::class, fn (Container $c) => new InquiryService(
        $c->make(Database::class),
        $c->make(CustomerRepository::class),
        $c->make(BookingRepository::class),
        $c->make(ActivityLog::class),
        $c->make(RateLimiter::class),
        $c->make(Clock::class),
        $config->inquiryMaxPerHour,
        $config->depositPercent,
    ));
    $c->set(OperationsBoard::class, fn (Container $c) => new OperationsBoard(
        $c->make(BookingRepository::class),
        $c->make(ResourceRepository::class),
        $c->make(ResourceScheduler::class),
        $config->balanceDueDaysBeforeEvent,
    ));

    $c->set(View::class, fn (Container $c) => new View(
        $c->make(Environment::class),
        $c->make(Policy::class),
        $c->make(Clock::class),
        $config->appName,
    ));
    $c->set(ErrorHandler::class, fn (Container $c) => new ErrorHandler(
        $c->make(ResponseFactoryInterface::class),
        $c->make(Environment::class),
        $c->make(LoggerInterface::class),
        $config->debug,
    ));
    $c->set(SessionMiddleware::class, fn (Container $c) => new SessionMiddleware(
        $c->make(SessionStore::class),
        $config->sessionSecureCookie,
    ));
    $c->set(CsrfMiddleware::class, fn (Container $c) => new CsrfMiddleware(
        $c->make(ResponseFactoryInterface::class),
        $c->make(Environment::class),
    ));
    $c->set(AuthMiddleware::class, fn (Container $c) => new AuthMiddleware(
        $c->make(UserRepository::class),
        $c->make(ResponseFactoryInterface::class),
    ));
    $c->set(DomainErrorMiddleware::class, fn (Container $c) => new DomainErrorMiddleware(
        $c->make(ResponseFactoryInterface::class),
    ));
    $c->set(SecurityHeadersMiddleware::class, fn () => new SecurityHeadersMiddleware());

    $c->set(HealthController::class, fn (Container $c) => new HealthController($c->make(Database::class)));
    $c->set(AuthController::class, fn (Container $c) => new AuthController(
        $c->make(View::class),
        $c->make(Authenticator::class),
        $c->make(LoggerInterface::class),
    ));
    $c->set(InquiryController::class, fn (Container $c) => new InquiryController(
        $c->make(View::class),
        $c->make(InquiryService::class),
        $c->make(Clock::class),
        $c->make(LoggerInterface::class),
    ));
    $c->set(OperationsController::class, fn (Container $c) => new OperationsController(
        $c->make(View::class),
        $c->make(OperationsBoard::class),
        $c->make(BookingRepository::class),
        $c->make(Clock::class),
    ));
    $c->set(BookingController::class, fn (Container $c) => new BookingController(
        $c->make(View::class),
        $c->make(BookingRepository::class),
        $c->make(BookingService::class),
        $c->make(CatalogRepository::class),
        $c->make(CustomerRepository::class),
        $c->make(ResourceRepository::class),
        $c->make(ResourceScheduler::class),
        $c->make(PaymentRepository::class),
        $c->make(ActivityLog::class),
        $c->make(Policy::class),
        $c->make(Clock::class),
        $config->balanceDueDaysBeforeEvent,
        $config->depositPercent,
    ));
    $c->set(ResourceController::class, fn (Container $c) => new ResourceController(
        $c->make(ResourceScheduler::class),
        $c->make(Policy::class),
    ));
    $c->set(PaymentController::class, fn (Container $c) => new PaymentController(
        $c->make(View::class),
        $c->make(PaymentService::class),
        $c->make(PaymentRepository::class),
        $c->make(OperationsBoard::class),
        $c->make(Policy::class),
        $c->make(Clock::class),
    ));
    $c->set(SetupController::class, fn (Container $c) => new SetupController(
        $c->make(View::class),
        $c->make(CatalogRepository::class),
        $c->make(ResourceRepository::class),
        $c->make(UserRepository::class),
        $c->make(ActivityLog::class),
        $c->make(Policy::class),
    ));

    return $c;
};

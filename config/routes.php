<?php

declare(strict_types=1);

use App\Http\Controller\AuthController;
use App\Http\Controller\BookingController;
use App\Http\Controller\HealthController;
use App\Http\Controller\InquiryController;
use App\Http\Controller\OperationsController;
use App\Http\Controller\PaymentController;
use App\Http\Controller\ResourceController;
use App\Http\Controller\SetupController;
use App\Http\Middleware\AuthMiddleware;
use App\Support\Container;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $c): void {
    $app->get('/health', [HealthController::class, 'check']);

    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout']);

    $app->get('/inquiry', [InquiryController::class, 'show']);
    $app->post('/inquiry', [InquiryController::class, 'submit']);
    $app->get('/inquiry/thanks', [InquiryController::class, 'thanks']);

    $app->group('', function (RouteCollectorProxy $r): void {
        $r->get('/', [OperationsController::class, 'home']);
        $r->get('/today', [OperationsController::class, 'today']);
        $r->get('/calendar', [OperationsController::class, 'calendar']);

        $r->get('/bookings', [BookingController::class, 'index']);
        $r->get('/bookings/new', [BookingController::class, 'create']);
        $r->post('/bookings', [BookingController::class, 'store']);
        $r->get('/bookings/{id:[0-9]+}', [BookingController::class, 'show']);
        $r->get('/bookings/{id:[0-9]+}/edit', [BookingController::class, 'edit']);
        $r->post('/bookings/{id:[0-9]+}', [BookingController::class, 'update']);
        $r->post('/bookings/{id:[0-9]+}/quote', [BookingController::class, 'markQuoted']);
        $r->post('/bookings/{id:[0-9]+}/confirm', [BookingController::class, 'confirm']);
        $r->post('/bookings/{id:[0-9]+}/complete', [BookingController::class, 'complete']);
        $r->post('/bookings/{id:[0-9]+}/cancel', [BookingController::class, 'cancel']);

        $r->post('/bookings/{id:[0-9]+}/resources', [ResourceController::class, 'assign']);
        $r->post('/bookings/{id:[0-9]+}/resources/{resourceId:[0-9]+}/release', [ResourceController::class, 'release']);
        $r->get('/api/availability', [ResourceController::class, 'availability']);

        $r->post('/bookings/{id:[0-9]+}/payments', [PaymentController::class, 'store']);
        $r->get('/payments', [PaymentController::class, 'due']);
        $r->get('/payments/export', [PaymentController::class, 'export']);

        $r->get('/setup', [SetupController::class, 'index']);
        $r->post('/setup/packages', [SetupController::class, 'savePackage']);
        $r->post('/setup/addons', [SetupController::class, 'saveAddon']);
        $r->post('/setup/resources', [SetupController::class, 'saveResource']);
        $r->post('/setup/users', [SetupController::class, 'saveUser']);
    })->add($c->make(AuthMiddleware::class));
};

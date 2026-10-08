<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\View;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class HealthController
{
    public function __construct(private readonly Database $db)
    {
    }

    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $started = microtime(true);
        try {
            $this->db->fetchValue('SELECT 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unreachable';
        }

        return View::json($response, [
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ], $database === 'ok' ? 200 : 503);
    }
}

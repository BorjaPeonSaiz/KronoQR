<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // La version va en la ruta, no en una cabecera (ADR-012). Todo endpoint
        // del producto cuelga de /api/v1. El fichero llega vacio de la tarea
        // 0.2: los primeros endpoints son de las tareas 0.6 (contrato) y 1.7.
        api: __DIR__.'/../routes/api_v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        // Sin rutas web: las tres aplicaciones cliente son SPA servidas por
        // Nginx, y el backend solo expone API. Sin sonda /up del esqueleto: la
        // del producto es GET /api/v1/health (doc 01 Anexo B), y su forma la
        // fija el contrato OpenAPI antes que el codigo (ADR-013).
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

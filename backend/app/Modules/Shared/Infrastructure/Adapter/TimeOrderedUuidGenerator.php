<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\UuidGenerator;
use Illuminate\Support\Str;

/**
 * UUID v7 con el generador del framework: la unica implementacion de
 * produccion de {@see UuidGenerator}.
 *
 * `Str::uuid7()` y no `Ramsey\Uuid\Uuid::uuid7()` directamente: es la misma
 * libreria por debajo, pero respeta `Str::createUuidsUsing()`, y asi una prueba
 * de integracion que lo necesite puede fijar los identificadores sin tocar
 * este adaptador.
 */
final readonly class TimeOrderedUuidGenerator implements UuidGenerator
{
    #[\Override]
    public function next(): string
    {
        return Str::uuid7()->toString();
    }
}

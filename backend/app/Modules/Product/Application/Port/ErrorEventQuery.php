<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\ErrorEventStatusFilter;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use DateTimeImmutable;

/**
 * Lo que se le pide al historico: los cinco filtros y la paginacion de
 * `GET /api/v1/diagnostics/errors` (RF-PD-15).
 *
 * **Un objeto y no siete argumentos** por lo mismo que la bandeja de
 * incidencias: la consulta la construyen tres sitios —el `FormRequest`, el
 * comando `product:errors` y el recolector del paquete de diagnostico— y con una
 * lista de posicionales bastaria intercambiar `from` y `to` en uno de ellos para
 * que devolviera siempre vacio sin que nada fallara.
 *
 * **Los tipos son los del dominio y no cadenas.** `source` y `level` llegan ya
 * como enum, asi que una consulta con un origen que no existe no se puede
 * construir: se cae antes, en la validacion, con un `422` que dice cuales hay.
 *
 * **`from` y `to` acotan `last_seen_at`, no `first_seen_at`**, y los dos son
 * inclusivos. Es el criterio de todo lo demas en esta tabla —la retencion
 * envejece por `last_seen_at` y el listado ordena por el—: lo que interesa de un
 * grupo es cuando paso la ultima vez, no cuando empezo. Un grupo que empezo hace
 * un ano y sigue ocurriendo hoy tiene que salir en «lo de esta semana».
 */
final readonly class ErrorEventQuery
{
    public function __construct(
        public ErrorEventStatusFilter $status,
        public ?ErrorSource $source = null,
        public ?ErrorLevel $level = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}

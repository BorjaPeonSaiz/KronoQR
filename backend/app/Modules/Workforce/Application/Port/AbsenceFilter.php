<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Workforce\Domain\ValueObject\AbsenceStatus;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use DateTimeImmutable;

/**
 * Lo que acota una consulta de ausencias (**RF-GP-04**, RF-ID-03).
 *
 * ## Por que un objeto y no seis parametros
 *
 * Porque {@see AbsenceRepository} tiene que preguntar dos veces lo mismo —el
 * recuento y la pagina— y seis parametros repetidos en dos firmas es la via por
 * la que un filtro acaba aplicandose en una de las dos y no en la otra. Ese
 * fallo da un `meta.total` que no cuadra con lo que se ve, y en un listado con
 * alcance por departamento significa decirle a un responsable cuanta gente hay
 * fuera del suyo.
 *
 * El limite del §3.5 sobre el numero de parametros dice lo mismo por otro
 * camino.
 *
 * ## El alcance va primero y no es opcional
 *
 * Es la acotacion que **quien llama no elige** (RF-ID-03), y por eso ocupa el
 * primer lugar y no tiene valor por defecto: una firma en la que el alcance se
 * pueda omitir es una firma en la que alguien lo omitira.
 */
final readonly class AbsenceFilter
{
    public function __construct(
        /** Hasta donde alcanza quien pregunta. Se aplica **dentro del `WHERE`**. */
        public AccessScope $scope,
        /** Primer dia del periodo, inclusive. */
        public DateTimeImmutable $from,
        /** Ultimo dia del periodo, inclusive. */
        public DateTimeImmutable $to,
        public ?string $employeeUuid = null,
        public ?int $departmentId = null,
        public ?AbsenceType $type = null,
        /**
         * Situacion por la que se filtra. `null` significa **todas**, que es lo
         * que pide `status=all` del contrato; el valor por omision de la API es
         * {@see AbsenceStatus::Active} y lo resuelve el `FormRequest`, no este
         * objeto: un valor por defecto aqui escondería la decision.
         */
        public ?AbsenceStatus $status = null,
    ) {}
}

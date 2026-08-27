<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use DateTimeImmutable;

/**
 * El PIN recien emitido, de camino a la unica respuesta que puede mostrarlo
 * (RF-ID-09).
 *
 * **Existe solo durante la peticion que lo genero.** No se guarda en claro, no
 * se cachea, no viaja a una cola y no entra en ningun evento de dominio: los
 * eventos acaban en `audit_log`, donde consta que hubo emision y no que se
 * emitio (regla dura 21).
 *
 * Es un objeto propio y no una cadena suelta para que el paso por las capas
 * lleve consigo a quien pertenece y cuando se emitio: el acuse que ve quien lo
 * entrega necesita las tres cosas.
 */
final readonly class IssuedPin
{
    public function __construct(
        public string $employeeUuid,
        public string $pin,
        public DateTimeImmutable $issuedAt,
    ) {}
}

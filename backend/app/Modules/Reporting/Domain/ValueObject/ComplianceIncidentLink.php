<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * La incidencia de la bandeja que describe **este mismo hecho** —misma persona,
 * misma jornada, mismo tipo—, cuando existe.
 *
 * Es la demostracion visible de que la vista y la bandeja cuentan lo mismo, y el
 * enlace para ir a resolverla.
 *
 * ## `status` es una cadena y no el enum de `Compliance`
 *
 * `Compliance\Domain\ValueObject\IncidentStatus` vive en otro modulo y
 * `Reporting` no puede importarlo (doc 02 §1.6). Copiar el enum aqui seria peor
 * que la cadena: dos catalogos que hay que acordarse de mantener iguales, y el
 * dia que discreparan la pantalla enseñaria un estado que la bandeja no reconoce.
 * Lo que garantiza que el valor es uno de los tres del contrato es el `CHECK` de
 * `incidents.status`, que es de donde sale.
 */
final readonly class ComplianceIncidentLink
{
    public function __construct(
        public int $id,
        /** `open`, `resolved` o `dismissed`, tal como esta en `incidents.status`. */
        public string $status,
    ) {}
}

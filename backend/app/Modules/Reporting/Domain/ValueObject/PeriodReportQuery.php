<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Lo que se pide al informe por periodo (**RF-IN-01**, RF-IN-02, RF-IN-03).
 *
 * ## Por que vive en `Domain/ValueObject` y no junto al caso de uso
 *
 * Es la pregunta que se le hace al informe, y esta hecha **solo de tipos del
 * dominio**: un rango de jornadas, dos enumerados, un alcance de acceso y dos
 * identificadores. Vive aqui por una razon concreta y verificable: el puerto
 * `Reporting\Application\Port\PeriodReportReader` la recibe —escrito asi y no
 * como `{@see}` porque Pint resolveria la referencia a un `use`, y un `use` de
 * `Application` desde `Domain` es la frontera que Deptrac rechaza—, y un puerto
 * solo puede hablar en tipos del dominio propio, de `Shared` o escalares
 * (ADR-025, restriccion 2). Declararla en
 * `Application/Query/` habria obligado a partir el puerto en seis parametros
 * repetidos tres veces, que es como se acaba teniendo tres firmas que divergen.
 *
 * Es ademas el tipo que la exportacion de la tarea 2.9 reutilizara para pedir
 * exactamente el mismo informe que la pantalla.
 *
 * **El alcance va dentro de la consulta y no al lado.** Es lo que quien llama no
 * puede elegir (RF-ID-03): lo resuelve la capa HTTP a partir del token y viaja
 * junto a los filtros para que no exista ninguna ruta por la que se pueda
 * consultar sin el. Y entra en el `WHERE`, no despues: un total por centro
 * calculado sobre toda la plantilla y servido a quien alcanza un solo
 * departamento seria una fuga por agregacion.
 *
 * **`departmentId` y `employeeUuid` son filtros, no autorizaciones.** Uno fuera
 * del alcance no produce `403`: produce un resultado vacio, igual que en
 * `GET /employees` y en el panel de presencia. Un `403` al filtrar convertiria el
 * desplegable de departamentos en un generador de errores y ademas confirmaria
 * que ese departamento —o esa persona— existe.
 *
 * **El rango ya viene validado** como {@see DateRange}: dos fechas civiles en la
 * zona del centro, no instantes. Es la misma clase que usa el detalle de
 * jornada, para que «del 1 al 31» signifique exactamente lo mismo en las dos
 * pantallas.
 */
final readonly class PeriodReportQuery
{
    public function __construct(
        public AccessScope $scope,
        public DateRange $range,
        public ReportGranularity $granularity,
        public ReportGrouping $grouping,
        public ?int $departmentId,
        /** Identificador **publico** de una persona, para el informe de una sola. */
        public ?string $employeeUuid,
        /**
         * Si los dias con un turno todavia abierto aportan los minutos que ya
         * tienen cerrados.
         *
         * Por omision **no**, y el porque esta en {@see PeriodReportRow}: un
         * tramo sin cerrar vale cero en la proyeccion, asi que ese dia daria una
         * cifra a medias justo en la comparacion contra lo contratado. El dia
         * cuenta igualmente como dia con actividad y sale en `open_shift_days`.
         */
        public bool $includeOpenShifts = false,
    ) {}
}

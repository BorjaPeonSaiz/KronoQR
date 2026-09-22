<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Model\Absence;
use DateTimeImmutable;

/**
 * Una ausencia **con lo que hace falta para pintar su fila** (**RF-GP-04**).
 *
 * ## Por que no basta con el modelo de dominio
 *
 * {@see Absence} habla de la persona por su UUID publico, que es lo correcto: el
 * dominio no tiene por que saber como se llama nadie. Pero la pantalla y el
 * contrato necesitan ademas el codigo de empleado, el nombre y el departamento,
 * y resolverlos fila a fila desde el controlador serian cien consultas en un
 * listado de cien ausencias.
 *
 * Asi que el repositorio los trae en la misma consulta y los entrega juntos.
 * **El modelo de dominio sigue dentro y sin contaminar**: lo que se añade es un
 * envoltorio de lectura, no campos nuevos en la entidad.
 *
 * ## Vive en `Application/Port` y no en `Application/Query`
 *
 * Porque es lo que **atraviesa** el puerto: el repositorio lo devuelve y la
 * consulta lo consume. Es el mismo sitio y el mismo criterio que
 * {@see PinDeliveryRecord} y {@see PinMaterial}, que tambien son tipos de
 * aplicacion que un puerto declara en su firma (ADR-025, restriccion 2).
 *
 * ## `employeeName` no puede bajar mas
 *
 * Llega hasta el `Resource` y para ahi. No entra en `audit_log`, ni en
 * `error_events`, ni en ningun log tecnico (regla dura 21): ahi la persona
 * viaja siempre por su UUID publico.
 */
final readonly class AbsenceView
{
    public function __construct(
        public Absence $absence,
        /** Codigo opaco de la persona, el que va impreso en su tarjeta. */
        public string $employeeCode,
        /** Nombre y apellidos, **solo para pintar la fila**. */
        public string $employeeName,
        /** Departamento al que esta adscrita, o `null`. Es el eje del alcance (RF-ID-03). */
        public ?int $departmentId,
        public ?string $departmentName,
        /**
         * Cuando se escribio **esta version**, en UTC.
         *
         * Vive aqui y no en {@see Absence} a proposito: es un hecho de la fila,
         * no de la ausencia. El modelo de dominio se construye **antes** de
         * existir en ninguna tabla, asi que tener ahi un `createdAt` obligaria a
         * que fuera nulo durante media vida del objeto, o a que el dominio
         * leyera un reloj (regla dura 2).
         */
        public DateTimeImmutable $createdAt,
    ) {}
}

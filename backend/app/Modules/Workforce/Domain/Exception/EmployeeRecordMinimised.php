<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Se ha pedido un contrato, una ausencia, una readmision o la correccion de la
 * fecha de cese de una ficha minimizada por retencion (ADR-060, tarea 11.3 A;
 * RL-11; RN-27, ADR-062 §6 y §7). **Una sola excepcion y un solo `type`,
 * `employee-record-minimised`**, para toda escritura que la ficha minimizada ya
 * no admite; quien vuelve despues de la minimizacion es un alta nueva.
 *
 * La ficha minimizada ya no tiene contratos ni ausencias —la minimizacion los
 * borro— y no vuelve a tenerlos: lo que se registrara ahora seria un dato
 * personal nuevo de alguien cuyo plazo de conservacion ya vencio. **Es un `409`
 * y no un `422`**: el cuerpo es valido, lo que no encaja es el estado de la
 * ficha, y la accion siguiente es releerla (dira `anonymized_at`). El motor lo
 * comprueba otra vez con `employees_guard_anonymized_dependents` y, para los
 * periodos y sus correcciones, con sus guardas propias.
 *
 * Mismo criterio que `SiteTimezoneLocked`: `getMessage()` es tecnico y en
 * ingles —va al log y al historico de errores, con el `employee_uuid` y sin
 * nombre (regla dura 21)—; lo que ve una persona sale de
 * {@see self::$translationKey} y {@see self::$parameters}.
 */
final class EmployeeRecordMinimised extends WorkforceConflict implements BusinessRejection
{
    public const string TRANSLATION_KEY = 'employees.errors.record_minimised';

    /**
     * @param  array<string, string>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  string  $anonymizedOn  Fecha de la minimizacion, `Y-m-d`
     */
    public static function withUuid(string $uuid, string $anonymizedOn): self
    {
        return new self(
            self::TRANSLATION_KEY,
            ['anonymized_on' => $anonymizedOn],
            sprintf(
                'The personnel record %s was minimised by retention on %s (ADR-060): it takes no new contracts, absences, rehiring nor termination date corrections.',
                $uuid,
                $anonymizedOn,
            ),
        );
    }
}

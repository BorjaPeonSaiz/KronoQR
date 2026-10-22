<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * Se ha pedido un contrato o una ausencia para una ficha minimizada por
 * retencion (ADR-060, tarea 11.3 A; RL-11).
 *
 * La ficha minimizada ya no tiene contratos ni ausencias —la minimizacion los
 * borro— y no vuelve a tenerlos: lo que se registrara ahora seria un dato
 * personal nuevo de alguien cuyo plazo de conservacion ya vencio. **Es un `409`
 * y no un `422`**: el cuerpo es valido, lo que no encaja es el estado de la
 * ficha, y la accion siguiente es releerla (dira `anonymized_at`). El motor lo
 * comprueba otra vez con `employees_guard_anonymized_dependents`.
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
                'The personnel record %s was minimised by retention on %s (ADR-060): it takes no new contracts or absences.',
                $uuid,
                $anonymizedOn,
            ),
        );
    }
}

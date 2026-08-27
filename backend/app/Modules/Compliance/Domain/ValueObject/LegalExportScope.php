<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * A quien alcanza la exportacion: a toda la plantilla o a una sola persona
 * (RF-IN-05).
 *
 * **Por que es un objeto y no un `?string`.** El alcance viaja a tres sitios que
 * no pueden discrepar: la consulta, la cabecera de criterios del fichero y el
 * asiento de `audit_log`. Con un `?string` nulo, cada uno de los tres tendria
 * que acordarse por su cuenta de que «nulo significa toda la plantilla», y el
 * dia que uno lo interprete al reves el fichero dira que exporto a una persona
 * habiendo exportado a seiscientas.
 *
 * **El identificador es el UUID publico, nunca el codigo interno ni un nombre.**
 * Este valor acaba en `audit_log`, que se conserva cuatro años y se lee en una
 * inspeccion: un `employee_uuid` basta para reconstruir el alcance y no
 * convierte la tabla en un directorio de plantilla (regla dura 21).
 */
final readonly class LegalExportScope
{
    private function __construct(public ?string $employeeUuid) {}

    /** La plantilla completa: lo que pide un requerimiento general. */
    public static function everyone(): self
    {
        return new self(null);
    }

    public static function employee(string $employeeUuid): self
    {
        return new self($employeeUuid);
    }

    public function isEveryone(): bool
    {
        return $this->employeeUuid === null;
    }

    /**
     * Etiqueta de la metrica `legal_exports_total{scope}` (doc 02 §8.2).
     *
     * Dos valores y nunca el identificador: una etiqueta de Prometheus con un
     * UUID dentro crea una serie temporal por persona exportada, que es a la vez
     * una fuga de datos hacia el sistema de metricas y una explosion de
     * cardinalidad.
     */
    public function metricLabel(): string
    {
        return $this->employeeUuid === null ? 'all' : 'employee';
    }
}

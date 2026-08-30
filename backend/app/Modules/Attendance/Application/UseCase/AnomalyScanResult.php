<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

/**
 * Lo que encontro una pasada de la revision diaria (RF-PR-01).
 *
 * **Son recuentos, nunca personas** (regla dura 21): lo escribe el comando en su
 * salida y en el log de la ejecucion, y ahi no puede aparecer el nombre de
 * nadie. Quien necesita el detalle lo tiene en la bandeja, que se lee con
 * autorizacion.
 *
 * `$byType` cuenta **hallazgos emitidos**, no incidencias creadas: la
 * deduplicacion contra lo que ya estaba abierto ocurre en `Compliance`, detras
 * del indice unico parcial de `incidents`. Un segundo pase el mismo dia vuelve a
 * contar los mismos hallazgos y **no** crea nada nuevo, que es justo lo que hace
 * idempotente al comando.
 */
final readonly class AnomalyScanResult
{
    /**
     * @param  array<string, int>  $byType  Hallazgos por valor de `incidents.type`.
     */
    private function __construct(
        public bool $ranOverASite,
        public int $daysInspected,
        public int $workDaysInspected,
        public array $byType,
    ) {}

    /**
     * @param  array<string, int>  $byType
     */
    public static function of(int $daysInspected, int $workDaysInspected, array $byType): self
    {
        return new self(true, $daysInspected, $workDaysInspected, $byType);
    }

    /**
     * Antes de la puesta en marcha no hay centro (RF-PD-03) y por tanto no hay
     * zona horaria con la que resolver una jornada (RN-05). No es un error: es
     * una instalacion recien instalada, y un planificador que fallara cada noche
     * por eso llenaria el log de quien todavia no ha configurado nada.
     */
    public static function withoutSite(): self
    {
        return new self(false, 0, 0, []);
    }

    public function total(): int
    {
        return array_sum($this->byType);
    }
}

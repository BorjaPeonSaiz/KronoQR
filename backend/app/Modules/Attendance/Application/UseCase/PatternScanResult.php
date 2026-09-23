<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

/**
 * Lo que encontro una pasada de la deteccion de patrones (RF-PR-06).
 *
 * **Son recuentos, nunca personas** (regla dura 21): esto es lo que el comando
 * imprime y lo que deja en el log. Un indicio de posible prestamo de credencial
 * habla de dos personas concretas, y el sitio donde eso se mira es la bandeja,
 * que se lee con autorizacion.
 *
 * `$byPattern` cuenta **hallazgos emitidos**, no incidencias creadas: la
 * deduplicacion contra lo que ya estaba abierto ocurre en `Compliance`, detras
 * de la restriccion `one_incident_per_finding`. Un segundo pase la misma noche
 * vuelve a contar los mismos hallazgos y **no** crea nada nuevo, que es justo lo
 * que hace idempotente al comando.
 */
final readonly class PatternScanResult
{
    /**
     * @param  array<string, int>  $byPattern  `kiosk_coincidence` e `impossible_sequence` -> cuantos.
     */
    private function __construct(
        public bool $ranOverASite,
        public int $daysInspected,
        public int $scansInspected,
        public array $byPattern,
        /**
         * Hallazgos que **no se pudieron abrir**: la incidencia fallo al
         * escribirse y el resto de la pasada continuo.
         *
         * Se cuenta y no se traga: el comando termina con codigo distinto de
         * cero, pero lo que si se abrio queda abierto. La cifra se publica como
         * `pattern_detection_last_failures` y la vigila
         * `DeteccionDePatronesConFallos`.
         */
        public int $failures,
    ) {}

    /**
     * @param  array<string, int>  $byPattern
     */
    public static function of(int $daysInspected, int $scansInspected, array $byPattern, int $failures = 0): self
    {
        return new self(true, $daysInspected, $scansInspected, $byPattern, $failures);
    }

    /**
     * Antes de la puesta en marcha no hay centro (RF-PD-03) y por tanto no hay
     * zona horaria con la que decidir a que dia civil pertenece un escaneo. No
     * es un error: es una instalacion recien instalada.
     */
    public static function withoutSite(): self
    {
        return new self(false, 0, 0, [], 0);
    }

    public function total(): int
    {
        return array_sum($this->byPattern);
    }
}

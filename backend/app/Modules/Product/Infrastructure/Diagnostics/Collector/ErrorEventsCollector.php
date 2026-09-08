<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;

/**
 * Seccion `error_events`: **todavia no existe**, y el paquete lo dice
 * (RF-PD-15, tarea 5.12).
 *
 * ## Por que no es `[]`
 *
 * Porque un paquete que devolviera una lista vacia estaria **afirmando que no ha
 * habido errores**, y eso seria falso: lo que ocurre es que la tabla que los
 * guarda no se ha creado todavia. Soporte leeria «cero errores» y descartaria la
 * hipotesis correcta.
 *
 * Es el mismo criterio con el que `RetentionTally::unavailable()` distingue «no
 * hay filas» de «no se pudo mirar». La distincion cuesta tres lineas y ahorra
 * una incidencia mal diagnosticada.
 *
 * Cuando la tarea 5.12 cree la tabla, esta clase se sustituye por la que la lee;
 * el nombre de la seccion y su sitio en el contrato no cambian.
 */
final readonly class ErrorEventsCollector implements DiagnosticsCollector
{
    public function section(): string
    {
        return 'error_events';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        return [
            'status' => 'not_installed',
            'requirement' => 'RF-PD-15',
            'note' => 'El historico de errores llega con la tarea 5.12. Esta version no lo almacena, '
                .'asi que este paquete no puede afirmar nada sobre los errores ocurridos.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\AuditInstantIsNotUtc;
use DateTimeImmutable;

/**
 * Lo que el fichero exportado **dice de si mismo**: instalacion, momento,
 * periodo y alcance (RF-IN-05, plan 1.17 paso 1).
 *
 * ## Por que los criterios van dentro del fichero
 *
 * Un documento que se entrega a la Inspeccion tiene que poder contrastarse sin
 * volver a preguntar al sistema que lo genero. Si los criterios de inclusion
 * viven solo en la pantalla desde la que se pulso el boton, el CSV que llega al
 * expediente es una tabla de horas sin contexto: no dice de que periodo es, ni a
 * quien alcanza, ni que tramos deja fuera. Con la cabecera dentro, el fichero se
 * sostiene solo el dia que alguien lo abra dos años despues.
 *
 * ## El momento se guarda en UTC y se escribe diciendolo
 *
 * Regla dura 3. Una instalacion puede tener centros en husos distintos —Madrid y
 * Canarias son el mismo pais y una hora de diferencia—, asi que no hay «la zona
 * de la instalacion» que poner aqui. Cada fila lleva la de su centro; la
 * cabecera lleva UTC y lo dice.
 *
 * **Aqui no esta el nombre de la instalacion**, y no es un olvido: sale de
 * `config/branding.php` (RF-PD-08, regla dura 13) y lo lee el escritor del
 * fichero, que es infraestructura. Un objeto de dominio que llevara dentro un
 * valor de configuracion obligaria a arrastrarlo por toda la cadena para acabar
 * imprimiendolo en una linea.
 */
final readonly class LegalExportManifest
{
    public function __construct(
        public DateTimeImmutable $generatedAt,
        public LegalExportPeriod $period,
        public LegalExportScope $scope,
    ) {
        if ($generatedAt->getOffset() !== 0) {
            throw AuditInstantIsNotUtc::forField('generated_at', $generatedAt);
        }
    }

    /**
     * Nombre del fichero. **Nunca lleva el nombre de una persona**: un adjunto
     * llamado «registro-Lucia-Fernandez.csv» divulga a quien se esta
     * inspeccionando con solo mirar la bandeja de entrada (regla dura 21).
     */
    public function filename(): string
    {
        return 'registro-horario-'.$this->period->slug().'.csv';
    }
}

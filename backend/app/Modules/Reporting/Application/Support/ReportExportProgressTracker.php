<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

use App\Modules\Reporting\Application\Port\ReportExportProgressBoard;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Domain\Exception\ReportExportWasCancelled;
use App\Modules\Reporting\Domain\ValueObject\ReportExportProgress;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStage;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Shared\Application\Port\Clock;

/**
 * El avance de **una** generacion en diferido, y su punto de cancelacion
 * (**V5-PA-4**).
 *
 * ## Dos cosas en cada llamada, y en este orden
 *
 * 1. **Comprueba que la fila sigue `running`.** Si alguien la cancelo, lanza
 *    {@see ReportExportWasCancelled} y la generacion se detiene ahi: el caso de
 *    uso borra el fichero a medias y no publica nada.
 * 2. **Anota el avance** en el tablero, que se traga sus propios fallos.
 *
 * Primero la comprobacion porque es la que importa: anotar «escribiendo, 40 %»
 * de una exportacion que ya esta cancelada enseñaria una barra viva en una fila
 * muerta.
 *
 * ## Cada cuanto
 *
 * En cada cambio de fase siempre, y dentro de una fase con filas, cuando el
 * escritor avisa —cada 500 filas en la exportacion legal—. La comprobacion es
 * una lectura de una columna por clave unica: con 500 filas entre una y otra,
 * su coste no se nota en el recorrido.
 *
 * ## Uno por generacion, y con estado
 *
 * No es un servicio del contenedor: lo construye el caso de uso para cada
 * exportacion, con su `uuid`. Por eso no es `readonly`: recuerda la fase en la
 * que esta para que la exportacion legal pueda pasar de «leyendo» a
 * «escribiendo» en su primera fila sin que nadie se lo diga.
 */
final class ReportExportProgressTracker
{
    private ?ReportExportStage $stage = null;

    public function __construct(
        private readonly string $uuid,
        private readonly ReportExportRepository $exports,
        private readonly ReportExportProgressBoard $board,
        private readonly Clock $clock,
    ) {}

    /** Entra en una fase sin porcentaje: la barra indeterminada. */
    public function enter(ReportExportStage $stage): void
    {
        $this->assertStillRunning();

        $this->stage = $stage;
        $this->board->record($this->uuid, ReportExportProgress::at($stage, $this->clock->now()));
    }

    /**
     * Filas hechas de un total conocido, dentro de la fase de escritura.
     *
     * La primera llamada —cero filas, antes de recorrer— todavia es lectura:
     * el cursor esta ordenando la consulta. A partir de la primera fila escrita
     * la fase pasa a {@see ReportExportStage::Writing}.
     */
    public function rows(int $done, int $total): void
    {
        $this->assertStillRunning();

        $stage = $done === 0 ? ($this->stage ?? ReportExportStage::Reading) : ReportExportStage::Writing;
        $this->stage = $stage;

        $this->board->record($this->uuid, ReportExportProgress::ofRows($stage, $done, $total, $this->clock->now()));
    }

    /** Termino —bien, mal o cancelada—: el avance ya no describe nada. */
    public function finish(): void
    {
        $this->board->forget($this->uuid);
    }

    /**
     * @throws ReportExportWasCancelled si la fila ya no esta `running`
     */
    public function assertStillRunning(): void
    {
        if ($this->exports->statusOf($this->uuid) !== ReportExportStatus::Running) {
            throw ReportExportWasCancelled::during($this->uuid);
        }
    }
}

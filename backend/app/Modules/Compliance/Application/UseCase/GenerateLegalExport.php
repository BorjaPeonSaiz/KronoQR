<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\GenerateLegalExportCommand;
use App\Modules\Compliance\Application\Port\LegalExportAudit;
use App\Modules\Compliance\Application\Port\LegalExportMetrics;
use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Application\Port\LegalExportWriter;
use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * **Genera la exportacion normalizada para la Inspeccion de Trabajo**
 * (RF-IN-05, RL-03, RL-06, art. 34.9 ET).
 *
 * ## Los cuatro pasos
 *
 * ```
 * 1. Abrir transaccion de solo lectura sobre el registro
 * 2. Recorrer el origen y escribir el fichero, fila a fila
 * 3. Escribir el asiento de audit_log con periodo, alcance y recuento
 * 4. Devolver el fichero ya cerrado
 * ```
 *
 * ## Por que hay una transaccion en algo que solo lee
 *
 * Por dos motivos, y ninguno es la costumbre.
 *
 * El primero: **la lectura tiene que ser un instante**. Un mes de una plantilla
 * grande tarda segundos en escribirse, y en esos segundos alguien puede estar
 * corrigiendo una jornada desde el panel. Sin transaccion, el fichero que se
 * entrega podria contener el tramo antiguo en una fila y la correccion que lo
 * sustituyo en otra: dos verdades incompatibles en el mismo documento legal. Con
 * una transaccion, lo exportado es el registro tal y como estaba en un momento
 * concreto, que es lo que el manifiesto afirma.
 *
 * El segundo: **el asiento entra en la misma unidad de trabajo**. Si la
 * escritura de `audit_log` fallara, la exportacion no se da por hecha (regla
 * dura 6, ADR-027). El fichero se habra escrito en disco, pero quien llamo
 * recibe el error y no lo entrega: una descarga del registro horario de la
 * plantilla que no deje traza es exactamente lo que RS-05 prohibe.
 *
 * ## Lo que NO hace
 *
 * - **No autoriza.** Quien puede exportar lo decide `LegalExportPolicy` y el
 *   ambito del token lo comprueba el middleware (regla dura 18, doc 02 §7.3).
 * - **No pregunta la hora.** El momento de generacion llega por el puerto
 *   `Clock` (regla dura 2, ADR-021): sin eso, la cabecera del fichero no se
 *   podria fijar en una prueba.
 * - **No elige el formato.** Eso es del escritor, y el requisito que lo gobierna
 *   es RL-06.
 * - **No decide quien firma.** El actor sale de la peticion en curso, dentro del
 *   adaptador de auditoria: quien exporta no puede declarar quien es.
 */
final readonly class GenerateLegalExport
{
    public function __construct(
        private ConnectionInterface $connection,
        private LegalExportSource $source,
        private LegalExportWriter $writer,
        private LegalExportAudit $audit,
        private LegalExportMetrics $metrics,
        private Clock $clock,
    ) {}

    public function handle(GenerateLegalExportCommand $command): LegalExport
    {
        $manifest = new LegalExportManifest(
            generatedAt: $this->clock->now(),
            period: $command->period,
            scope: $command->scope,
        );

        $export = $this->connection->transaction(
            fn (): LegalExport => $this->generate($command, $manifest),
        );

        // Fuera de la transaccion a proposito: el fichero esta escrito y el
        // asiento confirmado, asi que un fallo de Redis no puede convertir una
        // exportacion valida en un error que invite a repetirla.
        $this->metrics->exportGenerated($manifest->scope->metricLabel());

        return $export;
    }

    private function generate(GenerateLegalExportCommand $command, LegalExportManifest $manifest): LegalExport
    {
        $tally = $this->writer->write(
            $manifest,
            $command->destinationPath,
            $this->source->records($command->period, $command->scope),
        );

        // Lo ultimo antes de confirmar, como en las correcciones: el recuento
        // que se apunta es el de las filas que de verdad se escribieron.
        $this->audit->recordGeneration($manifest, $tally);

        return new LegalExport($manifest, $command->destinationPath, $tally);
    }
}

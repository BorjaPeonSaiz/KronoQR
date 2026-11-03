<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\GenerateLegalExportCommand;
use App\Modules\Compliance\Application\Exception\LegalExportSignerNotAllowed;
use App\Modules\Compliance\Application\Port\LegalExportAudit;
use App\Modules\Compliance\Application\Port\LegalExportMetrics;
use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Application\Port\LegalExportWriter;
use App\Modules\Compliance\Application\Support\LegalExporters;
use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
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
 * - **No decide quien firma.** Por el panel, el actor sale de la peticion en
 *   curso, dentro del adaptador de auditoria: quien exporta no puede declarar
 *   quien es. Por consola firma la cuenta de `--responsible` que la persona ha
 *   confirmado (doc 01 §7.2), y aqui solo se comprueba, con la cadena tomada,
 *   que sigue pudiendo firmar.
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
        private ResponsibleAccounts $accounts,
        private SerializedLedgerWrite $serialized,
    ) {}

    /**
     * @throws LegalExportSignerNotAllowed por consola, la cuenta que firma ya no puede hacerlo
     */
    public function handle(GenerateLegalExportCommand $command): LegalExport
    {
        return UseCaseInstrumentation::run('compliance.generate_legal_export', fn (): LegalExport => $this->doHandle($command));
    }

    private function doHandle(GenerateLegalExportCommand $command): LegalExport
    {
        // Por consola, antes de leer nada: una cuenta que ya no puede firmar no
        // escribe ningun fichero. La comprobacion que cuenta es la de despues,
        // con la cadena tomada.
        if ($command->signedByUserId !== null && ! $this->canSign($command->signedByUserId)) {
            throw LegalExportSignerNotAllowed::beforeWriting();
        }

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
        if ($command->signedByUserId === null) {
            $this->audit->recordGeneration($manifest, $tally);

            return new LegalExport($manifest, $command->destinationPath, $tally);
        }

        $signer = $command->signedByUserId;

        // Por consola, la cuenta que firma se vuelve a comprobar con la cadena
        // de `audit_log` tomada, y el asiento se escribe con ella en la mano: la
        // baja de una cuenta toma la cadena antes de tocar su fila (ADR-051 §6),
        // asi que una baja durante la escritura no deja una exportacion firmada
        // por quien ya no responde. La cadena se toma aqui y no al empezar: con
        // ella se para todo lo que audita, y la escritura recorre el periodo
        // entero (ADR-046). El candado dura hasta que confirma la transaccion
        // de fuera, la misma que el asiento.
        $this->serialized->withChainLock(function () use ($manifest, $tally, $signer): void {
            if (! $this->canSign($signer)) {
                throw LegalExportSignerNotAllowed::afterWriting();
            }

            $this->audit->recordGeneration($manifest, $tally, $signer);
        });

        return new LegalExport($manifest, $command->destinationPath, $tally);
    }

    private function canSign(int $userId): bool
    {
        return $this->accounts->isActiveManagementAccount($userId, LegalExporters::ROLES);
    }
}

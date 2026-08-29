<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\PrintCredentialBatchCommand;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyPrinted;
use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Shared\Application\Port\EmployeeCardDirectory;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;

/**
 * Imprimir en **una sola hoja A4** todas las credenciales pendientes de la
 * instalacion (RF-QR-04, ADR-040).
 *
 * El doc 02 §5.5 explica por que existe: *«La hoja A4 con varias tarjetas por
 * pagina es lo que hace viable dar de alta a 40 personas de temporada en una
 * tarde.»*
 *
 * **Un solo documento con N tarjetas, no N documentos.** Es una sola invocacion
 * del renderizador —un Chromium, no sesenta— y una sola respuesta que imprimir de
 * una tirada.
 *
 * **`--pending` es la unica seleccion, no un filtro.** Se piden al repositorio las
 * credenciales activas sin `printed_at` y nada mas. No existe ninguna bandera que
 * incluya las ya impresas, porque no existe la reimpresion (ADR-034), y de ahi
 * sale la idempotencia del lote: **la segunda pasada no encuentra nada
 * pendiente**. Es lo que impide que dos ejecuciones del mismo lote produzcan dos
 * juegos de tarjetas con QR distinto de los que solo el ultimo vale.
 *
 * **Todo o nada.** Si entre la seleccion y la escritura alguien imprime una de
 * ellas, la transaccion se deshace entera y no sale ninguna tarjeta. Un lote a
 * medias es peor que ninguno.
 *
 * **El orden de la hoja es el del directorio de empleados**: departamento y
 * apellido. Quien recorta las tarjetas las reparte en ese orden, y dos
 * ejecuciones del mismo lote tienen que producir la misma hoja.
 */
final readonly class PrintCredentialBatch
{
    public function __construct(
        private CredentialRepository $credentials,
        private EmployeeCardDirectory $directory,
        private MintCards $mint,
        private CredentialTelemetry $telemetry,
    ) {}

    /**
     * @throws CredentialAlreadyPrinted si alguna se imprime en paralelo: `409` y no sale ninguna
     */
    public function handle(PrintCredentialBatchCommand $command): PrintedCards
    {
        $profiles = $this->directory->activeProfiles();

        $byEmployeeId = [];
        $employeeIds = [];

        foreach ($profiles as $profile) {
            $byEmployeeId[$profile->employeeId] = $profile;
            $employeeIds[] = $profile->employeeId;
        }

        $pending = $this->credentials->pendingPrintForEmployees($employeeIds);

        $targets = MintCards::pair($pending, $byEmployeeId);

        return $this->telemetry->measure(
            'identity.credential_print_batch',
            [
                'cards' => \count($targets),
                'batch' => true,
            ],
            fn (): PrintedCards => $this->mint->mint(
                targets: $targets,
                format: CardFormat::SHEET,
                batch: true,
                actorUserId: $command->actorUserId,
            ),
        );
    }

    /**
     * Cuantas tarjetas saldrian del lote, **sin imprimir nada**.
     *
     * Lo usan el comando de consola y el panel para poder decir «no hay nada
     * pendiente» antes de arrancar un Chromium para un documento vacio.
     *
     * @return list<EmployeeCardProfile> Los titulares de las credenciales pendientes.
     */
    public function preview(): array
    {
        $profiles = $this->directory->activeProfiles();

        $byEmployeeId = [];
        $employeeIds = [];

        foreach ($profiles as $profile) {
            $byEmployeeId[$profile->employeeId] = $profile;
            $employeeIds[] = $profile->employeeId;
        }

        return array_map(
            static fn (CardToMint $target): EmployeeCardProfile => $target->holder,
            MintCards::pair($this->credentials->pendingPrintForEmployees($employeeIds), $byEmployeeId),
        );
    }
}

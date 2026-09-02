<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\ImportEmployeesCommand;
use App\Modules\Workforce\Domain\Exception\ImportFileChanged;
use App\Modules\Workforce\Domain\Exception\ImportTooLarge;
use App\Modules\Workforce\Domain\Exception\UnreadableImportFile;
use App\Modules\Workforce\Domain\ValueObject\ImportReport;

/**
 * Las dos fases de la importacion masiva de plantilla, en orden (**RF-GP-05**).
 *
 * ## Siempre se planifica; aplicar es lo opcional
 *
 * Incluso en `mode: apply` se vuelve a leer y a decidir el fichero entero. No es
 * trabajo de mas: es lo que permite **no guardar el fichero en el servidor**
 * entre las dos fases —un almacen temporal con nombres y documentos de identidad
 * de la plantilla es superficie de datos personales en reposo, con su borrado,
 * su cuota y su fuga— y es lo que hace que la comparacion de huellas signifique
 * algo.
 *
 * ## La confirmacion es de un fichero concreto
 *
 * `confirm_checksum` tiene que ser el `sha256` que devolvio la validacion. Si no
 * coincide, `409` y **no se escribe nada**: quien reviso un informe de «38 altas
 * y 2 rechazos», corrigio el fichero y lo volvio a subir estaria aplicando a
 * ciegas un contenido que nadie ha revisado.
 *
 * ## Un fichero truncado no se aplica
 *
 * Se parte y se importa por trozos. Aplicar la mitad de una plantilla es
 * exactamente el fallo que nadie detecta hasta que falta gente a las 06:00.
 */
final readonly class ImportEmployeesHandler
{
    public function __construct(
        private PlanEmployeeImport $plan,
        private ApplyEmployeeImport $apply,
    ) {}

    /**
     * @param  array<string, list<string>>  $columnAliases
     *
     * @throws UnreadableImportFile si el fichero no se puede leer
     * @throws ImportFileChanged si se manda aplicar otro fichero distinto del validado
     * @throws ImportTooLarge si el fichero venia truncado: se parte y se importa por trozos
     */
    public function handle(ImportEmployeesCommand $command, int $maxRows, array $columnAliases): ImportReport
    {
        $report = $this->plan->handle($command->path, $maxRows, $columnAliases);

        if (! $command->apply) {
            return $report;
        }

        // `hash_equals` y no `!==`: comparar huellas en tiempo constante no
        // protege de nada aqui —la huella no es un secreto y el endpoint exige
        // sesion de RRHH— pero es la forma correcta de comparar un digest, y
        // dejarla escrita evita que alguien copie el patron contrario a un sitio
        // donde si importe.
        if ($command->confirmChecksum === null || ! hash_equals($report->sha256, $command->confirmChecksum)) {
            throw ImportFileChanged::make();
        }

        if (! $report->isApplicable()) {
            // La confirmacion era buena y aun asi no se aplica: el fichero venia
            // truncado, y aplicar la mitad de una plantilla es el fallo que nadie
            // detecta hasta que falta gente a las 06:00.
            throw new ImportTooLarge($maxRows);
        }

        return $this->apply->handle($report);
    }
}

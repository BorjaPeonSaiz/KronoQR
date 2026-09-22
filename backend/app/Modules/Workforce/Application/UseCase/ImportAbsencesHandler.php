<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\ImportAbsencesCommand;
use App\Modules\Workforce\Domain\Exception\ImportFileChanged;
use App\Modules\Workforce\Domain\Exception\ImportTooLarge;
use App\Modules\Workforce\Domain\Exception\UnreadableImportFile;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportReport;

/**
 * Las dos fases de la carga de ausencias, en orden (**RF-GP-04**).
 *
 * Calcado de {@see ImportEmployeesHandler}, incluidas sus tres decisiones, que
 * estan explicadas alli y valen igual aqui:
 *
 * 1. **Siempre se planifica; aplicar es lo opcional.** Incluso en `mode: apply`
 *    se vuelve a leer y a decidir el fichero entero. No es trabajo de mas: es lo
 *    que permite **no guardar el fichero en el servidor** entre las dos fases y
 *    lo que hace que la comparacion de huellas signifique algo.
 * 2. **La confirmacion es de un fichero concreto.** Si `confirm_checksum` no
 *    coincide, `409` y no se escribe nada: quien reviso un informe, corrigio el
 *    fichero y lo volvio a subir estaria aplicando a ciegas un contenido que
 *    nadie ha revisado.
 * 3. **Un fichero truncado no se aplica.** Medio cuadrante de vacaciones es el
 *    fallo que nadie detecta hasta que alguien no aparece en el informe del mes.
 *
 * **Reutiliza las excepciones de aquella y no define las suyas**, y es
 * deliberado: `ImportFileChanged` y `ImportTooLarge` describen el mecanismo de
 * las dos fases, que es del producto y no de la plantilla. Duplicarlas habria
 * obligado a duplicar tambien sus dos lineas de `bootstrap/app.php` y sus dos
 * traducciones, con el riesgo de que una de las copias dijera otra cosa.
 */
final readonly class ImportAbsencesHandler
{
    public function __construct(
        private PlanAbsenceImport $plan,
        private ApplyAbsenceImport $apply,
    ) {}

    /**
     * @param  array<string, list<string>>  $columnAliases
     *
     * @throws UnreadableImportFile si el fichero no se puede leer
     * @throws ImportFileChanged si se manda aplicar otro fichero distinto del validado
     * @throws ImportTooLarge si el fichero venia truncado: se parte y se importa por trozos
     */
    public function handle(
        ImportAbsencesCommand $command,
        int $maxRows,
        array $columnAliases,
    ): AbsenceImportReport {
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
            throw new ImportTooLarge($maxRows);
        }

        return $this->apply->handle($report, $command->importedByUserId);
    }
}

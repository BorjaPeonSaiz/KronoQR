<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\UseCase\ImportAbsencesHandler;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportOutcome;
use App\Modules\Workforce\Http\Request\ImportAbsencesRequest;
use App\Modules\Workforce\Http\Resource\AbsenceImportResource;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

/**
 * `POST /api/v1/absences/import` — carga de ausencias por fichero
 * (**RF-GP-04**).
 *
 * Delgado como el resto: valida, invoca y serializa. **Ninguna decision vive
 * aqui.** Que se crea, que queda igual y que se rechaza lo decide
 * `PlanAbsenceImport`; la escritura, `ApplyAbsenceImport`; los asientos, el
 * listener de `Compliance`.
 *
 * ## Los dos limites y el mapa de columnas salen de la configuracion
 *
 * Se leen aqui y se pasan al caso de uso ya resueltos, exactamente igual que los
 * umbrales legales llegan al dominio ya resueltos (regla dura 14). El caso de
 * uso no consulta configuracion.
 *
 * ## `200` tambien con lineas rechazadas
 *
 * El informe **es** el resultado del endpoint, no un error de la peticion: quien
 * lo recibe tiene que poder verlo entero para corregir el fichero. Lo que no es
 * `200` es un fichero ilegible (`422`), uno truncado que se manda aplicar
 * (`422`) o una confirmacion que no cuadra (`409`).
 */
final class AbsenceImportController extends Controller
{
    public function __invoke(
        ImportAbsencesRequest $request,
        ImportAbsencesHandler $handler,
        LoggerInterface $logger,
    ): JsonResponse {
        $command = $request->toCommand();

        $report = $handler->handle(
            $command,
            max(1, config()->integer('workforce.import.max_rows')),
            self::columnAliases(),
        );

        // SOLO CIFRAS Y LA HUELLA DEL FICHERO (regla dura 21). Ni un codigo de
        // empleado, ni un tipo de ausencia —una baja medica es dato de salud—,
        // ni una nota, ni el nombre del fichero, que lo pone quien sube y puede
        // llevar dentro el nombre de una persona. Esta linea es lo que permite
        // responder «¿cuando se cargo el cuadrante y cuantas ausencias
        // entraron?» desde el paquete de diagnostico, que va anonimizado por
        // defecto (ADR-020).
        $logger->info('workforce.absences_imported', [
            'mode' => $command->apply ? 'apply' : 'validate',
            'file_sha256' => $report->sha256,
            'rows' => $report->rowCount(),
            'create' => $report->countOf(AbsenceImportOutcome::CREATE),
            'unchanged' => $report->countOf(AbsenceImportOutcome::UNCHANGED),
            'reject' => $report->countOf(AbsenceImportOutcome::REJECT),
            'truncated' => $report->truncated,
        ]);

        return (new AbsenceImportResource($report, $command->apply))->response();
    }

    /**
     * Los alias de serie mas los que haya añadido el cliente en su `.env`.
     *
     * **Se suman, no sustituyen** (regla dura 13), con el mismo formato
     * `campo=cabecera` separado por `;` que la carga de plantilla. Una entrada
     * mal escrita se ignora en silencio: lo peor que puede pasar es que su
     * columna salga como «no reconocida» en el informe, y fallar al arrancar por
     * una coma en el `.env` dejaria al cliente sin poder cargar nada.
     *
     * **Mapa propio y no el de la plantilla**: las columnas son otras —`tipo`,
     * `desde`, `hasta`— y compartirlo significaria que un alias de `nombre`
     * apareciera como columna reconocida en un fichero de ausencias.
     *
     * @return array<string, list<string>>
     */
    private static function columnAliases(): array
    {
        /** @var array<string, list<string>> $aliases */
        $aliases = config()->array('workforce.absence_import.column_aliases');

        foreach (explode(';', config()->string('workforce.absence_import.extra_column_aliases')) as $entry) {
            $parts = explode('=', $entry, 2);

            if (\count($parts) !== 2) {
                continue;
            }

            $field = trim($parts[0]);
            $header = trim($parts[1]);

            // Solo campos que el importador conoce: un alias para `motivo` no
            // haria nada y dejaria a quien lo escribio esperando que hiciera algo.
            if ($header !== '' && isset($aliases[$field])) {
                $aliases[$field][] = $header;
            }
        }

        return $aliases;
    }
}

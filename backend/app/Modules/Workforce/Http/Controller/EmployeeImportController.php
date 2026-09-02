<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Application\UseCase\ImportEmployeesHandler;
use App\Modules\Workforce\Domain\ValueObject\ImportOutcome;
use App\Modules\Workforce\Http\Request\ImportEmployeesRequest;
use App\Modules\Workforce\Http\Resource\EmployeeImportResource;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

/**
 * `POST /api/v1/employees/import` — importacion masiva de plantilla
 * (**RF-GP-05**).
 *
 * Delgado como el resto: valida, invoca y serializa. **Ninguna decision vive
 * aqui.** Que se crea, que se actualiza y que se rechaza lo decide
 * `PlanEmployeeImport`; la escritura, `ApplyEmployeeImport`; el asiento, el
 * listener de `Compliance`.
 *
 * ## Los dos limites que este controlador aporta salen de la configuracion
 *
 * `max_rows` y el mapa de columnas se leen aqui y se pasan al caso de uso ya
 * resueltos, exactamente igual que los umbrales legales llegan al dominio ya
 * resueltos (regla dura 14). El caso de uso no consulta configuracion.
 *
 * ## `200` tambien con lineas rechazadas
 *
 * El informe **es** el resultado del endpoint, no un error de la peticion: quien
 * lo recibe tiene que poder verlo entero para corregir el fichero. Lo que no es
 * `200` es un fichero ilegible (`422`), uno truncado que se manda aplicar
 * (`422`) o una confirmacion que no cuadra (`409`).
 */
final class EmployeeImportController extends Controller
{
    public function __invoke(
        ImportEmployeesRequest $request,
        ImportEmployeesHandler $handler,
        LoggerInterface $logger,
    ): JsonResponse {
        $command = $request->toCommand();

        $report = $handler->handle(
            $command,
            max(1, config()->integer('workforce.import.max_rows')),
            self::columnAliases(),
        );

        // SOLO CIFRAS Y LA HUELLA DEL FICHERO (regla dura 21). Ni un nombre, ni
        // un correo, ni un documento, ni el nombre del fichero —que lo pone
        // quien sube y puede llevar dentro el de una persona—. Esta linea es lo
        // que permite responder «¿cuando se cargo la plantilla y cuanta gente
        // entro?» desde el paquete de diagnostico, que va anonimizado por
        // defecto (ADR-020).
        $logger->info('workforce.employees_imported', [
            'mode' => $command->apply ? 'apply' : 'validate',
            'file_sha256' => $report->sha256,
            'rows' => $report->rowCount(),
            'create' => $report->countOf(ImportOutcome::CREATE),
            'update' => $report->countOf(ImportOutcome::UPDATE),
            'unchanged' => $report->countOf(ImportOutcome::UNCHANGED),
            'reject' => $report->countOf(ImportOutcome::REJECT),
            'truncated' => $report->truncated,
        ]);

        return (new EmployeeImportResource($report, $command->apply))->response();
    }

    /**
     * Los alias de serie mas los que haya anadido el cliente en su `.env`.
     *
     * **Se suman, no sustituyen** (regla dura 13): un alias propio no apaga los
     * estandar, porque el fichero de la semana que viene puede venir del otro
     * sistema. El formato es `campo=cabecera` separado por `;`, que es lo que se
     * puede escribir comodamente en un `.env` — un JSON ahi obliga a pelearse
     * con las comillas y es la clase de detalle que hace que nadie use el
     * parametro.
     *
     * Una entrada mal escrita **se ignora en silencio y no rompe la
     * importacion**: lo peor que puede pasar es que su columna salga como «no
     * reconocida» en el informe, que es un aviso que se lee y se arregla. Fallar
     * al arrancar por una coma en el `.env` dejaria al cliente sin poder
     * importar nada.
     *
     * @return array<string, list<string>>
     */
    private static function columnAliases(): array
    {
        /** @var array<string, list<string>> $aliases */
        $aliases = config()->array('workforce.import.column_aliases');

        foreach (explode(';', config()->string('workforce.import.extra_column_aliases')) as $entry) {
            $parts = explode('=', $entry, 2);

            if (\count($parts) !== 2) {
                continue;
            }

            $field = trim($parts[0]);
            $header = trim($parts[1]);

            // Solo campos que el importador conoce: un alias para `dni_jefe` no
            // haria nada y dejaria a quien lo escribio esperando que hiciera algo.
            if ($header !== '' && isset($aliases[$field])) {
                $aliases[$field][] = $header;
            }
        }

        return $aliases;
    }
}

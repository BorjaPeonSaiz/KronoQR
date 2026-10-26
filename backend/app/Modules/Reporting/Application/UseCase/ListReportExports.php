<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ReportExportProgressBoard;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Support\ReportExportView;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;

/**
 * Los informes en diferido recientes **del solicitante** (**RF-IN-06**).
 *
 * ## Solo los suyos, tambien si es `admin`
 *
 * No es una limitacion tecnica: un informe en diferido
 * contiene las horas nominales de un conjunto de personas, y quien lo pidio
 * eligio ese conjunto. Que un administrador vea —y pueda pedir enlace de— los
 * ficheros que genero RRHH seria una via de acceso a datos personales que nadie
 * ha autorizado y que no aparece en ninguna policy. Si algun dia hace falta, sera
 * una decision de producto con su asiento propio; hoy esta anotada como fuera de
 * alcance.
 *
 * El filtro por dueño entra **en la consulta** (`WHERE requested_by_user_id =
 * ?`), no despues en PHP: filtrar un resultado ya leido es como se acaba
 * teniendo un `meta.total` que cuenta ficheros que quien pregunta no puede ver.
 *
 * ## Veinte, sin paginacion
 *
 * Una persona pide unos cuantos informes grandes al mes. Veinte cubre de sobra
 * lo que alguien mira, y una barra de paginas sobre una lista que cabe en media
 * pantalla es complejidad sin nadie que la use. El historico completo esta en
 * `audit_log`, que si tiene su propia consulta.
 *
 * ## Las purgadas y las fallidas siguen en la lista
 *
 * Regla dura 5. Una lista que enseñara solo las descargables convertiria «tu
 * informe caduco hace tres dias» en «aqui no ha pasado nada», que es la peor
 * respuesta para quien vuelve a buscar el fichero que pidio la semana pasada.
 *
 * ## Con el avance de las que se estan generando
 *
 * Solo de las `running`, y leido aparte de la fila
 * (`ReportExportProgressBoard`): es lo que la pantalla sondea mientras espera,
 * y por eso esta lista se puede pedir cada pocos segundos sin acuñar enlaces.
 * Una fila `cancelled` o `failed` nunca trae avance, aunque el trabajador
 * llegara a anotar uno antes de enterarse.
 */
final readonly class ListReportExports
{
    /** Lo que promete el esquema `ReportExportCollection` con su `maxItems`. */
    public const int LIMIT = 20;

    public function __construct(
        private ReportExportRepository $exports,
        private ReportExportProgressBoard $progress,
    ) {}

    /**
     * @param  list<ReportExportKind>|null  $kinds  Las clases que alcanza quien pregunta, o todas. Un
     *                                              token sin `reports:*` —el auditor— solo alcanza
     *                                              `legal`.
     * @return list<ReportExportView>
     */
    public function handle(int $requestedByUserId, ?array $kinds = null): array
    {
        return UseCaseInstrumentation::run('reporting.list_report_exports', fn () => $this->doHandle($requestedByUserId, $kinds));
    }

    /**
     * @param  list<ReportExportKind>|null  $kinds
     * @return list<ReportExportView>
     */
    private function doHandle(int $requestedByUserId, ?array $kinds): array
    {
        $exports = $this->exports->recentFor($requestedByUserId, self::LIMIT, $kinds);

        $running = array_values(array_map(
            static fn (ReportExport $export): string => $export->uuid,
            array_filter($exports, static fn (ReportExport $export): bool => $export->status === ReportExportStatus::Running),
        ));

        $progress = $running === [] ? [] : $this->progress->of($running);

        return array_map(
            static fn (ReportExport $export): ReportExportView => new ReportExportView($export, $progress[$export->uuid] ?? null),
            $exports,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Domain\Model\ReportExport;

/**
 * Los informes en diferido recientes **del solicitante** (**RF-IN-06**).
 *
 * ## Solo los suyos, tambien si es `admin`
 *
 * Decision 2 de la ficha, y no es una limitacion tecnica: un informe en diferido
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
 */
final readonly class ListReportExports
{
    /** Lo que promete el esquema `ReportExportCollection` con su `maxItems`. */
    public const int LIMIT = 20;

    public function __construct(private ReportExportRepository $exports) {}

    /**
     * @return list<ReportExport>
     */
    public function handle(int $requestedByUserId): array
    {
        return $this->exports->recentFor($requestedByUserId, self::LIMIT);
    }
}

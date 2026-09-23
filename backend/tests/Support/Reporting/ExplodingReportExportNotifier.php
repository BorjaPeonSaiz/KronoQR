<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\Modules\Reporting\Application\Port\ReportExportNotifier;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use RuntimeException;

/**
 * Un {@see ReportExportNotifier} que **revienta**, para probar que el aviso no
 * puede destruir un informe ya generado (**RF-IN-06**, regla dura 12).
 *
 * ## Que escenario representa
 *
 * El adaptador real atrapa los fallos de SMTP y devuelve `panel`, pero no puede
 * atrapar lo que ocurre **antes** de intentar el envio: resolver la cuenta a la
 * que avisar es una consulta a la base de datos, y esa consulta puede fallar
 * —conexion perdida, transaccion abortada, la fila del usuario que ya no esta—.
 *
 * Con el aviso dentro del `try` de la generacion, esa excepcion borraba el
 * fichero recien escrito y marcaba fallida una fila que estaba `completed`,
 * dejando en `audit_log` un `report_export.generated` que afirma un fichero
 * inexistente. Esta clase es lo que hace que esa regresion no pueda volver.
 */
final readonly class ExplodingReportExportNotifier implements ReportExportNotifier
{
    public function notify(ReportExport $export): ReportExportNotificationChannel
    {
        throw new RuntimeException('El aviso ha reventado antes de poder enviarse.');
    }
}

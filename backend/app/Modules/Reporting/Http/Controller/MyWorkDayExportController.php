<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\ReadEmployeeWorkDays;
use App\Modules\Reporting\Http\Request\ExportMyWorkDaysRequest;
use App\Modules\Reporting\Http\Response\PersonalRecordCsv;
use App\Modules\Reporting\Http\Support\JournalTelemetry;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /api/v1/me/export` — la descarga del historico propio (RF-ID-05, RL-05).
 *
 * ## Es la misma consulta que `/me/workdays` con otra presentacion
 *
 * Y no un informe aparte. Que el CSV se genere sobre el **mismo**
 * `WorkDayJournal` que pinta la pantalla es lo que garantiza que lo que alguien
 * descarga y lo que estaba mirando digan lo mismo: si fueran dos consultas,
 * bastaria con que una de las dos filtrara distinto para que el fichero que una
 * persona lleva a una reunion contradijera la pantalla desde la que lo bajo.
 *
 * ## No es la exportacion legal, y la diferencia importa
 *
 * `GET /api/v1/reports/legal-export` (RF-IN-05, tarea 1.17) la genera RRHH o
 * auditoria ante un requerimiento, abarca a terceros, lleva manifiesto con base
 * legal y deja asiento en `audit_log`. Esta la genera la persona sobre sus
 * propios datos. Mismo rigor en las horas —`HH:MM`, nunca decimal— y otro
 * destinatario, otro alcance y otra tabla de auditoria: ninguna.
 *
 * ## `GET` y no `POST`
 *
 * Solo lee. Que devuelva un fichero no lo convierte en una escritura, y un
 * `POST` impediria que el portal ofreciera la descarga como un enlace.
 *
 * ## Se emite en streaming, sin dejar el fichero en disco
 *
 * Al reves que la exportacion legal, que escribe a un temporal para poder
 * auditar **antes** de entregar nada. Aqui no hay asiento que escribir y el
 * volumen esta acotado por el techo de 366 dias de `DateRange`, asi que un
 * temporal solo añadiria un fichero con el registro horario de alguien esperando
 * a que alguien se olvide de borrarlo.
 */
final class MyWorkDayExportController extends Controller
{
    public function __invoke(
        ExportMyWorkDaysRequest $request,
        ReadEmployeeWorkDays $workDays,
        JournalTelemetry $telemetry,
    ): StreamedResponse {
        $query = $request->toQuery();

        $journal = $telemetry->measure(
            $query->employeeUuid,
            static fn () => $workDays->handle($query),
        );

        return PersonalRecordCsv::respond($journal);
    }
}

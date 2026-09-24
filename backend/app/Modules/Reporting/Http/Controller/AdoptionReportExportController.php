<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\Query\ReadAdoptionReport;
use App\Modules\Reporting\Application\UseCase\RecordAdoptionReportExport;
use App\Modules\Reporting\Domain\Event\AdoptionReportExported;
use App\Modules\Reporting\Http\Request\ExportAdoptionReportRequest;
use App\Modules\Reporting\Http\Response\AdoptionReportDocument;
use App\Modules\Reporting\Http\Response\AdoptionReportFile;
use App\Modules\Reporting\Http\Support\AdoptionReportTelemetry;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\Exception\FeatureNotLicensed;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Illuminate\Support\Facades\Config;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/v1/reports/adoption/export` — el cuadro de impacto como fichero
 * descargable (**RF-IN-08**).
 *
 * ## Es la misma consulta, y eso es la mitad del requisito
 *
 * {@see ReadAdoptionReport} con los mismos parametros y los mismos techos, no una
 * SQL propia. Si la exportacion tuviera la suya, el papel que alguien lleva a una
 * renovacion de licencia y la pantalla que estaba mirando podrian discrepar, y el
 * que se creeria seria el equivocado.
 *
 * ## Misma licencia que la consulta, y tiene que serlo
 *
 * `Feature::ImpactDashboard`, igual que el otro endpoint. Un endpoint de descarga
 * con la degradacion mas floja que su consulta es la forma habitual de que la
 * degradacion no sirva de nada — el mismo argumento por el que comparte ambito,
 * policy y limitador. **Esto no es el registro legal**: la exportacion para la
 * Inspeccion no se degrada jamas (RL-06, regla dura 15).
 *
 * ## El orden importa: componer, auditar, entregar
 *
 * Y es distinto del informe por periodo, donde el asiento lo escribe la consulta
 * antes de devolver el informe. Aqui el asiento **describe el fichero** —lleva su
 * huella y su tamaño—, asi que hay que componerlo primero; y tiene que escribirse
 * **antes** de que salga un byte, porque si falla la descarga no debe ocurrir (regla
 * dura 6, ADR-027). De ahi que el documento se construya entero en memoria
 * ({@see AdoptionReportFile}) en lugar de
 * transmitirse: son unos kilobytes.
 *
 * El asiento lo publica un caso de uso y no este metodo: publicar un evento de
 * dominio no es trabajo de un controlador.
 *
 * ## Se audita esto y no la lectura
 *
 * Abrir la pantalla no deja nada fuera del sistema y el cuadro no lleva ni un
 * identificador (regla dura 21). Descargarlo produce un documento que va a una
 * reunion, se adjunta a un correo y se archiva fuera del producto: de eso el cliente
 * tiene que poder responder quien, cuando y de que periodo. Ver
 * {@see AdoptionReportExported}.
 *
 * ## `GET` aunque quede auditado
 *
 * Solo lee. Que devuelva un fichero y que deje constancia no lo convierte en una
 * escritura; mismo criterio que la exportacion legal y que la descarga del informe.
 * Un `POST` ademas impediria enlazar la descarga.
 */
final class AdoptionReportExportController extends Controller
{
    public function __invoke(
        ExportAdoptionReportRequest $request,
        ReadAdoptionReport $reports,
        AdoptionReportTelemetry $telemetry,
        AdoptionReportDocument $documents,
        RecordAdoptionReportExport $audit,
        FeatureGate $features,
    ): Response {
        $availability = $features->statusOf(Feature::ImpactDashboard);

        if (! $availability->enabled) {
            throw FeatureNotLicensed::from($availability);
        }

        $criteria = $request->toCriteria();
        $format = $request->exportFormat();

        $report = $telemetry->measure($format, static fn () => $reports->handle(
            $criteria,
            maxRangeDays: Config::integer('reporting.adoption.max_range_days'),
            maxRows: Config::integer('reporting.period.max_rows'),
        ));

        $file = $documents->compose($report, $format, $this->actorUuid($request));

        $audit->handle(
            $report,
            $format->value,
            $file->digest,
            $file->sizeBytes(),
            $this->actorUserId($request),
        );

        return $file->toResponse();
    }

    /**
     * El `uuid` publico de la cuenta que pidio la descarga, para sellar el documento.
     *
     * La policy ya ha corrido cuando se llega aqui —`authorize()` del `FormRequest`—
     * y esa policy tipa {@see ManagementActor}, asi que llegar sin actor es imposible
     * salvo que alguien retire la autorizacion. Se comprueba igualmente y se rompe en
     * voz alta: sellar un documento con un emisor vacio seria peor que no entregarlo.
     */
    private function actorUuid(ExportAdoptionReportRequest $request): string
    {
        return $this->actor($request)->actorUuid();
    }

    /**
     * La clave interna de esa misma cuenta, para el asiento de `audit_log`.
     *
     * El asiento se atribuye por identificador interno y no por `uuid` porque asi lo
     * hace el resto del trail: es lo que permite unirlo con `users` sin depender de
     * una columna publica. Y con **cuenta equivocada el asiento seria de otra
     * persona**, asi que aqui tampoco se adivina nada.
     */
    private function actorUserId(ExportAdoptionReportRequest $request): int
    {
        $identifier = $request->user()?->getAuthIdentifier();

        if (! is_numeric($identifier)) {
            throw new LogicException('La descarga del cuadro de impacto ha llegado sin cuenta de gestion.');
        }

        return (int) $identifier;
    }

    private function actor(ExportAdoptionReportRequest $request): ManagementActor
    {
        $actor = $request->user();

        if (! $actor instanceof ManagementActor) {
            throw new LogicException('La descarga del cuadro de impacto ha llegado sin actor de gestion.');
        }

        return $actor;
    }
}

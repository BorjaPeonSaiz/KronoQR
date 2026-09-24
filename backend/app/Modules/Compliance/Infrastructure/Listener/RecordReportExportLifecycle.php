<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Reporting\Domain\Event\AdoptionReportExported;
use App\Modules\Reporting\Domain\Event\ReportExportDownloaded;
use App\Modules\Reporting\Domain\Event\ReportExportGenerated;
use App\Modules\Reporting\Domain\Event\ReportExportRequested;

/**
 * Sella en `audit_log` el ciclo de vida de un informe en diferido
 * (**RF-IN-06**, RS-05, regla dura 6, decision 7 de la ficha 3.9).
 *
 * ## Un listener con tres metodos, y no tres listeners
 *
 * Al contrario que la exportacion integra, donde los tres hechos tienen actores
 * que se resuelven distinto. Aqui los tres se atribuyen a **la misma cuenta**:
 * quien pidio el informe. Al pedirlo porque esta delante; al generarlo porque en
 * la cola no hay sesion y el asiento no puede salir firmado como `system`; y al
 * descargarlo porque **la ruta de descarga no tiene sesion** (ADR-041) y quien
 * recibio el enlace es quien lo pidio.
 *
 * Con la atribucion resuelta igual en los tres, partirlo en tres clases seria
 * repetir el mismo constructor tres veces.
 *
 * ## Que dice cada payload, y que no
 *
 * - **`requested`** — que se pidio: clase, formato, periodo, granularidad,
 *   agrupacion y **alcance** (`all` o `departments`). Es lo que convierte
 *   «alguien pidio un informe» en «alguien pidio las horas de todo el hotel de
 *   tres meses».
 * - **`generated`** — que salio: filas, huella, tamaño, caducidad y **la lista
 *   acotada de `employee_uuid`** cuando el informe es por empleado. El tope y el
 *   motivo son los mismos que en el informe sincrono.
 * - **`downloaded`** — que se lo llevaron: cuantas veces y con que huella, para
 *   poder confirmar que el fichero que alguien tiene delante es el que salio de
 *   aqui.
 *
 * **Nunca un nombre, nunca una hora trabajada y nunca la ruta absoluta del
 * fichero** (regla dura 21, decision 7): solo `file_name`, que es lo que permite
 * reconocerlo en una conversacion.
 *
 * ## Sincronos, sin `ShouldQueue` y sin `afterCommit`
 *
 * Si el asiento falla, el informe no se crea, no se marca como terminado y no se
 * entrega (ADR-027). Un fichero con las horas de la plantilla no sale de aqui sin
 * rastro (regla dura 6).
 *
 * ## Por que vive en `Compliance` y no en `Reporting`
 *
 * El §1.6 no concede la arista `Reporting -> Compliance`: el modulo que produce
 * el hecho no tiene que saber quien lo escucha. `Reporting` publica el evento y
 * esto lo sella, igual que con el alta de un empleado, el cambio de un ajuste y
 * la exportacion integra.
 */
final readonly class RecordReportExportLifecycle
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function requested(ReportExportRequested $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: AuditActor::user($event->requestedByUserId),
            action: AuditAction::ReportExportRequested,
            subject: AuditSubject::of('report_export'),
            payload: AuditPayload::of([
                'report_export_uuid' => $event->uuid,
                'kind' => $event->kind,
                'format' => $event->format,
                // El alcance con el que se autorizo (RF-ID-03): distingue «RRHH
                // pidio el hotel entero» de «alguien pidio un departamento», que
                // ante una brecha (RL-15) no es lo mismo.
                'scope' => $event->scope,
                ...self::parameters($event->parameters),
            ]),
        ));
    }

    public function generated(ReportExportGenerated $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: AuditActor::user($event->requestedByUserId),
            action: AuditAction::ReportExportGenerated,
            subject: AuditSubject::of('report_export'),
            payload: AuditPayload::of([
                'report_export_uuid' => $event->uuid,
                'kind' => $event->kind,
                'format' => $event->format,
                // El NOMBRE del fichero, nunca su ruta (decision 7).
                'file_name' => $event->fileName,
                // La huella es lo que identifica al fichero: con ella se puede
                // confirmar que el que alguien tiene delante es el que salio de
                // aqui.
                'sha256' => $event->sha256,
                'size_bytes' => $event->sizeBytes,
                'row_count' => $event->rowCount,
                'expires_at' => $event->expiresAt,
                'employees' => \count($event->employeeUuids),
                ...self::affectedSubjects($event->employeeUuids),
            ]),
        ));
    }

    /**
     * El cuadro de impacto descargado (**RF-IN-08**, tarea 3.13).
     *
     * ## Vive en este listener y no en uno propio
     *
     * Porque es el mismo hecho de la misma familia con la misma atribucion: una
     * cuenta de gestion se lleva un documento de informes. Un listener nuevo habria
     * repetido el constructor y habria dejado la pregunta «¿que informes salieron?»
     * contestada desde dos sitios.
     *
     * ## Y aqui el payload es corto, porque el documento no lleva a nadie dentro
     *
     * Periodo, formato, huella y tamaño. No hay `employee_uuids` que acotar ni
     * `row_count` que explicar: el cuadro son doce agregados de la instalacion
     * entera (regla dura 21). Es el unico de los cuatro hechos de este fichero del
     * que se puede decir eso, y la razon por la que **leer** el cuadro no deja
     * asiento mientras descargarlo si.
     *
     * La huella es la **del contenido**, no la del fichero: es la misma para el CSV,
     * el XLSX y el PDF del mismo cuadro y la misma que imprime el pie del PDF, asi
     * que el asiento se puede comparar con el papel que alguien tenga delante. El
     * tamaño si es del fichero, y va al lado por lo contrario: es lo que permite
     * reconocer el adjunto concreto.
     */
    public function adoptionExported(AdoptionReportExported $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: AuditActor::user($event->exportedByUserId),
            action: AuditAction::AdoptionReportExported,
            subject: AuditSubject::of('adoption_report'),
            payload: AuditPayload::of([
                'from' => $event->from,
                'to' => $event->to,
                'format' => $event->format,
                'sha256' => $event->sha256,
                'size_bytes' => $event->sizeBytes,
            ]),
        ));
    }

    public function downloaded(ReportExportDownloaded $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: AuditActor::user($event->requestedByUserId),
            action: AuditAction::ReportExportDownloaded,
            subject: AuditSubject::of('report_export'),
            payload: AuditPayload::of([
                'report_export_uuid' => $event->uuid,
                'kind' => $event->kind,
                'format' => $event->format,
                'file_name' => $event->fileName,
                'sha256' => $event->sha256,
                'size_bytes' => $event->sizeBytes,
                'download_count' => $event->downloadCount,
            ]),
        ));
    }

    /**
     * Los parametros del informe, **aplanados y sin nulos**.
     *
     * Aplanados porque el payload de `audit_log` es un mapa de escalares: un
     * objeto anidado obligaria a quien consulta el trail a saber su forma. Sin
     * nulos porque «no filtre por departamento» y «filtre por el departamento
     * nulo» se leen igual si la clave esta con valor vacio, y ahi la ausencia es
     * la respuesta.
     *
     * @param  array<string, bool|int|string|null>  $parameters
     * @return array<string, scalar>
     */
    private static function parameters(array $parameters): array
    {
        $flattened = [];

        foreach ($parameters as $key => $value) {
            if ($value !== null) {
                $flattened[$key] = $value;
            }
        }

        return $flattened;
    }

    /**
     * La lista de afectados, tal y como la acoto el caso de uso.
     *
     * Aqui no se vuelve a decidir el tope: viene ya aplicado en el evento, con el
     * mismo criterio y la misma cifra que `GeneratePeriodReport` —enumerar esta
     * descartado para los conjuntos grandes, porque el trail acabaria siendo una
     * segunda copia de la plantilla con cuatro años de retencion (RL-02), y es
     * obligatorio para los pequeños, porque saber de quien eran las horas es la
     * pregunta que RL-15 obliga a contestar—.
     *
     * Identificadores, nunca nombres (regla dura 21).
     *
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    private static function affectedSubjects(array $uuids): array
    {
        return $uuids === [] ? [] : ['employee_uuids' => implode(',', $uuids)];
    }
}

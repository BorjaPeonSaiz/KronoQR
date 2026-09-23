<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\PayrollDocumentWriter;
use App\Modules\Reporting\Application\Port\ReportCriteriaNarrator;
use App\Modules\Reporting\Application\Port\ReportExportDocumentWriter;
use App\Modules\Reporting\Application\Port\ReportExportNotifier;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Application\Support\ReportDataset;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\Event\ReportExportGenerated;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Genera el fichero del informe en diferido (**RF-IN-06**, **RF-IN-07**,
 * decision 4 de la ficha 3.9).
 *
 * ## No calcula nada nuevo: es **la misma** consulta del panel
 *
 * Llama a {@see GeneratePeriodReport}, el mismo caso de uso que sirve
 * `GET /reports/period` y su descarga. Si tuviera una consulta propia, el
 * fichero que alguien recibe por correo y la tabla que estaba mirando podrian
 * discrepar — y el que se creeria seria el equivocado. Por eso tambien reutiliza
 * sus criterios de inclusion, sus festivos (RF-GP-04) y su asiento de
 * divulgacion (RS-05).
 *
 * Lo unico que cambia son **los dos techos sincronos**: aqui no aplican, y por
 * eso entran como `PHP_INT_MAX`. No es que el informe cueste menos —cuesta
 * exactamente lo mismo—, es que nadie esta esperando con una pestaña abierta ni
 * hay un `fastcgi_read_timeout` que agotar. El techo que **si** sigue en pie es
 * `DateRange::MAXIMUM_DAYS` (366): mas de un año son dos exportaciones, y eso es
 * un limite del objeto de dominio, no un presupuesto de respuesta.
 *
 * El tercer techo —el `statement_timeout`— tampoco desaparece: se sustituye por
 * el suyo (`REPORTING_EXPORT_TIMEOUT_SECONDS`). Lo pone el lector que el
 * proveedor del modulo construye para este camino, asi que la consulta sincrona
 * conserva el suyo intacto. Una consulta en diferido sin techo ninguno seria una
 * conexion ocupando la base de datos que atiende el fichaje hasta que alguien la
 * mate (RNF-P-02, regla dura 19).
 *
 * ## El alcance se aplica tal cual, nunca se recalcula
 *
 * Sale de la columna `scope`, que es la instantanea del momento en que se
 * autorizo (decision 1). Si se releyera el alcance vivo de la cuenta, un
 * responsable al que le quitan un departamento entre la peticion y la generacion
 * recibiria un fichero distinto del que pidio, y el asiento de `audit_log`
 * describiria otra cosa.
 *
 * ## Idempotente si el trabajador reintenta
 *
 * Si la fila ya no esta en curso —`completed`, `failed` o `purged`—, devuelve y
 * no rehace nada: repetir la generacion seria cruzar otra vez la plantilla con
 * el calendario sin producir nada nuevo, y sobre `failed` ocultaria el fallo que
 * alguien tiene que mirar.
 *
 * ## Un fallo deja la fila cerrada, con un CODIGO
 *
 * Uno de los cinco de {@see ReportExportFailure}, nunca el mensaje ni la clase
 * de la excepcion: un error de PostgreSQL puede llevar dentro el valor de una
 * fila (regla dura 21) y esta columna se serializa en la API. La clase real la
 * registra quien invoca, junto al `uuid`.
 *
 * **Y el fichero a medias se borra.** Un CSV con media plantilla dentro,
 * invisible para la purga —que solo mira filas `completed`— y sin nadie que
 * supiera de donde salio, es una copia de horas nominales abandonada en el disco
 * del cliente.
 *
 * ## El aviso va DESPUES de cerrar la fila, y fuera de su transaccion
 *
 * Porque un SMTP lento o caido no puede impedir que un informe generado conste
 * como generado (regla dura 12, decision 8). Si el correo falla, la fila queda
 * con canal `panel` —que es el canal de serie— y la pantalla lo enseña igual.
 */
final readonly class GenerateReportExportHandler
{
    /**
     * Hasta cuantos afectados se enumeran en el asiento. La misma cifra que
     * `GeneratePeriodReport`, y por el mismo motivo: ver
     * {@see self::affectedSubjects()}.
     */
    private const int MAX_ENUMERATED_SUBJECTS = 50;

    public function __construct(
        private ReportExportRepository $exports,
        /**
         * El **mismo** caso de uso que el informe sincrono, construido con el
         * lector del `statement_timeout` en diferido. Ver el docblock.
         */
        private GeneratePeriodReport $reports,
        private ReportExportDocumentWriter $documents,
        private PayrollDocumentWriter $payroll,
        private ReportCriteriaNarrator $criteria,
        private ReportExportStorage $storage,
        private ReportExportNotifier $notifier,
        private ReportingEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        /** Dias que vive el fichero antes de purgarse (regla dura 14: ya resuelto). */
        private int $retentionDays,
    ) {}

    public function handle(string $uuid): ReportExport
    {
        $export = $this->exports->findByUuid($uuid)
            ?? throw new RuntimeException('No existe el informe en diferido solicitado: '.$uuid);

        if (! $export->isInProgress()) {
            return $export;
        }

        $export = $export->start($this->clock->now());
        $this->exports->save($export);

        /*
         * **La ruta se calcula dentro del `try`**, y no es estilo: crear el
         * directorio de `REPORTING_EXPORT_PATH` es la primera operacion que toca
         * el disco y por tanto la que falla cuando no se puede escribir —el fallo
         * mas probable en produccion—. Fuera del `try`, esa excepcion dejaria la
         * fila en `running` ocupando el turno de esa persona hasta que la
         * obsolescencia la liberase.
         */
        $path = null;

        try {
            /*
             * El alcance no puede faltar en una fila en curso: solo se borra al
             * purgar (RL-11), y purgar exige `completed`. Lo garantiza ademas el
             * `CHECK` `report_exports_chk_purged_is_minimised`. Se comprueba
             * igualmente porque generar un informe **sin alcance** seria generarlo
             * sobre la plantilla entera, que es exactamente la fuga que RF-ID-03
             * existe para impedir: ante la duda, no se genera.
             */
            $scope = $export->scope
                ?? throw new RuntimeException('El informe en diferido no tiene alcance que aplicar: '.$uuid);

            $report = $this->reports->handle(
                $export->parameters->toQuery($scope),
                // Los dos techos sincronos no aplican en diferido. Ver el docblock.
                maxRangeDays: PHP_INT_MAX,
                maxRows: PHP_INT_MAX,
                delivery: $this->deliveryOf($export),
                // El conjunto que se divulga, para el asiento `personal_data.accessed`
                // (RS-05): `payroll_export` cuando el fichero es el de la nomina, y
                // el del informe por periodo en el resto. Es el mismo campo que
                // escribe la descarga sincrona, asi que «quien se llevo estas horas y
                // para que» se sigue respondiendo con una sola consulta al trail.
                dataset: $this->datasetOf($export),
            );

            $fileName = $this->fileNameFor($export, $report);
            $path = $this->storage->pathFor($export->uuid, $fileName);
            $rowCount = $this->write($export, $report, $path);

            $completed = $this->completeAndPublish($uuid, $report, $fileName, $path, $rowCount);
        } catch (Throwable $failure) {
            /*
             * El fichero a medias se borra ANTES de marcar la fila. Si el fallo
             * fue al cerrarla, el fichero existe y nadie va a poder descargarlo
             * nunca: dejarlo seria abandonar en el disco del cliente un fichero
             * con las horas de su plantilla.
             */
            if ($path !== null) {
                $this->storage->delete($path);
            }

            $this->failIfStillInProgress($uuid, $failure);

            throw $failure;
        }

        /*
         * **EL AVISO VA FUERA DEL `try`, Y ESO NO ES ESTILO.**
         *
         * Dentro, cualquier fallo del aviso —un `find()` que no encuentra la
         * cuenta, una escritura que choca— caia en el `catch` de arriba: borraba
         * **un fichero ya generado y anunciado en `audit_log`** y marcaba fallida
         * una fila que estaba `completed`. El resultado era el peor de todos: un
         * `report_export.generated` que afirma un fichero que ya no existe.
         *
         * Aqui, si el aviso revienta, la excepcion sale sin tocar nada: la fila
         * sigue `completed`, el fichero sigue descargable y lo unico que falta es
         * el sello de `notified_at`. La pantalla —que es el canal de serie— lo
         * enseña igual (regla dura 12).
         */
        return $this->notify($completed);
    }

    /**
     * Cierra la fila **sobre una relectura bloqueada**, no sobre la instancia que
     * se leyo al empezar.
     *
     * Entre el `start()` y el cierre pasan minutos: en ese hueco la obsolescencia
     * pudo dar el trabajo por muerto y otra pasada pudo cerrarlo. Cerrar sobre la
     * instancia vieja escribiria diecinueve columnas a ciegas y resucitaria como
     * `completed` una fila que otro camino ya dio por fallida.
     *
     * Con `lockByUuid()` dentro de la transaccion, `complete()` comprueba el
     * estado **real** y lanza si ya no es `running` — y esa excepcion cae en el
     * `catch` de `handle()`, que borra el fichero recien escrito. Que hoy no
     * ocurra porque `REPORTING_EXPORT_STALE_AFTER` es mayor que el `$timeout` del
     * trabajo es una coincidencia de dos numeros configurables, no una garantia.
     */
    private function completeAndPublish(
        string $uuid,
        PeriodReport $report,
        string $fileName,
        string $path,
        int $rowCount,
    ): ReportExport {
        /** @var ReportExport $completed */
        $completed = $this->connection->transaction(
            function () use ($uuid, $report, $fileName, $path, $rowCount): ReportExport {
                $locked = $this->exports->lockByUuid($uuid)
                    ?? throw new RuntimeException('El informe en diferido desaparecio al cerrarlo: '.$uuid);

                $completed = $locked->complete(
                    completedAt: $this->clock->now(),
                    filePath: $path,
                    fileName: $fileName,
                    sizeBytes: $this->storage->sizeOf($path),
                    // La huella se calcula sobre el FICHERO ya cerrado y leido por
                    // bloques, no sobre lo que el escritor creia estar escribiendo:
                    // es la unica que sirve para comprobar que la descarga llego
                    // entera.
                    sha256: $this->storage->digestOf($path),
                    rowCount: $rowCount,
                    criteria: $this->criteria->linesFor($report),
                    expiresAt: $this->clock->now()->modify('+'.max(1, $this->retentionDays).' days'),
                );

                $this->publishGeneration($completed, $report);

                return $completed;
            }
        );

        return $completed;
    }

    /**
     * Marca fallida la fila **solo si al releerla sigue en curso**.
     *
     * Sin la relectura, un fallo tardio escribiria `failed` sobre una fila que
     * otro camino ya cerro —o que la purga ya marco— y la dejaria con
     * `file_path`, `sha256` y `completed_at` a nulo: una exportacion que existio,
     * se anuncio en `audit_log` y de la que no queda ni el rastro operativo.
     *
     * La escritura va dentro de la misma transaccion que el bloqueo por lo mismo
     * que el cierre: comprobar y escribir sin bloqueo es comprobar otra cosa.
     */
    private function failIfStillInProgress(string $uuid, Throwable $failure): void
    {
        $this->connection->transaction(function () use ($uuid, $failure): void {
            $locked = $this->exports->lockByUuid($uuid);

            if ($locked === null || ! $locked->isInProgress()) {
                return;
            }

            $this->exports->save($locked->fail($this->clock->now(), self::failureOf($failure)));
        });
    }

    /**
     * Guarda la fila cerrada y publica el hecho.
     *
     * **Sin abrir transaccion propia**: ya corre dentro de la de
     * {@see self::completeAndPublish()}, que es la que sostiene el bloqueo de la
     * fila. El asiento de `audit_log` es sincrono y tiene que poder impedir el
     * cierre (regla dura 6, ADR-027): un fichero con las horas de la plantilla
     * que consta como descargable sin que exista traza de su generacion es
     * exactamente lo que RS-05 no admite, y con las dos escrituras en la misma
     * transaccion eso se cumple por construccion.
     */
    private function publishGeneration(ReportExport $completed, PeriodReport $report): void
    {
        $this->exports->save($completed);

        $this->events->publish(new ReportExportGenerated(
            uuid: $completed->uuid,
            kind: $completed->kind->value,
            format: $completed->format,
            // El NOMBRE, nunca la ruta absoluta (regla dura 21 y decision 7): una
            // ruta del servidor no le dice nada a quien lee el trail y si dice
            // donde mirar a quien no deberia.
            fileName: $completed->fileName ?? '',
            sha256: $completed->sha256 ?? '',
            sizeBytes: $completed->sizeBytes ?? 0,
            rowCount: $completed->rowCount ?? 0,
            expiresAt: $completed->expiresAt?->format(DateTimeInterface::RFC3339) ?? '',
            employeeUuids: $this->affectedSubjects($report),
            requestedByUserId: $completed->requestedByUserId,
            occurredAt: $completed->completedAt ?? $this->clock->now(),
        ));
    }

    /**
     * La lista de afectados, **solo cuando el conjunto es pequeño**.
     *
     * Mismo tope y mismo motivo que `GeneratePeriodReport::affectedSubjects()`,
     * nombrado en prosa porque es privado alli: por debajo del tope el informe es
     * de una persona o de un equipo concreto y saber de quien eran las horas es
     * la pregunta que RL-15 obliga a contestar; por encima, el recuento y el
     * alcance la contestan mejor que quinientos identificadores, y enumerarlos
     * convertiria el trail —con cuatro años de retencion (RL-02)— en una segunda
     * copia de la plantilla.
     *
     * Con agrupacion por departamento o por centro la lista viene vacia del
     * propio informe, y es correcto: ahi no se ha divulgado el dato de nadie en
     * particular.
     *
     * Identificadores, nunca nombres (regla dura 21).
     *
     * @return list<string>
     */
    private function affectedSubjects(PeriodReport $report): array
    {
        $uuids = $report->employeeUuids();

        return \count($uuids) > self::MAX_ENUMERATED_SUBJECTS ? [] : $uuids;
    }

    /**
     * Avisa y deja escrito por donde.
     *
     * **Fuera de cualquier transaccion y despues de cerrar la fila**: el correo
     * sale por red a un servidor que no controla este producto, y meterlo en la
     * transaccion del cierre convertiria un SMTP lento en una fila bloqueada. Si
     * el aviso revienta de una forma que el adaptador no atrapo, el informe sigue
     * generado y descargable: lo unico que se pierde es el sello de
     * `notified_at`, y la pantalla es el canal de serie.
     */
    private function notify(ReportExport $completed): ReportExport
    {
        $now = $this->clock->now();
        $channel = $this->notifier->notify($completed);

        /*
         * `recordNotification()` y no `save()`: tres columnas en lugar de
         * diecinueve. Entre el cierre y el correo pueden haber pasado cosas
         * —alguien pidio el enlace, se lo descargo, la purga borro el fichero— y
         * un `save()` con la instancia de antes del correo las desharia todas.
         */
        $this->exports->recordNotification($completed->id, $now, $channel);

        return $completed->markNotified($now, $channel);
    }

    private function write(ReportExport $export, PeriodReport $report, string $path): int
    {
        return $export->kind === ReportExportKind::Payroll
            ? $this->payroll->write($export, $report, $path)
            : $this->documents->write($export, $report, $path);
    }

    private function fileNameFor(ReportExport $export, PeriodReport $report): string
    {
        return $export->kind === ReportExportKind::Payroll
            ? $this->payroll->fileNameFor($export, $report)
            : $this->documents->fileNameFor($export, $report);
    }

    /**
     * En que formato sale, para el asiento de divulgacion del informe.
     *
     * Es el mismo campo `format` que escribe la descarga sincrona, asi que «quien
     * se llevo estas horas y en que» se sigue respondiendo con una sola consulta
     * al trail. `ReportDelivery::Json` no puede salir de aqui: un informe en
     * diferido siempre acaba en fichero.
     */
    private function deliveryOf(ReportExport $export): ReportDelivery
    {
        return ReportDelivery::tryFrom($export->format) ?? ReportDelivery::Csv;
    }

    /**
     * Que conjunto de datos personales se divulga (RS-05).
     *
     * Son dos ficheros con el mismo contenido y dos finalidades distintas —uno se
     * lee y el otro se importa en la herramienta con la que se paga—, y el trail
     * tiene que poder distinguirlas: ante una revision de accesos, «saco el
     * informe de marzo» y «saco el fichero de la nomina de marzo» no son la misma
     * frase. Es el mismo valor que escribe la descarga sincrona de cada uno.
     */
    private function datasetOf(ReportExport $export): ReportDataset
    {
        return $export->kind === ReportExportKind::Payroll
            ? ReportDataset::PayrollExport
            : ReportDataset::PeriodReport;
    }

    /**
     * De que fallo se trata, en el vocabulario que lee quien pidio el informe.
     *
     * Se clasifica **aqui y no en el dominio** porque distinguir un error de base
     * de datos exige nombrar los tipos del driver, y `Domain/` no puede (regla
     * dura 1).
     *
     * El orden importa. {@see ReportTooLargeForSynchronousDelivery} va primero
     * porque es el fallo propio de esta tarea: el lector la lanza cuando
     * PostgreSQL cancela por `statement_timeout`, y ahi no hay nada roto —hay un
     * periodo demasiado grande—, asi que el codigo tiene que llevar a «pide dos
     * periodos mas cortos» y no a «abre una incidencia». Despues el driver, y lo
     * que no encaje en ninguno es `unexpected`.
     */
    private static function failureOf(Throwable $failure): ReportExportFailure
    {
        return match (true) {
            $failure instanceof ReportTooLargeForSynchronousDelivery => ReportExportFailure::QueryTimeout,
            $failure instanceof ReportExportWriteFailed => ReportExportFailure::WriteFailed,
            $failure instanceof QueryException,
            $failure instanceof PDOException => ReportExportFailure::DatabaseError,
            default => ReportExportFailure::Unexpected,
        };
    }
}

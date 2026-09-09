<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Application\Port\ErrorMetrics;
use App\Modules\Product\Domain\ValueObject\ErrorContextAllowlist;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use App\Modules\Product\Domain\ValueObject\ErrorWriteOutcome;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * **El unico camino por el que algo entra en `error_events`** (RF-PD-15, tarea
 * 5.12).
 *
 * Sanea, calcula la huella, agrupa y cuenta. Es el adaptador del puerto
 * {@see ErrorEventSink} que vive en `Shared`, de modo que quien reporta —el
 * enganche del manejador de excepciones, el latido del quiosco, el endpoint del
 * panel y del portal— no conoce ni la tabla, ni la huella, ni el saneado.
 *
 * ## Que hay un solo camino es la garantia, no una comodidad
 *
 * La regla dura 21 dice que en esta tabla no hay nombres. Eso solo se puede
 * afirmar si **todo** lo que entra pasa por el mismo saneado. Con dos caminos
 * —uno para el servidor y otro para los clientes, por ejemplo— bastaria olvidar
 * el saneado en uno para que el paquete de diagnostico empezara a llevar PII
 * hacia el fabricante, y nadie lo veria hasta que alguien abriera un ZIP.
 *
 * ## NUNCA lanza, y eso es parte del contrato
 *
 * Los cuatro pasos van envueltos. Un error al guardar el error no puede
 * convertirse en un segundo error (regla dura 19):
 *
 * - una peticion que **ya ha fallado** no puede fallar «mas» —el cliente
 *   recibiria un `500` en lugar del `409` que le tocaba—;
 * - el latido de un quiosco no puede fallar porque la tabla no responda: la
 *   tablet perderia su ventana de sincronizacion y con ella los fichajes de la
 *   cola;
 * - un trabajo de cola no puede reintentarse eternamente porque no se pueda
 *   apuntar por que fallo.
 *
 * Cuando no se puede guardar, queda constancia en el log tecnico —`warning`, no
 * `error`: no vaya a ser que el canal de logs vuelva a llamar aqui— y se
 * devuelve `false`. Quien llama decide: el latido responde
 * `client_errors_accepted: 0` y la tablet conserva su buffer para el siguiente
 * intento.
 *
 * ## `recordAll()` devuelve un PREFIJO
 *
 * Se detiene en el primero que no se pudo guardar y devuelve cuantos van desde
 * el principio. No es una sutileza: el cliente vacia su buffer con
 * `acknowledge(n)`, que descarta **los `n` mas antiguos**. Si el recuento fuera
 * «cuantos se guardaron en total» y hubiera huecos, el cliente descartaria
 * errores que no llegaron a persistirse.
 *
 * ## El instante del grupo, acotado por los DOS extremos
 *
 * `first_seen_at` y `last_seen_at` salen de `occurred_at` —el reloj de quien lo
 * sufrio, que es el que importa cuando un error viene de la cola offline de una
 * tablet—, acotado **por arriba al reloj del servidor y por abajo a recepcion
 * menos la retencion** (decision 6). Una tablet con la hora adelantada dejaria
 * su grupo clavado en lo alto del listado; una con la hora atrasada un ano
 * escribia un `last_seen_at` de hace un ano en un grupo vivo y **lo arrastraba
 * hacia la purga**, borrando un fallo que estaba ocurriendo hoy. Ver
 * {@see self::seenAt()}.
 *
 * ## El techo de grupos por origen
 *
 * Ver {@see self::overflows()}: por encima de
 * `PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE`, una huella nueva no abre fila,
 * se cuenta en el grupo de desbordamiento del origen. Es la cota que el
 * limitador de peticiones no puede poner, porque acota envios y no filas.
 */
final readonly class RecordErrorEvent implements ErrorEventSink
{
    /** El codigo con el que se reconoce el grupo de desbordamiento (decision 14). */
    public const string OVERFLOW_CODE = 'overflow';

    /** Anchura de `error_events.code`. */
    private const int MAX_CODE = 80;

    /** Anchura de `error_events.exception_class` y de `error_events.file`. */
    private const int MAX_CLASS = 255;

    /**
     * El mensaje del grupo de desbordamiento. Fijo y **sin nada variable
     * dentro**: es lo que hace que el grupo creado para contener la entropia no
     * la reintroduzca por la puerta de atras.
     */
    private const string OVERFLOW_MESSAGE = 'Se ha alcanzado el techo de grupos de errores abiertos de este origen. '
        .'Las apariciones nuevas se cuentan aqui en lugar de crear una fila propia. '
        .'Atiende o resuelve los grupos abiertos de este origen para volver a ver el detalle.';

    public function __construct(
        private ErrorEventRepository $errors,
        private ErrorMetrics $metrics,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $maxOpenGroupsPerSource,
        private int $retentionDays,
    ) {}

    public function record(ErrorReport $report): bool
    {
        try {
            $now = $this->clock->now();

            $message = ErrorMessageSanitizer::sanitize($report->message);
            $context = ErrorContextAllowlist::apply($report->context);
            $fingerprint = $this->fingerprint($report, $message);

            // Decision 14: por encima del techo, la ocurrencia se cuenta en el
            // grupo de desbordamiento del origen en vez de abrir fila.
            if ($this->overflows($report, $fingerprint)) {
                $report = $this->asOverflow($report);
                $message = self::OVERFLOW_MESSAGE;
                $context = [];
                $fingerprint = ErrorFingerprint::overflowFor($report->source);
            }

            $outcome = $this->errors->upsert(
                report: $this->withinColumnWidths($report),
                fingerprint: $fingerprint,
                message: $message,
                context: $context,
                seenAt: $this->seenAt($report->occurredAt, $now),
                recordedAt: $now,
            );

            if ($outcome->persisted()) {
                $this->metrics->errorRecorded($report->source, $report->level);
            }

            if ($outcome === ErrorWriteOutcome::Opened) {
                $this->metrics->groupOpened($report->source, $report->level);
            }

            return $outcome->persisted();
        } catch (Throwable $failure) {
            /*
             * El `catch` mas ancho del modulo, y a proposito. Aqui no se traga
             * un fallo esperado: se traga CUALQUIER cosa, porque este metodo se
             * llama desde el camino de un error y desde el latido del quiosco.
             * Un `TypeError` en el saneado no puede dejar sin fichar a nadie.
             */
            $this->logger->warning('product.error_history_write_failed', [
                'source' => $report->source->value,
                'level' => $report->level->value,
                // La clase, nunca el mensaje: el mensaje del fallo al guardar
                // puede llevar dentro el mensaje que se intentaba guardar
                // (regla dura 21).
                'failure' => $failure::class,
            ]);

            return false;
        }
    }

    public function recordAll(array $reports): int
    {
        $accepted = 0;

        foreach ($reports as $report) {
            if (! $this->record($report)) {
                return $accepted;
            }

            $accepted++;
        }

        return $accepted;
    }

    /**
     * Si esta ocurrencia **abriria** un grupo por encima del techo del origen
     * (decision 14).
     *
     * ## El problema que cierra
     *
     * El limitador acota **peticiones**, no filas: con cincuenta errores por
     * envio y doce envios por minuto, una sesion podia crear seiscientos grupos
     * nuevos por minuto y dejarlos noventa dias. El catalogo cerrado de codigos
     * corta la mayor parte de la entropia en el origen, pero el mensaje sigue
     * siendo libre —viene del navegador— y la huella lo incluye.
     *
     * ## Las dos consultas solo ocurren en el techo
     *
     * Por debajo del techo esto es **una** consulta barata sobre el indice de
     * `(level, resolved_at)`; solo cuando el origen ya esta lleno se pregunta
     * ademas si la huella existe. Y esa segunda pregunta es la que garantiza que
     * **un grupo que ya existe sigue contando sus apariciones**: el techo frena
     * grupos nuevos, no el registro de lo que ya se estaba viendo.
     */
    private function overflows(ErrorReport $report, ErrorFingerprint $fingerprint): bool
    {
        if ($this->maxOpenGroupsPerSource < 1) {
            return false;
        }

        if ($this->errors->countOpenGroups($report->source) < $this->maxOpenGroupsPerSource) {
            return false;
        }

        return ! $this->errors->exists($fingerprint);
    }

    /**
     * El mismo informe, convertido en una aparicion del grupo de desbordamiento.
     *
     * Conserva el origen —es un techo POR ORIGEN— y el nivel, para que un
     * desbordamiento de errores criticos siga siendo critico. Pierde todo lo
     * demas: clase, fichero, linea, traza y contexto son justamente lo que
     * variaba, y guardarlo aqui volveria a llenar la tabla por otra via.
     */
    private function asOverflow(ErrorReport $report): ErrorReport
    {
        return new ErrorReport(
            source: $report->source,
            level: $report->level,
            message: self::OVERFLOW_MESSAGE,
            occurredAt: $report->occurredAt,
            appVersion: $report->appVersion,
            context: [],
            code: self::OVERFLOW_CODE,
            exceptionClass: null,
            file: null,
            line: null,
            traceId: null,
            deviceId: null,
            employeeUuid: null,
            module: null,
        );
    }

    /**
     * El mismo informe con `code`, `exception_class` y `file` recortados a la
     * anchura de su columna (decision 14).
     *
     * **Un valor largo no puede hacer fallar el `INSERT` y perder el error.** Es
     * el peor modo de fallo que puede tener esta tabla: el fallo que mas cuesta
     * diagnosticar —una clase de excepcion generada, una ruta de fichero de
     * ochenta niveles, un codigo mal formado— seria justo el que no se guarda.
     * PostgreSQL rechaza la fila entera con `22001` y el `catch` de arriba la
     * convierte en una linea de log que nadie mira.
     *
     * Se recorta **por caracteres y no por bytes**: `substr()` sobre UTF-8 parte
     * una tilde por la mitad y deja una fila con un byte invalido que revienta
     * al serializar el paquete de diagnostico.
     */
    private function withinColumnWidths(ErrorReport $report): ErrorReport
    {
        return new ErrorReport(
            source: $report->source,
            level: $report->level,
            message: $report->message,
            occurredAt: $report->occurredAt,
            appVersion: mb_substr($report->appVersion, 0, 32),
            context: $report->context,
            code: self::clip($report->code, self::MAX_CODE),
            exceptionClass: self::clip($report->exceptionClass, self::MAX_CLASS),
            file: self::clip($report->file, self::MAX_CLASS),
            line: $report->line,
            traceId: $report->traceId,
            deviceId: $report->deviceId,
            employeeUuid: $report->employeeUuid,
            module: self::clip($report->module, 40),
        );
    }

    private static function clip(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit);
    }

    /**
     * El instante del grupo, **acotado por los dos extremos** (decision 6).
     *
     * - **Por arriba, al reloj del servidor.** Una tablet con la hora adelantada
     *   dejaria su grupo clavado en lo alto del listado durante dias y
     *   retrasaria su purga, que envejece por `last_seen_at`.
     * - **Por abajo, a recepcion menos la retencion.** Es la mitad que faltaba:
     *   una tablet con la hora atrasada un ano escribia un `last_seen_at` de
     *   2025 en un grupo vivo y **lo arrastraba hacia la purga**, borrando un
     *   fallo que estaba ocurriendo hoy. Con el `GREATEST` de la escritura el
     *   grupo ya no retrocede, y con esta cota tampoco nace envejecido.
     *
     * El desfase real, si importa, viaja aparte en `context.skew_seconds`, que
     * es donde el quiosco ya lo pone.
     */
    private function seenAt(DateTimeImmutable $occurredAt, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($occurredAt > $now) {
            return $now;
        }

        $floor = $now->sub(new DateInterval('P'.max(1, $this->retentionDays).'D'));

        return $occurredAt < $floor ? $floor : $occurredAt;
    }

    /**
     * La huella del servidor o la del cliente, segun de donde venga.
     *
     * Un cliente no tiene `fichero:linea` —su `stack` nunca viaja— y su codigo
     * del catalogo cerrado hace ese trabajo. Ver {@see ErrorFingerprint}.
     */
    private function fingerprint(ErrorReport $report, string $message): ErrorFingerprint
    {
        if ($report->source->isClient() && $report->code !== null) {
            return ErrorFingerprint::forClient($report->source, $report->code, $message);
        }

        return ErrorFingerprint::forServer(
            $report->source,
            $report->exceptionClass,
            $report->file,
            $report->line,
            $message,
        );
    }
}

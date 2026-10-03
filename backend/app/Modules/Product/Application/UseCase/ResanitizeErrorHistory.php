<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ErrorHistoryRewriter;
use App\Modules\Product\Domain\ValueObject\ErrorColumnSanitizer;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use Psr\Log\LoggerInterface;

/**
 * **Vuelve a sanear el historico de errores ya guardado** y recalcula sus
 * huellas (ADR-048 decision 9, H6; RF-PD-15, RL-19, regla dura 21).
 *
 * ## Por que hace falta
 *
 * Las instalaciones anteriores a la 2.2.0 guardaron filas con el saneado por
 * patrones, que dejaba pasar nombres sin comillas. Esas filas viven 90 dias y
 * viajan en el paquete de diagnostico. Y su `fingerprint` es un `sha256` SIN
 * SAL de un texto que contenia el nombre: con la plantilla conocida, se puede
 * atacar por diccionario. No basta con filtrar al leer: hay que reescribirlas.
 *
 * ## Que hace con cada grupo
 *
 * Aplica exactamente lo que aplica `RecordErrorEvent` al escribir hoy —el
 * mensaje, el contexto, `code`, `exception_class`, `file`, `app_version` y la
 * huella sobre lo ya saneado— y:
 *
 * - si no cambia nada, no escribe;
 * - si la huella nueva ya la tiene otro grupo, **los funde**: suma
 *   `occurrences`, toma el `first_seen_at` mas antiguo y el `last_seen_at` mas
 *   reciente, y el grupo **queda abierto si cualquiera de los dos lo estaba**
 *   (un fallo que alguien dio por atendido y que sigue abierto en otra fila no
 *   esta atendido). Si los dos estaban resueltos, se queda la resolucion mas
 *   reciente;
 * - si no, reescribe la fila en su sitio.
 *
 * ## Idempotente y sin asiento de auditoria
 *
 * Ejecutarlo dos veces da lo mismo que una: las funciones de saneado lo son, y
 * en la segunda pasada ningun grupo cambia. **No escribe en `audit_log`**: es
 * diagnostico tecnico, no registro legal, y la migracion que lo lanza queda en
 * la tabla `migrations`. Deja una linea de log con dos recuentos y ningun
 * contenido.
 */
final readonly class ResanitizeErrorHistory
{
    /** Filas por lectura. */
    public const int DEFAULT_BATCH = 200;

    public function __construct(
        private ErrorHistoryRewriter $groups,
        private LoggerInterface $logger,
        private int $batchSize = self::DEFAULT_BATCH,
    ) {}

    public function run(): ResanitizeErrorHistoryResult
    {
        $size = max(1, $this->batchSize);
        $afterId = 0;
        $rows = 0;
        $rewritten = 0;
        $merged = 0;

        do {
            $batch = $this->groups->groupsAfter($afterId, $size);

            foreach ($batch as $group) {
                $afterId = $group->id;
                $rows++;

                $clean = self::sanitized($group);

                if (self::same($clean, $group)) {
                    continue;
                }

                if ($this->mergedInto($clean, $group->id)) {
                    $merged++;

                    continue;
                }

                if ($this->groups->rewrite($clean)) {
                    $rewritten++;

                    continue;
                }

                // La huella la ha tomado otro grupo entre la busqueda y la
                // escritura: el sumidero sigue escribiendo mientras esto corre.
                if ($this->mergedInto($clean, $group->id)) {
                    $merged++;
                }
            }
        } while (\count($batch) === $size);

        // Dos recuentos y nada mas: ni mensajes, ni huellas, ni identificadores.
        $this->logger->info('product.error_history_resanitized', ['rows' => $rows, 'merged' => $merged]);

        return new ResanitizeErrorHistoryResult($rows, $rewritten, $merged);
    }

    /**
     * Funde `$absorbedId` en el grupo que ya tiene la huella de `$clean`, si lo
     * hay y no es el mismo.
     *
     * @phpstan-impure Consulta y escribe la tabla: la segunda llamada, tras una
     * reescritura rechazada, puede encontrar un grupo que la primera no vio.
     */
    private function mergedInto(ErrorEvent $clean, int $absorbedId): bool
    {
        $holder = $this->groups->findByFingerprint($clean->fingerprint);

        if (! $holder instanceof ErrorEvent || $holder->id === $absorbedId) {
            return false;
        }

        $this->groups->merge($holder->id, $absorbedId);

        return true;
    }

    /**
     * El grupo con las reglas de hoy: las de {@see ErrorColumnSanitizer::row()},
     * las mismas que aplica `RecordErrorEvent` al escribir.
     */
    public static function sanitized(ErrorEvent $group): ErrorEvent
    {
        $row = ErrorColumnSanitizer::row(
            $group->source,
            $group->message,
            $group->context,
            $group->code,
            $group->exceptionClass,
            $group->file,
            $group->appVersion,
        );

        return new ErrorEvent(
            id: $group->id,
            fingerprint: ErrorFingerprint::forGroup(
                $group->source,
                $row->code,
                $row->exceptionClass,
                $row->file,
                $group->line,
                $row->message,
            )->value,
            level: $group->level,
            source: $group->source,
            module: $group->module,
            code: $row->code,
            message: $row->message,
            exceptionClass: $row->exceptionClass,
            file: $row->file,
            line: $group->line,
            context: $row->context,
            traceId: $group->traceId,
            deviceId: $group->deviceId,
            employeeUuid: $group->employeeUuid,
            appVersion: $row->appVersion,
            occurrences: $group->occurrences,
            firstSeenAt: $group->firstSeenAt,
            lastSeenAt: $group->lastSeenAt,
            resolvedAt: $group->resolvedAt,
            resolvedByUuid: $group->resolvedByUuid,
            resolvedByName: $group->resolvedByName,
        );
    }

    /**
     * Si el saneado no cambio nada que se guarde. El contexto se compara sin
     * orden: `jsonb` devuelve las claves en el suyo, no en el de la lista.
     */
    private static function same(ErrorEvent $clean, ErrorEvent $group): bool
    {
        $cleanContext = $clean->context;
        $groupContext = $group->context;
        ksort($cleanContext);
        ksort($groupContext);

        return $clean->fingerprint === $group->fingerprint
            && $clean->message === $group->message
            && $clean->code === $group->code
            && $clean->exceptionClass === $group->exceptionClass
            && $clean->file === $group->file
            && $clean->appVersion === $group->appVersion
            && $cleanContext === $groupContext;
    }
}

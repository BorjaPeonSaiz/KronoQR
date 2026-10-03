<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ErrorHistoryRewriter;
use App\Modules\Product\Domain\ValueObject\ErrorColumnSanitizer;
use App\Modules\Product\Domain\ValueObject\ErrorContextAllowlist;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use DateTimeImmutable;
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

                $holder = $this->groups->findByFingerprint($clean->fingerprint);

                if ($holder instanceof ErrorEvent && $holder->id !== $group->id) {
                    $this->groups->merge(self::merged($holder, $clean), $group->id);
                    $merged++;

                    continue;
                }

                $this->groups->rewrite($clean);
                $rewritten++;
            }
        } while (\count($batch) === $size);

        // Dos recuentos y nada mas: ni mensajes, ni huellas, ni identificadores.
        $this->logger->info('product.error_history_resanitized', ['rows' => $rows, 'merged' => $merged]);

        return new ResanitizeErrorHistoryResult($rows, $rewritten, $merged);
    }

    /**
     * El grupo con las reglas de hoy.
     */
    public static function sanitized(ErrorEvent $group): ErrorEvent
    {
        $code = ErrorColumnSanitizer::code($group->source, $group->code);
        $exceptionClass = ErrorColumnSanitizer::exceptionClass($group->exceptionClass);
        $file = ErrorColumnSanitizer::file($group->file);
        $message = ErrorMessageSanitizer::sanitize($group->message);

        return new ErrorEvent(
            id: $group->id,
            fingerprint: ErrorFingerprint::forGroup(
                $group->source,
                $code,
                $exceptionClass,
                $file,
                $group->line,
                $message,
            )->value,
            level: $group->level,
            source: $group->source,
            module: $group->module,
            code: $code,
            message: $message,
            exceptionClass: $exceptionClass,
            file: $file,
            line: $group->line,
            context: ErrorContextAllowlist::apply($group->context),
            traceId: $group->traceId,
            deviceId: $group->deviceId,
            employeeUuid: $group->employeeUuid,
            appVersion: ErrorColumnSanitizer::appVersion($group->appVersion),
            occurrences: $group->occurrences,
            firstSeenAt: $group->firstSeenAt,
            lastSeenAt: $group->lastSeenAt,
            resolvedAt: $group->resolvedAt,
            resolvedByUuid: $group->resolvedByUuid,
            resolvedByName: $group->resolvedByName,
        );
    }

    /**
     * El grupo que sobrevive a la fusion: el que ya tenia la huella, con los
     * recuentos, los instantes y la resolucion de los dos.
     */
    public static function merged(ErrorEvent $survivor, ErrorEvent $absorbed): ErrorEvent
    {
        [$resolvedAt, $resolvedByUuid, $resolvedByName] = self::resolution($survivor, $absorbed);

        return new ErrorEvent(
            id: $survivor->id,
            fingerprint: $survivor->fingerprint,
            level: $survivor->level,
            source: $survivor->source,
            module: $survivor->module,
            code: $survivor->code,
            message: $survivor->message,
            exceptionClass: $survivor->exceptionClass,
            file: $survivor->file,
            line: $survivor->line,
            context: $survivor->context,
            traceId: $survivor->traceId,
            deviceId: $survivor->deviceId,
            employeeUuid: $survivor->employeeUuid,
            appVersion: $survivor->appVersion,
            occurrences: $survivor->occurrences + $absorbed->occurrences,
            firstSeenAt: min($survivor->firstSeenAt, $absorbed->firstSeenAt),
            lastSeenAt: max($survivor->lastSeenAt, $absorbed->lastSeenAt),
            resolvedAt: $resolvedAt,
            resolvedByUuid: $resolvedByUuid,
            resolvedByName: $resolvedByName,
        );
    }

    /**
     * Abierto si cualquiera lo estaba; si los dos estaban resueltos, la
     * resolucion mas reciente con su autor.
     *
     * @return array{0: ?DateTimeImmutable, 1: ?string, 2: ?string}
     */
    private static function resolution(ErrorEvent $survivor, ErrorEvent $absorbed): array
    {
        if ($survivor->isOpen() || $absorbed->isOpen()) {
            return [null, null, null];
        }

        $latest = $absorbed->resolvedAt > $survivor->resolvedAt ? $absorbed : $survivor;

        return [$latest->resolvedAt, $latest->resolvedByUuid, $latest->resolvedByName];
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

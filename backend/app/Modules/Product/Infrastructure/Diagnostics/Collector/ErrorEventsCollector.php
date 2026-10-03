<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\ErrorColumnSanitizer;
use App\Modules\Product\Domain\ValueObject\ErrorContextAllowlist;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEventStatusFilter;
use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use App\Modules\Product\Domain\ValueObject\FieldAllowlist;
use App\Modules\Shared\Application\Port\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Seccion `error_events`: **el historico agrupado del periodo, con su
 * `trace_id`** (§11.6.6, **RF-PD-15**, decision 12 de la ficha 5.12).
 *
 * ## Es la seccion por la que existe la tabla
 *
 * El fabricante no accede al servidor del cliente (ADR-016, ADR-020) y Loki es
 * opcional. Sin esta seccion, la primera pregunta de cada incidencia seria
 * «¿puedes mirar los logs?» y la respuesta seria que no. Con ella, el cliente
 * envia un fichero y soporte ve **que esta fallando, desde cuando, cuantas veces
 * y con que traza** sin haber entrado en ningun sitio.
 *
 * ## Resumen antes que detalle
 *
 * `summary` va primero porque es lo primero que se mira: ochenta grupos abiertos
 * de origen `worker` orientan la incidencia antes de leer un solo mensaje.
 * `groups` viene despues, hasta 500 y por `last_seen_at` descendente, que es el
 * orden de «lo que esta pasando ahora».
 *
 * ## Lista de permitidos, y por eso `resolved_by` NO sale
 *
 * Cada grupo pasa por {@see FieldAllowlist}, igual que el resto del paquete y de
 * la exportacion integra: **lo que no esta declarado, no viaja**. La unica
 * columna del historico que lleva un nombre de persona es el autor de la
 * resolucion —una cuenta de gestion del hotel—, y no esta en la lista. Que un
 * fallo lo diera por atendido «Marta» no ayuda a diagnosticar nada y es
 * informacion de la organizacion del cliente.
 *
 * Si entra `trace_id`, y a proposito: el contrato lo pide con todas las letras y
 * es lo que permite correlacionar con el log tecnico **del cliente** cuando el
 * cliente si lo conserva. No identifica a nadie.
 *
 * ## `unavailable` en lugar de `[]` cuando no se puede leer
 *
 * Mismo criterio que `RetentionTally::unavailable()` y que la version anterior
 * de esta clase: una lista vacia **afirmaria que no ha habido errores**, y
 * soporte descartaria la hipotesis correcta. `reason` lleva la **clase** de lo
 * que fallo y nunca su mensaje: un mensaje de PostgreSQL puede llevar dentro el
 * valor de una fila (regla dura 21).
 *
 * ## El periodo es el del propio paquete
 *
 * `period_days` de {@see DiagnosticsOptions}, el mismo que acota `personal_data`,
 * para que las dos secciones hablen de la misma ventana y se puedan leer juntas.
 * Con el paquete anonimizado —el caso normal— eso son los siete dias por
 * omision.
 *
 * ## En el anonimizado no hay identificador de empleado (H2, ADR-048)
 *
 * `employee_uuid` es un **seudonimo** (art. 4.5 RGPD): el hotel tiene la
 * correspondencia con la persona. Por eso el paquete anonimizado **omite la
 * columna** —ni siquiera la lleva a nulo— y sustituye **todo UUID** del mensaje
 * y de los valores del contexto por `[uuid]`: un «Employee 0199… has no open
 * shift entry» lo llevaria dentro aunque la columna faltara. `device_id` y
 * `trace_id` se quedan: identifican una tablet y una peticion, no a una
 * persona. Con `--with-personal-data` el grupo sale completo. La tabla local
 * no cambia.
 */
final readonly class ErrorEventsCollector implements DiagnosticsCollector
{
    /**
     * Tope de grupos. Quinientos es lo que el contrato declara y cubre de sobra
     * una instalacion con problemas; por encima, la seccion se comeria el tope
     * del paquete entero y dejaria fuera otras que si hacen falta.
     */
    private const int MAX_GROUPS = 500;

    /**
     * Lo que viaja de cada grupo. Ver el docblock: es una lista de **permitidos**
     * y el autor de la resolucion no esta.
     */
    private const array GROUP_FIELDS = [
        'fingerprint',
        'level',
        'source',
        'module',
        'code',
        'message',
        'exception_class',
        'file',
        'line',
        'context',
        'trace_id',
        'device_id',
        'employee_uuid',
        'app_version',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    /** La columna que el paquete anonimizado no lleva (H2). */
    private const string EMPLOYEE_FIELD = 'employee_uuid';

    public function __construct(
        private ErrorEventRepository $errors,
        private Clock $clock,
    ) {}

    public function section(): string
    {
        return 'error_events';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        $since = $this->clock->now()->sub(new DateInterval('P'.max(1, $options->periodDays).'D'));

        try {
            $summary = $this->errors->summary($since);

            $page = $this->errors->page(new ErrorEventQuery(
                status: ErrorEventStatusFilter::All,
                from: $since,
                page: 1,
                perPage: self::MAX_GROUPS,
            ));
        } catch (Throwable $failure) {
            return ['status' => 'unavailable', 'reason' => $failure::class];
        }

        // La lista se construye una vez y se aplica a todas las filas: impone el
        // orden declarado, de modo que dos paquetes de la misma instalacion se
        // puedan comparar linea a linea con `diff`. En el anonimizado no lleva
        // `employee_uuid` (H2): ver el docblock de la clase.
        $anonymized = ! $options->includePersonalData;
        $allowlist = new FieldAllowlist(...array_values(array_filter(
            self::GROUP_FIELDS,
            static fn (string $field): bool => ! ($anonymized && $field === self::EMPLOYEE_FIELD),
        )));

        return [
            'status' => 'ok',
            'period_days' => max(1, $options->periodDays),
            'summary' => [
                'open' => $summary->totalOpen,
                'resolved' => $summary->totalResolved,
                'by_source' => $summary->perSource,
                'by_level' => $summary->perLevel,
            ],
            'total_groups' => $page->total,
            'truncated' => $page->total > self::MAX_GROUPS,
            'groups' => array_map(
                static fn (ErrorEvent $event): array => $allowlist->apply(self::describe($event, $anonymized)),
                $page->rows,
            ),
        ];
    }

    /**
     * Un grupo como mapa plano, antes de pasar por la lista de permitidos.
     *
     * `context` es un mapa anidado y {@see FieldAllowlist} lo anularia -no puede
     * afirmar nada sobre las claves de un mapa-, asi que llega ya **serializado
     * a texto**. No es una trampa a la lista: lo que hay dentro paso antes por
     * {@see ErrorContextAllowlist}, que
     * es una lista de permitidos con dieciseis claves y valores escalares
     * saneados. La de aqui protege las columnas; aquella protege el contexto.
     *
     * ## Se vuelve a sanear al leer (ADR-048, H7)
     *
     * `message`, `context`, `code`, `exception_class`, `file` y `app_version`
     * pasan otra vez por las mismas funciones que `RecordErrorEvent` aplica al
     * escribir. Son idempotentes, asi que con una fila bien escrita no cambia
     * nada; con una escrita por otro camino, o por una version anterior a la
     * migracion de saneado, es la red que impide que salga.
     *
     * @return array<string, mixed>
     */
    private static function describe(ErrorEvent $event, bool $anonymized): array
    {
        $message = ErrorMessageSanitizer::sanitize($event->message);
        $context = ErrorContextAllowlist::apply($event->context);

        if ($anonymized) {
            $message = ErrorMessageSanitizer::withoutUuids($message);
            $context = array_map(
                static fn (bool|int|float|string $value): bool|int|float|string => \is_string($value)
                    ? ErrorMessageSanitizer::withoutUuids($value)
                    : $value,
                $context,
            );
        }

        return [
            'fingerprint' => $event->fingerprint,
            'level' => $event->level->value,
            'source' => $event->source->value,
            'module' => $event->module,
            'code' => ErrorColumnSanitizer::code($event->source, $event->code),
            'message' => $message,
            'exception_class' => ErrorColumnSanitizer::exceptionClass($event->exceptionClass),
            'file' => ErrorColumnSanitizer::file($event->file),
            'line' => $event->line,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'trace_id' => $event->traceId,
            'device_id' => $event->deviceId,
            self::EMPLOYEE_FIELD => $anonymized ? null : $event->employeeUuid,
            'app_version' => ErrorColumnSanitizer::appVersion($event->appVersion),
            'occurrences' => $event->occurrences,
            'first_seen_at' => self::utc($event->firstSeenAt),
            'last_seen_at' => self::utc($event->lastSeenAt),
            'resolved_at' => $event->resolvedAt instanceof DateTimeImmutable
                ? self::utc($event->resolvedAt)
                : null,
        ];
    }

    /** ISO-8601 en UTC, como todo instante que sale del producto (regla dura 3). */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}

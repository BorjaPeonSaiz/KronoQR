<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use DateTimeImmutable;
use DateTimeZone;

/**
 * La forma del esquema `ErrorEvent` del contrato, en un solo sitio (RF-PD-15).
 *
 * **Existe porque la misma fila sale por dos puertas**: dentro de la pagina de
 * `GET /api/v1/diagnostics/errors` y sola en el `200` de
 * `POST /api/v1/diagnostics/errors/{id}/resolve`. Con la serializacion escrita
 * dos veces, el dia que se anada un campo bastaria olvidarlo en una para que el
 * panel pintara una fila distinta segun de donde viniera — que es justo el caso
 * en el que el panel sustituye la fila recien resuelta por la que devuelve el
 * `POST`.
 *
 * Es el mismo papel que `IncidentPayload` en `Compliance`, y por el mismo
 * motivo.
 *
 * **Ningun instante sale en hora local desde aqui.** Todos van en UTC (regla
 * dura 3) y la antiguedad se calcula contra `meta.generated_at`; la zona del
 * centro viaja una vez en `meta.time_zone`.
 *
 * **`context` sale como objeto aunque este vacio.** Un `[]` de PHP se serializa
 * como array JSON y el contrato declara un objeto: el cliente generado lo
 * rechazaria. Es el fallo tipico de esta clase de mapas y por eso se fuerza
 * aqui, donde se ve.
 *
 * ## `resolved_by` NO se sirve a un actor de soporte (decision 14)
 *
 * Es la unica columna del historico que lleva **un nombre de persona**: la
 * cuenta de gestion del hotel que dio el fallo por atendido. El paquete de
 * diagnostico ya la excluia por el mismo motivo —no ayuda a diagnosticar nada y
 * es informacion de la organizacion del cliente (ADR-020, regla dura 16)—, y un
 * acceso de soporte que lee la pantalla la estaba viendo igual.
 *
 * **Sale `null`, no un nombre vacio.** El esquema `IncidentUser` del contrato
 * exige `name` con `minLength: 1`, asi que un `{uuid, name: ''}` seria una
 * respuesta invalida; y `resolved_by: null` con `resolved_at` puesto ya es un
 * estado que el contrato describe —el de la cuenta borrada—. El soporte sigue
 * viendo **que** se resolvio y **cuando**, que es lo que necesita para
 * diagnosticar; quien lo hizo es asunto del cliente.
 */
final readonly class ErrorEventPayload
{
    /**
     * @param  bool  $withResolver  `false` para un actor de soporte. Ver el docblock de la clase.
     * @return array<string, mixed>
     */
    public static function of(ErrorEvent $event, bool $withResolver = true): array
    {
        return [
            'id' => $event->id,
            'level' => $event->level->value,
            'source' => $event->source->value,
            'module' => $event->module,
            'code' => $event->code,
            'message' => $event->message,
            'exception_class' => $event->exceptionClass,
            'file' => $event->file,
            'line' => $event->line,
            'context' => $event->context === [] ? new \stdClass : $event->context,
            'trace_id' => $event->traceId,
            'device_id' => $event->deviceId,
            'employee_uuid' => $event->employeeUuid,
            'app_version' => $event->appVersion,
            'occurrences' => $event->occurrences,
            'first_seen_at' => self::utc($event->firstSeenAt),
            'last_seen_at' => self::utc($event->lastSeenAt),
            'resolved_at' => $event->resolvedAt instanceof DateTimeImmutable
                ? self::utc($event->resolvedAt)
                : null,
            /*
             * `null` mientras siga abierto **o si se reabrio**: la escritura
             * vacia las dos columnas al recurrir el error. Y `null` tambien si
             * la cuenta que lo resolvio se borro despues (`nullOnDelete`), que es
             * la unica forma de que quede `resolved_at` sin autor.
             */
            'resolved_by' => ! $withResolver || $event->resolvedByUuid === null || $event->resolvedByName === null
                ? null
                : ['uuid' => $event->resolvedByUuid, 'name' => $event->resolvedByName],
        ];
    }

    /** ISO-8601 en UTC con microsegundos, el esquema `UtcTimestamp`. */
    public static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}

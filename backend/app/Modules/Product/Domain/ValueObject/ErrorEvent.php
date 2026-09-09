<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use DateTimeImmutable;

/**
 * Un grupo de errores tal y como se lee: **una fila de `error_events`**
 * (RF-PD-15, esquema `ErrorEvent` del contrato).
 *
 * ## Es la fila leida, no lo que se escribe
 *
 * Lo que se escribe es
 * {@see ErrorReport}, que es un informe
 * crudo con el mensaje sin sanear. Esto es lo que sale de la base de datos
 * despues del saneado, de la huella y de la agrupacion, y lo unico que el panel,
 * el comando y el paquete de diagnostico llegan a ver.
 *
 * ## Que instante es de que aparicion, y por que no es lo mismo
 *
 * - `message`, `exceptionClass`, `file` y `line` son los de la **primera**
 *   aparicion. Cambiarlos en cada repeticion haria que el grupo contara una
 *   historia distinta cada vez que alguien lo mira.
 * - `appVersion`, `traceId`, `deviceId` y `employeeUuid` son los de la
 *   **ultima**. Son las cuatro cosas que sirven para ir a buscar: la traza mas
 *   reciente es la que todavia estara en el log tecnico, y la version mas
 *   reciente responde a «¿esto sigue pasando despues de actualizar?».
 *
 * ## Ni un dato personal (regla dura 21)
 *
 * Las personas aparecen **solo** como `employeeUuid` y los quioscos solo como
 * `deviceId`. La unica excepcion es `resolvedBy`, que lleva el nombre de una
 * cuenta de **gestion**: no es un dato de plantilla, es quien pulso el boton, y
 * el contrato lo declara con la misma forma que el autor de una correccion
 * (`IncidentUser`). **No sale en el paquete de diagnostico**: alli el grupo pasa
 * por la lista de permitidos, que no lo incluye.
 *
 * ## Dominio puro
 *
 * Sin Eloquent y sin framework: el adaptador de persistencia construye esto a
 * mano desde el resultado de su consulta, igual que el resto del modulo.
 */
final readonly class ErrorEvent
{
    /**
     * @param  int  $id  Clave interna. Es la que viaja en la ruta de `resolve`, y es
     *                   deliberado: no identifica a nadie y el contrato la declara asi.
     * @param  array<string, scalar>  $context  Ya filtrado por {@see ErrorContextAllowlist}.
     * @param  string|null  $resolvedByUuid  UUID publico de la cuenta que lo resolvio.
     * @param  string|null  $resolvedByName  Nombre de esa cuenta de gestion. Ver el docblock.
     */
    public function __construct(
        public int $id,
        public string $fingerprint,
        public ErrorLevel $level,
        public ErrorSource $source,
        public ?string $module,
        public ?string $code,
        public string $message,
        public ?string $exceptionClass,
        public ?string $file,
        public ?int $line,
        public array $context,
        public ?string $traceId,
        public ?string $deviceId,
        public ?string $employeeUuid,
        public string $appVersion,
        public int $occurrences,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
        public ?DateTimeImmutable $resolvedAt,
        public ?string $resolvedByUuid,
        public ?string $resolvedByName,
    ) {}

    /**
     * Si el grupo sigue abierto.
     *
     * **Un grupo reabierto esta abierto**: cuando un error dado por resuelto
     * vuelve a ocurrir, la escritura vacia `resolved_at` y `resolved_by_user_id`
     * conservando `occurrences`. «Resuelto» significa «ya no pasa», no «ya no se
     * ve».
     */
    public function isOpen(): bool
    {
        return ! $this->resolvedAt instanceof DateTimeImmutable;
    }
}

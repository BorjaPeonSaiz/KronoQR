<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Lo que un productor sabe de un error en el momento de reportarlo (RF-PD-15,
 * tarea 5.12). Es la ENTRADA del historico, no una fila: quien lo recibe
 * —el adaptador del puerto `ErrorEventSink` de `Shared\Application`, que vive
 * en `Product`— sanea el mensaje y el contexto, calcula la huella y agrupa.
 * (Sin `{@see}` a proposito: Pint lo convertiria en un `use` de Application
 * dentro de Domain, y Deptrac lo denunciaria.)
 *
 * ## Es un informe crudo, y por eso NO se persiste tal cual
 *
 * `message` y `context` llegan aqui como los produjo la excepcion o el cliente.
 * Pueden llevar cualquier cosa; es el saneado de servidor de la decision 5 de
 * la ficha el que garantiza que a la tabla no llega ni un nombre, ni un correo,
 * ni una hora de fichaje (regla dura 21). Quien construye un `ErrorReport` no
 * tiene que sanear —aunque el quiosco ya lo hace— y no se confia en que lo
 * haya hecho.
 *
 * ## Lo que si es responsabilidad de quien lo construye
 *
 * - `source` y `level`: quien reporta sabe de donde viene y, con
 *   {@see ErrorLevel}, cuanto importa.
 * - `employeeUuid` y `deviceId` **solo como identificadores publicos** —nunca
 *   un nombre ni una clave interna—: tienen columna propia y no pasan por el
 *   contexto.
 * - `context` con valores escalares. Una estructura entera es la via por la
 *   que se cuela una fila de plantilla; el saneado descarta lo que no sea
 *   escalar y lo que no este en la lista de claves permitidas.
 */
final readonly class ErrorReport
{
    /**
     * @param  array<string, scalar|null>  $context  Datos tecnicos; se filtran por lista de permitidos al persistir.
     * @param  string|null  $code  Codigo estable (catalogo del cliente, o el que declare la excepcion).
     * @param  string|null  $exceptionClass  Clase de la excepcion en el servidor; `null` en un cliente.
     * @param  string|null  $file  Fichero del punto de fallo, relativo a la raiz; `null` en un cliente.
     * @param  string|null  $traceId  Traza W3C (32 hexadecimales) si la hay.
     * @param  string|null  $deviceId  UUID publico del quiosco si el error viene de uno.
     * @param  string|null  $employeeUuid  UUID publico del empleado implicado, si lo hay. Nunca el nombre.
     * @param  string|null  $module  Modulo del monolito (`attendance`, `kiosk`, …) si se conoce.
     */
    public function __construct(
        public ErrorSource $source,
        public ErrorLevel $level,
        public string $message,
        public DateTimeImmutable $occurredAt,
        public string $appVersion,
        public array $context = [],
        public ?string $code = null,
        public ?string $exceptionClass = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $traceId = null,
        public ?string $deviceId = null,
        public ?string $employeeUuid = null,
        public ?string $module = null,
    ) {}
}

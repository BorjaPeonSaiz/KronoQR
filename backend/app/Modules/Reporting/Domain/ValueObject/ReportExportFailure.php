<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Por que no se genero un informe en diferido (**RF-IN-06**, regla dura 21).
 *
 * ## Un codigo estable, no el nombre de una clase de PHP
 *
 * `failure_reason` la lee **quien pidio el informe** en el panel, y la traduce
 * el cliente TypeScript. `RuntimeException` no le dice nada y
 * `Illuminate\Database\QueryException` le dice algo equivocado: parece un fallo
 * del producto cuando casi siempre es un disco lleno o una consulta que no cupo
 * en su `statement_timeout`. Peor: el nombre de la clase es un detalle de
 * implementacion, asi que refactorizar el escritor cambiaria el texto que ve
 * quien lo lee.
 *
 * Y sobre todo: **un mensaje de PostgreSQL puede llevar dentro el valor de una
 * fila** —un nombre, un codigo de empleado— y esta fila se serializa en la API
 * (regla dura 21).
 *
 * Cinco codigos, y cada uno lleva a una accion distinta:
 *
 * - `write_failed` — no se pudo escribir el fichero. Crear el directorio, abrir
 *   el fichero, permisos, disco lleno. Lo que hay que mirar es `df -h` y los
 *   permisos de `REPORTING_EXPORT_PATH`.
 * - `query_timeout` — PostgreSQL canceló la consulta al agotar
 *   `REPORTING_EXPORT_TIMEOUT_SECONDS`. Es el fallo propio de esta tarea y no lo
 *   tiene la exportacion integra: aqui la consulta cruza la plantilla con el
 *   calendario y un rango de un año sobre una plantilla grande puede no caber.
 *   La salida es pedir dos periodos mas cortos.
 * - `database_error` — cualquier otro fallo de la base de datos.
 * - `stale` — nadie lo termino. El trabajador de cola murio, o el servidor se
 *   paro a mitad (una actualizacion, un `docker compose down`). No hay nada
 *   roto: se vuelve a pedir.
 * - `unexpected` — cualquier otra cosa. Es la unica que justifica generar un
 *   paquete de diagnostico y abrir incidencia.
 *
 * **La clase real de la excepcion no se pierde**: va al log tecnico junto al
 * `uuid` de la exportacion. Lo que nunca sale de ahi es su MENSAJE.
 *
 * El catalogo esta escrito tres veces y las tres atadas: aqui, en el `CHECK` de
 * la migracion —compuesto desde este enumerado— y en el contrato.
 */
enum ReportExportFailure: string
{
    case WriteFailed = 'write_failed';

    case QueryTimeout = 'query_timeout';

    case DatabaseError = 'database_error';

    case Stale = 'stale';

    case Unexpected = 'unexpected';

    /**
     * El catalogo, para el `CHECK` de la migracion y para la prueba que lo ata
     * al contrato.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $failure): string => $failure->value, self::cases());
    }
}

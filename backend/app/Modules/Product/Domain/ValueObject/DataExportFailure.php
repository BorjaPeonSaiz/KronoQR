<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Por que no se genero una exportacion integra (**RF-PD-14**, RL-20, regla dura
 * 21).
 *
 * ## Un codigo estable, no el nombre de una clase de PHP
 *
 * `failure_reason` la lee **una persona de informatica de un hotel** en el panel,
 * y la traduce el cliente TypeScript. `RuntimeException` no le dice nada, y
 * `Illuminate\Database\QueryException` le dice algo equivocado: parece un fallo
 * del producto cuando casi siempre es un disco lleno. Peor todavia: el nombre de
 * la clase es un detalle de implementacion, asi que refactorizar el escritor
 * cambiaria el texto que ve el cliente y rompería su traduccion.
 *
 * Cuatro codigos, y cada uno lleva a una accion distinta:
 *
 * - `write_failed` — **no se pudo escribir**. Crear el directorio, abrir un
 *   fichero, cerrar el ZIP, permisos, disco lleno. Es el fallo mas probable con
 *   diferencia, y lo que hay que mirar es `df -h` y los permisos de
 *   `PRODUCT_DATA_EXPORT_PATH`.
 * - `database_error` — la base de datos fallo al recorrerla. Lo que hay que
 *   mirar es si PostgreSQL esta sano (`product:doctor`).
 * - `stale` — nadie la termino. El trabajador de cola murio, o el servidor se
 *   paro a mitad (una actualizacion, un `docker compose down`). No hay nada roto:
 *   se vuelve a pedir.
 * - `unexpected` — cualquier otra cosa. Es la unica que justifica generar un
 *   paquete de diagnostico y abrir incidencia.
 *
 * **La clase real de la excepcion no se pierde**: va al log tecnico junto al
 * `uuid` de la exportacion. Lo que nunca sale de ahi es su MENSAJE, que puede
 * llevar dentro el valor de una fila (regla dura 21).
 *
 * El catalogo esta escrito tres veces y las tres atadas: aqui, en el `CHECK` de
 * la migracion —compuesto desde este enum— y en el contrato.
 */
enum DataExportFailure: string
{
    case WriteFailed = 'write_failed';

    case DatabaseError = 'database_error';

    case Stale = 'stale';

    case Unexpected = 'unexpected';

    /**
     * El catalogo, para el `CHECK` de la migracion y para la prueba que lo ata al
     * contrato.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $failure): string => $failure->value, self::cases());
    }
}

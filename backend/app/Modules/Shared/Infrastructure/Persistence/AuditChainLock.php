<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;

/**
 * La clave del candado consultivo **global** de la cadena de `audit_log`
 * (ADR-010, doc 02 §7.4), en un solo sitio.
 *
 * **Existe porque el par de enteros tiene que ser el mismo en todos los caminos
 * que lo toman.** Lo toma el escritor de la cadena antes de leer el ultimo hash
 * y escribir el siguiente; y lo toma, por delante de cualquier fila, todo
 * proceso que vaya a escribir algo que se audita —para adquirir los candados en
 * el mismo orden que el fichaje y no cerrar un ciclo con el—. Con la clave
 * copiada en dos clases, el dia que una cambiara los dos caminos dejarian de
 * serializarse entre si **sin que nada fallara**: la cadena naceria bifurcada de
 * madrugada y la alerta critica de RS-07 sonaria por una rotura que nadie causo.
 *
 * El espacio de `pg_advisory_lock` es global a la base de datos, asi que el par
 * se elige a mano y se documenta: si otro proceso tomara este mismo par para
 * otra cosa, se serializaria contra los fichajes sin que se viera la relacion.
 *
 * `pg_advisory_xact_lock` **se suelta con el commit**, nunca a mano, y se
 * concede otra vez al que ya lo tiene en la misma transaccion: por eso tomarlo
 * dos veces —una por delante y otra dentro del asiento— no cuesta una espera.
 */
final class AuditChainLock
{
    /** 'KR' de KronoQR. */
    public const int LOCK_NAMESPACE = 0x4B52;

    /** 'AU' de `audit_log`. */
    public const int LOCK_RESOURCE = 0x4155;

    /**
     * Toma el candado en esa conexion. Exige una transaccion abierta para
     * significar algo (ver la cabecera).
     */
    public static function takeOn(ConnectionInterface $connection): void
    {
        $connection->statement(
            'SELECT pg_advisory_xact_lock(?, ?)',
            [self::LOCK_NAMESPACE, self::LOCK_RESOURCE],
        );
    }
}

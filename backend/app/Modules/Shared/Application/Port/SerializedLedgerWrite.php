<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * Ejecuta una escritura **en el mismo orden de candados que todo lo que audita**
 * (ADR-010, ADR-027, regla dura 6).
 *
 * ## El problema que resuelve, que es un abrazo mortal
 *
 * Toda accion con relevancia legal pasa por el candado consultivo **global** de
 * la cadena de `audit_log`: es un solo candado porque la cadena es una sola
 * secuencia encadenada por hash. Y el camino del fichaje lo toma **antes** de
 * escribir sus proyecciones: el agregado registra primero el hecho —la entrada o
 * la salida, que se audita— y despues el recalculo de `daily_totals`, de modo que
 * el orden efectivo es *candado de la cadena → fila de la proyeccion*.
 *
 * Cualquier otro proceso que necesite las dos cosas y las tome **al reves**
 * —primero la fila, despues el asiento— cierra un ciclo con el fichaje. No es una
 * carrera improbable que se resuelva reintentando: es un abrazo mortal, y
 * PostgreSQL lo rompe matando a una de las dos transacciones a
 * `deadlock_timeout`. Si la victima es el fichaje, un empleado recibe un error al
 * pasar la tarjeta por una tarea de mantenimiento nocturna, que es exactamente lo
 * que la regla dura 19 prohibe.
 *
 * **La solucion no es sobrevivir al abrazo, es no crearlo**: quien escriba algo
 * que va a auditarse toma el candado de la cadena **primero**, envolviendo su
 * trabajo con este puerto, y despues toma las filas que necesite. Con un orden
 * global unico no hay ciclo posible.
 *
 * ## Por que es reentrante y por tanto barato
 *
 * `pg_advisory_xact_lock` se concede otra vez al que ya lo tiene dentro de la
 * misma transaccion, asi que el asiento que se escriba despues —que vuelve a
 * pedirlo— no espera. El coste real es que el candado global se retiene durante
 * todo el trabajo envuelto en lugar de solo durante el `INSERT` del asiento: por
 * eso se envuelve lo minimo, y nunca un bucle sobre la plantilla.
 *
 * ## Por que vive en `Shared`
 *
 * Porque lo necesitan modulos que no pueden importarse entre si —`Attendance`
 * corrige la proyeccion, `Compliance` escribe la cadena— y la clave del candado
 * tiene que ser **la misma**. Dos claves serian dos ordenes de adquisicion
 * distintos, que es el mismo abrazo mortal por otra puerta.
 */
interface SerializedLedgerWrite
{
    /**
     * Ejecuta `$work` dentro de una transaccion que **ya tiene** el candado de la
     * cadena de auditoria.
     *
     * La transaccion la abre el adaptador: un candado de transaccion tomado sin
     * transaccion se suelta al terminar la sentencia y no serializa nada, y
     * dejarlo al criterio de quien llama seria dejar que la garantia dependa de
     * acordarse. Si `$work` lanza, la transaccion revierte entera y la excepcion
     * sale hacia arriba sin tocar.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult
     */
    public function withChainLock(callable $work): mixed;
}

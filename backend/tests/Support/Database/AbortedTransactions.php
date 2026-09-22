<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Database\ConnectionInterface;
use Mockery;
use Mockery\MockInterface;

/**
 * Una conexion cuya transaccion **no llega a ejecutar su cuerpo**, para probar
 * sin base de datos que un caso de uso no deja nada fuera de ella (ADR-027,
 * regla dura 6).
 *
 * ## Que afirma, y por que no basta {@see ImmediateTransactions}
 *
 * Aquella ejecuta la closure, asi que una prueba pasa igual si el caso de uso
 * publica su evento **dentro** de la transaccion o justo despues de ella. Y la
 * diferencia es toda: ADR-027 exige que si el asiento de `audit_log` falla, la
 * accion auditada **no se confirme**. Con la escritura fuera, una cuenta podria
 * quedar dada de baja sin asiento —o con la contrasena ya sustituida y sin
 * traza de quien lo hizo—, que es exactamente el estado que la regla dura 6 no
 * admite.
 *
 * Esta conexion simula el peor caso —la transaccion que no llega a correr, o que
 * revierte entera— del unico modo que una prueba unitaria puede: **no invocando
 * la closure**. Lo que la prueba observa despues en los puertos es, por
 * definicion, lo que el caso de uso hace fuera de la transaccion. Si es nada,
 * esta bien atado.
 *
 * No sustituye a la prueba de integracion del rollback de verdad: que PostgreSQL
 * revierta se afirma con PostgreSQL delante.
 */
final class AbortedTransactions
{
    /**
     * @return ConnectionInterface&MockInterface
     */
    public static function connection(): ConnectionInterface
    {
        /** @var ConnectionInterface&MockInterface $connection */
        $connection = Mockery::mock(ConnectionInterface::class);

        $connection->shouldReceive('transaction')->andReturnNull();

        return $connection;
    }
}

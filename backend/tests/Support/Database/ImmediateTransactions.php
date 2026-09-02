<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Database\ConnectionInterface;
use Mockery;
use Mockery\MockInterface;

/**
 * Una conexion que **ejecuta la transaccion y nada mas**, para pruebas
 * unitarias.
 *
 * ## Por que existe
 *
 * Varios casos de uso reciben `ConnectionInterface` porque su escritura y su
 * asiento de auditoria tienen que ir juntos (ADR-027, regla dura 6): si el
 * asiento falla, el cambio no se confirma. Esa dependencia es correcta y no se
 * va a quitar, pero convierte en imposible probar el caso de uso sin base de
 * datos — que es justo donde vive la regla que se quiere afirmar.
 *
 * Esto resuelve el nudo sin tocar el diseño: `transaction()` invoca su closure y
 * devuelve lo que devuelva. **No simula el rollback**, y no debe: lo que una
 * prueba unitaria comprueba aqui es que el caso de uso publica lo que tiene que
 * publicar y devuelve lo que tiene que devolver. Que la transaccion de verdad
 * revierta es una afirmacion sobre PostgreSQL, y esa se prueba con PostgreSQL
 * delante.
 *
 * ## Por que un doble y no una clase escrita a mano
 *
 * `ConnectionInterface` declara una treintena de metodos. Una implementacion
 * completa serian trescientas lineas de cuerpos vacios que nadie lee y que hay
 * que mantener cada vez que Laravel amplie la interfaz. Con el doble, **lo que
 * no se declara falla al llamarse**, que es exactamente el comportamiento
 * deseado: si un caso de uso empieza a consultar la base de datos por su cuenta,
 * la prueba unitaria se rompe y lo dice.
 */
final class ImmediateTransactions
{
    /**
     * @return ConnectionInterface&MockInterface
     */
    public static function connection(): ConnectionInterface
    {
        /** @var ConnectionInterface&MockInterface $connection */
        $connection = Mockery::mock(ConnectionInterface::class);

        $connection->shouldReceive('transaction')
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($connection));

        return $connection;
    }
}

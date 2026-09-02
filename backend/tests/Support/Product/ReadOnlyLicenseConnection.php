<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Una conexion que **falla al anotar `last_verified_at`** y funciona para todo
 * lo demas.
 *
 * ## Que simula, y por que hace falta simularlo
 *
 * Lo que devuelve PostgreSQL con la base en solo lectura, el disco lleno o el
 * rol sin permiso de `UPDATE`. Es el escenario que convierte una escritura de
 * diagnostico en un `500` si el adaptador la deja subir — y ocurre en el camino
 * de `GET /api/v1/license` y de `license:show`, que son las dos superficies
 * desde las que alguien intenta averiguar que le pasa a su licencia.
 *
 * **No se puede probar provocando el fallo de verdad.** Un `UPDATE` que falla en
 * PostgreSQL deja la transaccion abortada, y la suite corre dentro de una
 * transaccion: el resto de la peticion fallaria por eso y no por lo que se
 * quiere observar. En produccion no hay transaccion envolvente y la peticion
 * sigue su curso, que es justo el comportamiento que se quiere fijar. Asi que el
 * fallo se lanza **antes** de tocar la base de datos.
 *
 * ## Comparte el PDO de la conexion real
 *
 * De modo que las lecturas —la fila de `license`, el recuento de plantilla— son
 * las de verdad y participan en la misma transaccion de la prueba. Lo unico
 * postizo es la sentencia que se quiere ver fallar.
 */
final class ReadOnlyLicenseConnection extends PostgresConnection
{
    public static function wrapping(Connection $real): self
    {
        /** @var array<string, mixed> $config */
        $config = $real->getConfig();

        $fake = new self(
            $real->getPdo(),
            $real->getDatabaseName(),
            $real->getTablePrefix(),
            $config,
        );

        $fake->setQueryGrammar($real->getQueryGrammar());
        $fake->setPostProcessor($real->getPostProcessor());

        // El despachador puede no estar: en una conexion sin eventos, propagar
        // el `null` seria un error de tipo. Sin el, la conexion sigue sirviendo
        // para lo unico que hace falta aqui, que es leer y fallar al escribir.
        $events = $real->getEventDispatcher();

        if ($events instanceof Dispatcher) {
            $fake->setEventDispatcher($events);
        }

        return $fake;
    }

    /**
     * @param  \Closure|Builder|string  $table
     * @param  string|null  $as
     */
    public function table($table, $as = null): Builder
    {
        $builder = new TouchFailingBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());

        return $builder->from($table, $as);
    }
}

/**
 * Constructor de consultas que se niega a escribir `last_verified_at`.
 *
 * Solo esa columna y solo cuando va sola: la activacion escribe la fila entera y
 * tiene que seguir funcionando, porque **esa si es una escritura deliberada** y
 * debe fallar en voz alta si no se puede hacer.
 */
final class TouchFailingBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        if (array_keys($values) === ['last_verified_at']) {
            throw new RuntimeException('SQLSTATE[25006]: cannot execute UPDATE in a read-only transaction');
        }

        return parent::update($values);
    }
}

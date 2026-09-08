<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DoctorFinding;

/**
 * Una familia de comprobaciones de `product:doctor` (**RF-PD-13**).
 *
 * ## Una sonda por familia, y no un comando de mil lineas
 *
 * Base de datos, colas, correo, TLS, permisos, disco, aplicacion y licencia. La
 * frontera es la del recurso que se comprueba, porque es la frontera por la que
 * fallan: si Postgres no responde, fallan las cuatro comprobaciones de base de
 * datos y **ninguna otra**, y el informe tiene que enseñarlo asi.
 *
 * ## Una sonda NUNCA lanza
 *
 * Es la unica regla que este puerto impone. `doctor` se ejecuta justamente
 * cuando algo esta roto —lo llaman `install.sh` y `update.sh`—, y una excepcion
 * sin capturar dejaria al cliente con un volcado de pila en lugar de un informe.
 * Lo que no se puede comprobar se declara como hallazgo, y quien recorre las
 * sondas —`RunDoctorHandler`— pone una red debajo por si alguna se olvida.
 */
interface DoctorProbe
{
    /**
     * Prefijo comun de los identificadores que devuelve: `database`, `queue`...
     *
     * Sirve para dos cosas: agrupar el informe legible y permitir que el
     * recolector diga **que familia** se cayo si una sonda revienta.
     */
    public function family(): string;

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array;
}

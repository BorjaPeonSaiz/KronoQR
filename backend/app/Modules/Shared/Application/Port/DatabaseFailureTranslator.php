<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use Throwable;

/**
 * Traduce un fallo de la base de datos al vocabulario de los casos de uso
 * ({@see DatabaseFailure}).
 *
 * Un caso de uso que tiene que distinguir «no pude tomar el candado» de «la
 * escritura fallo», o «la consulta se corto por tiempo» de «se cayo la base de
 * datos», pregunta aqui. Leer el `SQLSTATE` o nombrar los tipos del driver es
 * cosa del adaptador: en `Application` ataba la decision al motor y al driver
 * (el codigo de una misma excepcion es el `SQLSTATE` en unos y un entero en
 * otros).
 */
interface DatabaseFailureTranslator
{
    /**
     * El fallo traducido, o `null` si no viene de la base de datos.
     */
    public function translate(Throwable $failure): ?DatabaseFailure;
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use SensitiveParameter;

/**
 * Hash y comprobacion de las contrasenas de gestion (RF-ID-01, RF-ID-10).
 *
 * ## Por que es un puerto y no el cast `hashed` del modelo
 *
 * **Para poder hashear ANTES del candado de la cadena de auditoria.** `bcrypt`
 * con coste 12 tarda decenas de milisegundos, y todo lo que se escribe con
 * asiento pasa por el candado global de ADR-010 — el mismo por el que pasa cada
 * fichaje. Si el hash se calculara dentro, al guardar la fila, cada alta y cada
 * restablecimiento detendrian los fichajes de la instalacion durante ese tiempo.
 * Es el mismo criterio con el que el PIN del empleado se sella fuera del candado
 * (`Workforce`, tarea 1.13).
 *
 * El algoritmo y el coste son los de la configuracion de la aplicacion: el
 * adaptador no decide nada, solo los aplica.
 */
interface PasswordHasher
{
    public function hash(#[SensitiveParameter] string $password): string;

    public function matches(#[SensitiveParameter] string $password, string $hash): bool;
}

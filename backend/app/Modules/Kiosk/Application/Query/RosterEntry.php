<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Query;

/**
 * Una linea del padron tal y como sale hacia el quiosco: **hash de la tarjeta y
 * nombre minimo** (esquema `KioskRosterEntry`, doc 02 §7.3).
 *
 * Se parece a `Shared\Domain\ValueObject\RosterMember` y no es lo mismo, y la
 * diferencia es justo la que importa: `RosterMember` lleva la **clave interna**
 * del empleado, que sirve para cruzar tablas dentro del servidor y **no puede
 * salir por la API** —un identificador secuencial dice cuanta gente hay y en que
 * orden entro—. Esta clase es lo que queda despues de cambiar esa clave por el
 * hash de su tarjeta: dos campos, ninguno secuencial, ninguno reversible.
 *
 * Que sean dos clases y no una con un campo opcional es deliberado: con una sola,
 * la clave interna viajaria hasta el `Resource` y bastaria un descuido —un
 * `toArray()` que devuelva todo— para publicarla.
 */
final readonly class RosterEntry
{
    public function __construct(
        /** `SHA-256` en hexadecimal del token impreso (`credentials.secret_hash`). */
        public string $tokenHash,
        /** Nombre de pila e inicial del primer apellido. **Jamas en un log** (regla dura 21). */
        public string $displayName,
    ) {}
}

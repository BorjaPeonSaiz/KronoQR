<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Shared\Domain\ValueObject\PinLength;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Dos recuentos que `product:doctor` necesita para decir cuanto falta de la
 * transicion de ADR-050 (RF-ID-09, RS-06).
 *
 * ## Solo numeros, y por diseño del puerto
 *
 * Ninguno de los dos metodos puede devolver a QUIEN: el informe viaja en el
 * paquete de diagnostico (ADR-020, regla dura 21), y decir que compañeros
 * conservan el PIN corto o que responsables no tienen segundo factor es decir a
 * quien atacar. RRHH ya sabe a quien ha entregado PIN; lo que no sabe es
 * cuantos le faltan.
 */
interface AccessHardeningFacts
{
    /**
     * Personas EN ALTA con un PIN emitido de esta longitud (`employees.pin_length`).
     *
     * Las de baja no cuentan: no pueden entrar al portal (RF-ID-07), y
     * contarlas pediria restablecimientos que nadie va a recoger.
     */
    public function activeEmployeesWithPinOf(PinLength $length): int;

    /**
     * Cuentas de gestion ACTIVAS con alguno de estos roles y sin segundo factor
     * confirmado.
     *
     * Son las que tienen abierta la ventana de auto-alta del TOTP (doc 07 §6,
     * M1 de la revision de ADR-050): quien tenga solo su contraseña puede dar
     * de alta su propio segundo factor antes que el titular.
     *
     * @param  list<UserRole>  $roles
     */
    public function activeAccountsWithoutSecondFactor(array $roles): int;
}

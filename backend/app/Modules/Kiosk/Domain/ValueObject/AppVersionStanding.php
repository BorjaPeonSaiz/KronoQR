<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use App\Modules\Kiosk\Domain\Policy\AppVersionPolicy;

/**
 * Donde esta la aplicacion de un quiosco respecto de la version del servidor
 * (RF-KI-07, RF-PA-07). Lo decide
 * {@see AppVersionPolicy}.
 */
enum AppVersionStanding: string
{
    /** Mismo nucleo `X.Y.Z` que el servidor. */
    case Current = 'current';

    /**
     * Anterior al servidor, o una version que no se puede leer (no SemVer,
     * vacia, `null` o `0.0.0`). Es lo unico que pide una accion: que la tablet
     * se actualice (`app_version_behind`).
     */
    case Behind = 'behind';

    /**
     * Posterior al servidor: el servidor ha vuelto atras restaurando la copia
     * (ADR-054) y la tablet conserva el build nuevo. Se informa —la sonda de
     * `product:doctor` lo dice— pero **no se pide nada**: la PWA que sirve el
     * servidor es la de su version, asi que la tablet se pone en ella sola en
     * su siguiente actualizacion, y mientras tanto el codigo del servidor
     * sigue aceptando sus fichajes por contrato aditivo.
     */
    case Ahead = 'ahead';

    /**
     * El servidor no tiene una version con la que comparar: desconocida
     * (`0.0.0`) o un build de desarrollo (`-dev`). No se juzga a nadie.
     */
    case Unchecked = 'unchecked';
}

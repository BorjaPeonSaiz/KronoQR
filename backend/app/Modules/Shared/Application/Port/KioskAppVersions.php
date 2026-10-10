<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\KioskAppVersionSurvey;

/**
 * La version de la PWA de cada quiosco frente a la del servidor, para la sonda
 * `kiosk.app_version` de `product:doctor` (RF-KI-07, RF-PD-13).
 *
 * Vive en `Shared` porque lo implementa `Kiosk` —que es quien tiene los
 * quioscos y la regla (`AppVersionPolicy`)— y lo consume `Product`, y los dos
 * modulos no pueden importarse entre si. Es la misma forma que
 * {@see ErrorEventSink}: el puerto aqui, el caso de uso que lo implementa en el
 * modulo dueño del dato.
 *
 * Solo cuenta los quioscos **activos con latido reciente** —los que no han
 * pasado el plazo de silencio de `kiosk:health`—: de una tablet callada la
 * version es lo de menos, y de eso ya avisan la salud del quiosco y sus alertas.
 */
interface KioskAppVersions
{
    public function survey(): KioskAppVersionSurvey;
}

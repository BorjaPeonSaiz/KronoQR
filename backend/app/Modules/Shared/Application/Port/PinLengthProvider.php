<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\PinLength;

/**
 * La longitud con la que esta instalacion **emite** los PIN (RF-ID-09, ADR-050).
 *
 * La clave es `IDENTITY_PIN_LENGTH` y vive en `installation_settings`, que es de
 * `Product`; quien la consume es el generador de `Workforce`, que no puede
 * importar `Product` (doc 02 §1.6, verificado por Deptrac). Por eso el puerto
 * esta aqui y su adaptador en `Product/Infrastructure/Adapter`, igual que
 * {@see KioskServiceCodeProvider} y {@see WeeklySummaryPreference} (ADR-025).
 *
 * **Solo sirve para emitir.** Nadie la usa para comprobar un PIN tecleado: la
 * comprobacion compara el hash y no depende de la longitud, que es lo que deja
 * seguir valiendo a los PIN emitidos antes de cambiarla.
 */
interface PinLengthProvider
{
    public function current(): PinLength;
}

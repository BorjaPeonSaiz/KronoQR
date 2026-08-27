<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * Entrega la politica de PIN de esta instalacion (RF-ID-09, regla dura 13).
 *
 * Es un puerto por la misma razon que {@see
 * \App\Modules\Shared\Application\Port\OperationalSettingsProvider}: el caso de
 * uso recibe el umbral **ya resuelto** y no consulta la configuracion. Con esto,
 * probar que el generador rechaza los patrones triviales no exige montar el
 * `config()` de Laravel, y endurecer la lista para un cliente no toca el codigo.
 */
interface PinPolicyProvider
{
    public function policy(): PinPolicy;
}

<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Port;

use App\Modules\Kiosk\Domain\ValueObject\RenewedDeviceToken;

/**
 * La rotacion del token del quiosco, vista desde el latido (RF-ID-04,
 * doc 02 §7.3, ADR-044).
 *
 * ## Por que un puerto, si `Kiosk/Application` ya alcanza `Identity/Application`
 *
 * El emparejamiento llama a `IssueDeviceToken` directamente y esta bien: si la
 * emision falla, la tablet vuelve a pedir codigo. Aqui no puede fallar nada: el
 * latido es la unica señal de que la tablet sigue viva, y **una rotacion rota no
 * puede tumbarlo** (regla dura 19). Ese contrato —«nunca lanza»— es el que este
 * puerto declara y el que hace que `RecordHeartbeat` no necesite ni `try` ni
 * rama de fallo, igual que con `ErrorEventSink`. Y es lo que permite
 * probar ese desenlace con un doble, que con el caso de uso final de `Identity`
 * no se puede.
 */
interface DeviceTokenRenewal
{
    /**
     * El relevo del token que firmo el latido, o `null` si no toca —el caso
     * normal— **o si la rotacion ha fallado**. En el segundo caso el adaptador
     * deja constancia (log, `error_events`, metrica) y el latido siguiente lo
     * vuelve a intentar.
     *
     * **Nunca lanza.**
     *
     * @param  string  $deviceUuid  Identificador publico del quiosco.
     * @param  int  $presentedTokenId  El token con el que se firmo el latido.
     */
    public function renewIfDue(string $deviceUuid, int $presentedTokenId): ?RenewedDeviceToken;
}

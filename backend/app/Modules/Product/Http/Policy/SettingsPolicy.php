<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Policy;

use App\Modules\Product\Http\Resource\SettingResource;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver y cambiar la configuracion de la instalacion (RF-PD-01,
 * regla dura 18).
 *
 * ## Solo `admin`, y las dos mitades dicen lo mismo
 *
 * El Anexo B del doc 01 marca `GET`/`PATCH /settings` como `[rol: admin]` y el
 * §7.3 del doc 02 concede `settings:*` unicamente al administrador de
 * instalacion. El middleware `ability` comprueba el ambito y esta policy el rol:
 * dos controles distintos que aqui coinciden, que es como tienen que ser. Sin la
 * policy, bastaria un error al conceder ambitos —o un token emitido a mano— para
 * que `rrhh` cambiara el anti-rebote.
 *
 * **`rrhh` no entra, y no es un descuido.** RRHH corrige fichajes y gestiona
 * plantilla; cambiar el umbral con el que se calculan las horas de todo el
 * centro es otra potestad. Quien puede corregir un tramo deja traza sobre **una**
 * jornada; quien puede mover el anti-rebote cambia el calculo de **todas** las
 * siguientes.
 *
 * **El `auditor` tampoco**, y es el caso que mas conviene entender: es el rol
 * que mira, y aun asi no lee esta pantalla. Lo que necesita para su trabajo —que
 * umbral regia el 14 de marzo y quien lo cambio— esta en `audit_log`, al que si
 * llega con `audit:read`, y ahi es historico y encadenado en lugar de ser el
 * valor de hoy.
 *
 * ## Dos metodos aunque el conjunto de roles sea el mismo
 *
 * Para que la matriz de autorizacion negativa pruebe cada endpoint por separado
 * (regla dura 18): un `authorize()` que devolviera `true` en uno solo de los dos
 * seria invisible desde el otro.
 */
final class SettingsPolicy
{
    /**
     * Los roles que pueden leer y escribir la configuracion. Uno, hoy.
     *
     * @return list<UserRole>
     */
    private static function administrators(): array
    {
        return [UserRole::ADMIN];
    }

    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }

    public function update(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }

    /**
     * Si ademas puede tocar una clave **confidencial** (tarea 3.3).
     *
     * ## El fabricante configura la instalacion; no se lleva sus secretos
     *
     * Un acceso de soporte con alcance `configuration` pasa `update()` a
     * proposito: cambiar los umbrales o el idioma del cliente es justo para lo
     * que el cliente concede ese acceso (RF-PD-11). Una clave `confidential` no
     * es eso. Hoy es `KIOSK_SERVICE_CODE`, el codigo con el que se abre la
     * pantalla de mantenimiento de todas las tablets del hotel (RF-KI-08):
     * escribirlo daria al fabricante una llave que el cliente no le dio, y que
     * no caduca con la concesion — porque la tablet la guarda.
     *
     * Y **leerlo tampoco**: {@see SettingResource}
     * sirve `value: null` y `redacted: true` al mismo actor. Las dos mitades
     * dicen lo mismo, que es lo que exige ADR-020 y la regla dura 16.
     *
     * ## Un tercer metodo, y no un `if` dentro de `update()`
     *
     * Porque son dos preguntas distintas —«¿puede escribir configuracion?» y
     * «¿puede escribir ESTA configuracion?»— y la matriz de autorizacion negativa
     * tiene que poder probarlas por separado (regla dura 18). Fundirlas dejaria
     * a un actor de soporte sin poder cambiar un idioma, que es lo contrario de
     * lo que la concesion `configuration` significa.
     */
    public function updateConfidential(ManagementActor $actor): bool
    {
        return $this->update($actor) && ! $actor->isSupportActor();
    }
}

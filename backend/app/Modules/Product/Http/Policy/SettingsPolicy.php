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

    /**
     * Si ademas puede tocar una clave **reservada al cliente** (tarea 3.5,
     * RF-AT-12; ampliada en la 3.12, RF-PR-05).
     *
     * Son las que no deciden «como funciona el producto» sino **de que responde
     * el hotel y a donde van los datos de su gente**. Hoy son dos y por dos
     * motivos distintos:
     *
     * | Clave | Que decide |
     * |---|---|
     * | `ATTENDANCE_BREAK_CLOCKING` | Que jornadas se marcan: reactiva o suspende RN-12 (RF-AT-12). |
     * | `WEEKLY_SUMMARY_EMAIL` | Que cada lunes salgan por SMTP nombres y horas de la plantilla hacia buzones (RF-PR-05). |
     *
     * El fabricante configura la instalacion y diagnostica; no decide el
     * cumplimiento de su cliente ni enciende una salida de datos personales de
     * su plantilla (ADR-020, regla dura 16).
     *
     * ## El fabricante no decide el cumplimiento del cliente
     *
     * Es la misma frontera que ya traza `ComplianceProfilePolicy`, que le niega
     * al actor de soporte el perfil de cumplimiento entero: los umbrales legales
     * son del hotel, responden a su convenio y de ellos depende que jornadas se
     * marcan. `ATTENDANCE_BREAK_CLOCKING` vive en `installation_settings` y no
     * en `compliance_profiles` por razones de esquema —es un interruptor, no un
     * umbral—, pero hace exactamente eso: activarlo **reactiva RN-12** y desde la
     * noche siguiente se abren incidencias `missing_break` contra la plantilla
     * del cliente; desactivarlo las apaga. Un actor de soporte que pudiera
     * moverlo estaria decidiendo, desde fuera, de que responde el hotel ante una
     * inspeccion.
     *
     * Y al reves que en el perfil, la consecuencia de apagarlo es peor que la de
     * encenderlo: silenciar avisos de descanso en la instalacion de un cliente
     * es justo lo que nadie ajeno debe poder hacer (ADR-020, regla dura 16).
     *
     * ## Por que NO se resuelve marcandola `confidential`
     *
     * Porque `confidential` significa «este valor es un secreto»: ademas del
     * `403` al escribir, {@see SettingResource} lo sirve como `value: null` con
     * `redacted: true`. Este ajuste no es ningun secreto —el panel lo enseña, la
     * guia de RRHH lo explica y el latido lo reparte a todas las tablets—, y
     * redactarlo dejaria la pantalla de ajustes sin poder pintar su estado. Son
     * dos propiedades distintas de una clave y confundirlas romperia una de las
     * dos.
     *
     * ## Ni por `SettingImpact::COMPLIANCE_REVIEW`
     *
     * Cuatro claves lo llevan —la jornada maxima, la tolerancia de desfase, el
     * transito minimo y el fichaje de pausa— y las otras tres son parametros
     * operativos que el soporte SI debe poder ajustar mientras diagnostica
     * (RF-PD-11). Un impacto describe la consecuencia de un cambio, no quien
     * puede hacerlo.
     *
     * Lo mismo vale al reves para la segunda clave: `WEEKLY_SUMMARY_EMAIL` es la
     * unica `DATA_DISCLOSURE` de hoy, pero la lista sigue siendo explicita y no
     * derivada del impacto. Si mañana hubiera una salida de datos que el
     * fabricante si debiera poder apagar mientras diagnostica —un correo que se
     * ha desbocado, por ejemplo—, derivar la puerta del impacto lo impediria sin
     * que nadie lo hubiera decidido.
     */
    public function updateCustomerReserved(ManagementActor $actor): bool
    {
        return $this->update($actor) && ! $actor->isSupportActor();
    }
}

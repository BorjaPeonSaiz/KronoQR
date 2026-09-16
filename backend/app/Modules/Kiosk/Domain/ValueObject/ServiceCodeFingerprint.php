<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

/**
 * La huella del codigo de servicio con el que se abre la pantalla de
 * diagnostico de la tablet (**RF-KI-08**, tarea 3.3, decision 6).
 *
 * ## Que es y por que viaja una huella y no el codigo
 *
 * SHA-256 en hexadecimal de `"{uuid del dispositivo}:{codigo}"`. El servidor la
 * devuelve en cada `KioskHeartbeat`; la tablet la guarda y comprueba el codigo
 * **en local**, de modo que la pantalla de diagnostico funciona sin red —que es
 * justo cuando hace falta— y **el codigo nunca viaja en claro**.
 *
 * ## El UUID va dentro, y no es decoracion
 *
 * Sin el, todas las tablets de la instalacion guardarian la misma huella y una
 * tabla precalculada de codigos de ocho a doce cifras valdria para todas a la
 * vez. Con el, la huella es distinta en cada quiosco: es una sal por
 * dispositivo, publica pero no reutilizable entre instalaciones ni entre
 * tablets.
 *
 * ## Riesgo aceptado (doc 07 §6)
 *
 * Con acceso fisico al almacenamiento de la tablet, una huella SHA-256 de un
 * codigo de 8 a 12 cifras se fuerza. Lo que protege es una pantalla **sin datos
 * personales y sin el token**, y el coste de un KDF por latido y por quiosco no
 * se justifica. Quien tiene la tablet en la mano tiene la tablet.
 *
 * ## Sin codigo configurado no hay huella
 *
 * `null`, y la pantalla se abre sin codigo: una instalacion que no ha decidido
 * proteger su pantalla de diagnostico no puede quedarse sin ella (regla dura
 * 19).
 */
final readonly class ServiceCodeFingerprint
{
    /**
     * La huella, o `null` si la instalacion no tiene codigo.
     *
     * Una cadena vacia y `null` significan lo mismo —«sin codigo»— porque el
     * ajuste `KIOSK_SERVICE_CODE` vale la cadena vacia de serie y distinguirlos
     * aqui solo daria dos formas de decir lo mismo.
     */
    public static function of(string $deviceUuid, ?string $serviceCode): ?string
    {
        if ($serviceCode === null || $serviceCode === '') {
            return null;
        }

        return hash('sha256', $deviceUuid.':'.$serviceCode);
    }
}

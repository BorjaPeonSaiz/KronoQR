<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una sesion de portal recien abierta: el token y lo justo de quien lo pidio
 * (RF-ID-05, RF-ID-07, RL-05).
 *
 * ## Por que vive en `Shared\Domain` y no en `Identity`
 *
 * Es el mismo caso que {@see PinVerification} y {@see CredentialResolution}:
 * cruza la frontera entre el modulo que **tiene** el dato —`Workforce`, dueño de
 * `employees` y por tanto el unico que puede acuñar un token colgado de una
 * persona— y el que **abre** la sesion —`Identity`, dueño del acceso—, y ninguno
 * de los dos puede importar nada del otro (doc 02 §1.6, verificado por Deptrac).
 * La unica capa que los dos alcanzan es esta.
 *
 * ## Lleva nombre, y en esta clase eso no rompe la regla dura 21
 *
 * La regla prohibe nombres de empleado en **logs tecnicos y en `error_events`**,
 * no en la respuesta que una persona recibe sobre si misma. Aqui el unico nombre
 * que aparece es el de quien acaba de teclear su propio PIN. Lo que sigue sin
 * poder ocurrir es que este objeto llegue a un log: quien lo maneja registra
 * `employeeUuid` y nada mas.
 *
 * ## `timeZone` y `locale` no son adorno
 *
 * Sin la zona del centro, la interfaz pintaria las horas con la del navegador, y
 * eso es la regla dura 3 rota justo donde mas importa: un registro con valor
 * legal consultado desde un movil con la zona mal puesta. Sin el idioma de la
 * persona, el portal abriria en el del navegador y no en el que RRHH dejo en su
 * ficha (§6.6).
 *
 * ## El token en claro existe una sola vez
 *
 * Vive dentro de este objeto el tiempo que tarda en serializarse la respuesta.
 * El servidor guarda su hash y no puede volver a enseñarlo; por eso este objeto
 * no se persiste, no se cachea y no se registra en ningun sitio.
 */
final readonly class PortalSession
{
    public function __construct(
        /** UUID publico del empleado (`employees.uuid`). El unico identificador admitido en un log. */
        public string $employeeUuid,
        /** Nombre completo. Es el de quien pregunta, no el de un tercero. */
        public string $displayName,
        /** `employees.employee_code`: opaco y aleatorio, la mitad publica de la credencial. */
        public string $employeeCode,
        /** Idioma de la persona (`employees.locale`), no el del navegador. */
        public string $locale,
        /** Zona IANA del centro al que esta adscrita hoy (`sites.timezone`). */
        public string $timeZone,
        /** Token `Bearer` de ambito `self:read`. Solo existe aqui y una vez. */
        public string $plainTextToken,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Una sesion de portal es siempre la de alguien.');
        }

        if ($displayName === '') {
            throw new InvalidArgumentException('Una sesion de portal necesita un nombre con el que saludar.');
        }

        if ($employeeCode === '') {
            throw new InvalidArgumentException('Una sesion de portal necesita el codigo con el que se entro.');
        }

        if ($locale === '') {
            throw new InvalidArgumentException('Una sesion de portal necesita el idioma de la persona.');
        }

        if ($timeZone === '') {
            throw new InvalidArgumentException('Una sesion sin zona horaria obligaria al cliente a adivinarla (regla dura 3).');
        }

        if ($plainTextToken === '') {
            throw new InvalidArgumentException('Una sesion de portal sin token no es una sesion.');
        }

        if ($expiresAt->getTimezone()->getName() !== 'UTC') {
            // Regla dura 3. Una caducidad en hora local caduca a la hora
            // equivocada dos veces al año.
            throw new InvalidArgumentException('La caducidad de la sesion va en UTC.');
        }
    }
}

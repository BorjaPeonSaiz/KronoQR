<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Desenlace de un intento de autenticacion, en la forma exacta en la que sale
 * como etiqueta `outcome` de `kronoqr_auth_attempts_total` (doc 02 §8.2).
 *
 * **La frontera entre `failure` y `lockout` esta escrita a proposito, y la
 * escribe la alerta que los consulta** (`infra/observability/prometheus/rules/
 * auth.yml`):
 *
 * ```
 * success   la credencial era la buena
 * failure   el intento no acabo en sesion, por la causa que sea — incluido
 *           llegar con un bloqueo ya puesto, que tambien es un intento fallido
 * lockout   se ABRIO un bloqueo. Uno por bloqueo, no uno por intento rechazado
 * ```
 *
 * `KronoqrAuthLockouts` dispara con **tres o mas en quince minutos** porque lee
 * esa cifra como «tres cuentas distintas alcanzando su limite a la vez», que es
 * la firma de probar una lista de credenciales robadas. Si `lockout` contara los
 * intentos rechazados mientras el bloqueo dura, una sola persona insistiendo
 * contra una sola cuenta llegaria a tres en segundos y la alerta seria ruido
 * desde el primer dia.
 *
 * Contado asi, ademas, `lockout` casa uno a uno con los asientos
 * `auth.lockout_started` de `audit_log`: dos formas de contar el mismo hecho que
 * no pueden divergir.
 */
enum AuthOutcome: string
{
    case SUCCESS = 'success';

    case FAILURE = 'failure';

    case LOCKOUT = 'lockout';
}

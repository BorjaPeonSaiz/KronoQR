<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\PortalSession;
use DateTimeImmutable;

/**
 * Acuña la sesion del portal del empleado (RF-ID-05, RF-ID-07, RL-05).
 *
 * **Por que en `Shared/Application/Port` y no en `Identity`.** Es exactamente el
 * mismo reparto que {@see EmployeePinVerifier}: quien **necesita** la
 * abstraccion es `Identity` —dueño del acceso, quien decide si se abre sesion— y
 * quien **tiene** el dato es `Workforce`, dueño de `employees`. Un token de
 * Sanctum cuelga de una fila concreta, asi que solo puede emitirlo el modulo
 * que posee esa tabla, y ninguno de los dos puede importar nada del otro (doc 02
 * §1.6, verificado por Deptrac). La unica capa que los dos alcanzan es esta. El
 * adaptador vive en `Workforce/Infrastructure/Adapter/` y se enlaza en
 * `WorkforceServiceProvider` (ADR-025, restriccion 3).
 *
 * **El `tokenable` es el empleado y no una cuenta de `users`**, y no es un
 * detalle. Meter a la plantilla en la tabla de gestion habria significado
 * fabricar una cuenta por persona —con correo obligatorio, politica de
 * contraseña y el 2FA de la tarea 2.1 encima—, y el producto no puede exigir
 * correo a nadie (regla dura 12, ADR-015). Es la misma decision que tomo el
 * token de quiosco al colgar de `devices`: quien tiene sesion es quien existe,
 * no una cuenta espejo que alguien tiene que acordarse de dar de baja.
 *
 * ## Lo que este puerto NO hace
 *
 * **No comprueba el PIN.** Eso es de {@see EmployeePinVerifier}, que ademas
 * lleva el contador de intentos. Cuando se llega aqui, la identidad ya esta
 * decidida: este puerto solo acuña.
 *
 * **No decide los ambitos ni la caducidad.** Los recibe ya resueltos, igual que
 * el dominio recibe los umbrales legales resueltos (regla dura 14). El catalogo
 * de ambitos vive en `Identity\Domain\ValueObject\TokenAbility` —nombrado en
 * prosa y no con `@see`, porque una referencia resoluble seria una dependencia
 * entre modulos que el §1.6 no concede— y la vida de la sesion es configuracion
 * de la instalacion (regla dura 13).
 */
interface PortalSessionIssuer
{
    /**
     * Abre la sesion de este empleado, o `null` si ya no se le puede abrir.
     *
     * **`null` no es un error del programa**: es la carrera —estrecha, pero
     * real— entre comprobar el PIN y acuñar el token, en la que a la persona le
     * dan de baja o se le cambia de centro. Quien llama lo traduce al **mismo**
     * rechazo generico que un PIN incorrecto (regla dura 17): desde fuera no se
     * distingue, y desde dentro queda en el log.
     *
     * @param  string  $employeeUuid  UUID publico del empleado ya verificado.
     * @param  string  $sessionName  Nombre del token, para poder reconocerlo al listarlo.
     *                               Nunca lleva datos personales (regla dura 21).
     * @param  list<string>  $abilities  Ambitos del token, ya resueltos. Para el portal es
     *                                   siempre `['self:read']` (RF-ID-07).
     * @param  DateTimeImmutable  $expiresAt  Caducidad en UTC, ya resuelta de la configuracion.
     */
    public function issueFor(
        string $employeeUuid,
        string $sessionName,
        array $abilities,
        DateTimeImmutable $expiresAt,
    ): ?PortalSession;
}

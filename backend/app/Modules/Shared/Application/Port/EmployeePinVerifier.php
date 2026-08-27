<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Shared\Domain\ValueObject\PinVerification;
use SensitiveParameter;

/**
 * De un codigo de empleado y su PIN al empleado que hay detras, o a un rechazo
 * (RF-AT-11, RF-ID-06, RS-12).
 *
 * **Por que en `Shared/Application/Port` y no en el modulo que pregunta.** Es el
 * mismo caso que {@see ClockingEmployees} y {@see EmployeeRegistry}: quien
 * **necesita** la abstraccion son dos satelites —el fichaje de respaldo del
 * quiosco (`Attendance`, tarea 1.12) y el portal del empleado (tarea 1.11)— y
 * quien **tiene** el dato es un tercero, `Workforce`, dueno de
 * `employees.pin_hash`. Ninguno puede importar nada de los otros (doc 02 §1.6,
 * verificado por Deptrac), y la unica capa que los tres alcanzan es esta. El
 * adaptador vive en `Workforce/Infrastructure/`, que es donde esta la tabla, y
 * se enlaza en `WorkforceServiceProvider` (ADR-025, restriccion 3).
 *
 * **Es el par de credenciales del portal, no uno nuevo** (ADR-015, regla dura
 * 12): codigo de empleado y PIN de seis digitos. Que el fichaje de respaldo use
 * exactamente el mismo par es lo que evita inventar una segunda credencial que
 * emitir, entregar y restablecer — y lo que hace que restablecer el PIN arregle
 * las dos puertas a la vez.
 *
 * ## Cinco obligaciones del adaptador que no son opcionales
 *
 * 1. **Comprobar el bloqueo ANTES de verificar** (RS-12). Verificar primero
 *    convierte el bloqueo en un oraculo: la diferencia de tiempo entre «bloqueado
 *    tras comparar» y «bloqueado sin comparar» dice si el PIN probado era el
 *    bueno.
 * 2. **Tiempo constante** (RS-03, regla dura 17). Un codigo inexistente no puede
 *    responder antes que un PIN incorrecto: comparar el hash cuesta milisegundos
 *    y saltarse esa comparacion es medible desde fuera. El adaptador paga el
 *    coste tambien cuando no hay contra que comparar.
 * 3. **Anotar el fallo y limpiar en el acierto**, contra {@see PinAttempts} y con
 *    este mismo origen. Sin esto el escalado del §7.5 no existe.
 *    Que sea el adaptador y no quien llama es lo que hace que las dos puertas
 *    —quiosco y portal— no puedan implementar la mitad cada una.
 * 4. **Respetar RN-14.** Quien esta de baja no verifica, y no verifica **por el
 *    mismo camino y con el mismo valor** que un codigo inexistente.
 * 5. **No devolver nunca el PIN ni su hash**, ni escribirlos en ningun sitio
 *    (regla dura 21). Lo unico que sale al acertar es el `employee_uuid`.
 */
interface EmployeePinVerifier
{
    /**
     * @param  string  $employeeCode  Codigo opaco y aleatorio del empleado (doc 01 §5.5).
     *                                Es la mitad publica de la credencial del portal.
     * @param  string  $pin  El PIN en claro, ya descifrado. Vive en memoria el tiempo de
     *                       esta llamada y no se persiste, ni se registra, ni se devuelve.
     * @param  PinOrigin  $origin  La puerta por la que se teclea. Forma parte de la clave del
     *                             contador de intentos (§7.5): las dos no se comparten.
     */
    public function verify(
        string $employeeCode,
        #[SensitiveParameter] string $pin,
        PinOrigin $origin,
    ): PinVerification;
}

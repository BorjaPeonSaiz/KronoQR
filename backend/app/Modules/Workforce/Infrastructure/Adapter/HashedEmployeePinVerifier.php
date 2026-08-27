<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Shared\Domain\ValueObject\PinVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Comprueba el PIN contra `employees.pin_hash` y lleva la cuenta de los fallos
 * (RF-AT-11, RF-ID-06, RS-12).
 *
 * **Es la arista de ADR-025 al reves de la habitual**: el puerto lo declara
 * `Shared` —porque lo necesitan dos satelites que no pueden verse entre si, el
 * quiosco y el portal— y lo implementa `Workforce`, que es quien tiene la tabla.
 * El enlace se declara en `WorkforceServiceProvider` (restriccion 3).
 *
 * ## El orden de los cuatro pasos no es negociable
 *
 * ```
 * 1. Buscar al empleado por su codigo
 * 2. Decidir contra QUE hash se compara: el real, o el señuelo
 * 3. Comparar, cueste lo que cueste                    <- RS-03
 * 4. Decidir el desenlace y anotar el fallo o limpiar  <- RS-12
 * ```
 *
 * **El paso 3 se paga siempre**: aunque no haya nadie con ese codigo, aunque no
 * haya PIN emitido, aunque la persona este de baja y aunque este bloqueada.
 * `Hash::check()` contra un hash señuelo cuesta lo mismo que contra el real —es
 * el mismo algoritmo y el mismo factor de coste, bcrypt o argon2id segun la
 * instalacion—, y saltarselo dejaria una diferencia de decenas de milisegundos
 * perfectamente medible desde fuera: quien la midiera podria averiguar que
 * codigos de empleado existen sin acertar ni un PIN (RS-03, regla dura 17). Esa
 * unica comparacion, presente en los cinco caminos, **es** lo que iguala los
 * tiempos; no hace falta ningun suelo artificial porque el trabajo caro ya es el
 * mismo en todos. Es la misma decision que toma
 * `Identity\Infrastructure\Adapter\HmacSignatureVerifier` con el payload del QR
 * —nombrado en prosa y no con `@see`, porque una referencia resoluble seria una
 * dependencia entre modulos que el §1.6 no concede—.
 *
 * **Estando bloqueado se compara contra el señuelo, no contra el hash real.** Es
 * lo que resuelve la tension entre las dos exigencias: RS-12 pide que un PIN no
 * se compruebe mientras el bloqueo esta activo —si se comprobara, el bloqueo
 * seria un oraculo que confirma cuando se acierta— y RS-03 pide que el tiempo no
 * delate nada. Comparando contra el señuelo se paga el mismo coste sin llegar a
 * mirar el PIN de nadie: el resultado de esa comparacion se descarta.
 *
 * ## Cuatro rechazos, un solo valor
 *
 * Codigo inexistente, PIN incorrecto, PIN nunca emitido y empleado de baja
 * (RN-14) devuelven todos `PinVerification::rejected()`. No hay ninguna rama que
 * los distinga hacia arriba, y por tanto no hay ninguna forma de que se filtren
 * por descuido en un `Resource` futuro.
 *
 * ## Solo cuenta como intento fallido quien puede fallar
 *
 * El contador se lleva por `employee_uuid`, asi que un codigo inexistente **no
 * crea contador**: no hay UUID contra el que anotarlo. Es correcto y es
 * deliberado —quien prueba codigos al azar no puede llenar la cache—, y no
 * debilita nada, porque el limite por dispositivo y por IP de la ruta (§7.1) es
 * el control que frena ese otro ataque. Los dos conviven; RS-12 los enumera
 * juntos.
 *
 * ## El hash se lee por la tabla, no por el modelo
 *
 * `Employee` tiene `pin_hash` en `$hidden` y fuera de `$fillable` justamente
 * para que no salga por un `toArray()` de depuracion. Leerlo con el constructor
 * de consultas mantiene esa promesa intacta: el hash entra en una variable
 * local, se compara y no llega a ningun objeto que alguien pueda serializar.
 */
final readonly class HashedEmployeePinVerifier implements EmployeePinVerifier
{
    /**
     * Hash señuelo contra el que se compara cuando no hay uno real.
     *
     * Es un bcrypt valido de una cadena aleatoria que nadie conoce, generado una
     * vez y clavado aqui: no es un secreto —el PIN que lo produjo no existe— y
     * clavarlo es lo que garantiza que el coste de la comparacion sea siempre el
     * mismo. Generarlo al vuelo con `Hash::make()` costaria mas que la
     * comparacion y produciria la asimetria contraria.
     */
    private const string DECOY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.7ZBpRolkT/LNfWfeoQhh0Zc1a5tRfIu';

    public function __construct(private PinAttempts $attempts) {}

    public function verify(
        string $employeeCode,
        #[SensitiveParameter] string $pin,
        PinOrigin $origin,
    ): PinVerification {
        $employee = $this->findByCode($employeeCode);

        $locked = $employee !== null && $this->attempts->isLocked($employee['uuid'], $origin);

        // Se compara SIEMPRE y contra algo: con el hash real solo cuando hay
        // empleado y NO esta bloqueado; con el señuelo en los otros cuatro
        // caminos. Ver el docblock de la clase: esta linea es la que iguala los
        // tiempos y la que impide que el bloqueo sea un oraculo.
        $matches = Hash::check(
            $pin,
            $locked ? self::DECOY_HASH : ($employee['pin_hash'] ?? self::DECOY_HASH),
        );

        if ($employee === null) {
            return PinVerification::rejected();
        }

        if ($locked) {
            // El resultado de la comparacion de arriba se descarta a proposito:
            // se pago por el tiempo, no por la respuesta.
            return PinVerification::locked($this->attempts->secondsUntilUnlock($employee['uuid'], $origin));
        }

        // RN-14 despues de la comparacion, no antes, para que dar de baja a
        // alguien no cambie el tiempo de respuesta de su codigo.
        if (! $matches || ! EmploymentStatus::from($employee['status'])->canClock()) {
            $this->attempts->recordFailure($employee['uuid'], $origin);

            return PinVerification::rejected();
        }

        // Acertar borra el castigo acumulado en las dos puertas: el PIN es el
        // bueno, asi que quien fallara antes era la misma persona teniendo un
        // mal dia.
        $this->attempts->clear($employee['uuid']);

        return PinVerification::verified($employee['uuid']);
    }

    /**
     * El empleado con ese codigo, con lo justo para decidir.
     *
     * `employee_code` es `CITEXT`, asi que la comparacion la hace PostgreSQL sin
     * distinguir mayusculas: quien teclea su codigo en una tablet con guantes no
     * tiene por que acertar la caja.
     *
     * @return array{uuid: string, status: string, pin_hash: string|null}|null
     */
    private function findByCode(string $employeeCode): ?array
    {
        $row = DB::table('employees')
            ->select(['uuid', 'status', 'pin_hash'])
            ->where('employee_code', $employeeCode)
            ->first();

        if ($row === null) {
            return null;
        }

        $uuid = $row->uuid ?? null;
        $status = $row->status ?? null;
        $hash = $row->pin_hash ?? null;

        if (! \is_string($uuid) || ! \is_string($status)) {
            return null;
        }

        return [
            'uuid' => $uuid,
            'status' => $status,
            'pin_hash' => \is_string($hash) && $hash !== '' ? $hash : null,
        ];
    }
}

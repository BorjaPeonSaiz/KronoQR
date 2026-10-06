<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\PinClaim;
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
 * ## Los cinco caminos hacen el mismo trabajo, y eso **es** el control
 *
 * ```
 * 1. Buscar al empleado por su codigo            1 consulta, exista o no
 * 2. Reservar el intento: el sujeto real, o el   candado + 1 lectura
 *    señuelo si no hay nadie con ese codigo      + 1 escritura de cache
 * 3. Comparar el PIN contra el hash o el señuelo 1 bcrypt          <- RS-03
 * ```
 *
 * **Los cinco rechazos ejecutan esa secuencia entera y en el mismo orden**:
 * aunque no haya nadie con ese codigo, aunque no haya PIN emitido, aunque la
 * persona este de baja y aunque el bloqueo ya estuviera puesto. Lo que cambia es
 * contra **quien**: un codigo que no existe se reserva contra el señuelo del
 * contador, un PIN que no se puede comparar se compara contra
 * {@see self::DECOY_HASH} y un empleado ya bloqueado reserva sin anotar —su
 * bloqueo no crece por insistir (RS-12)— pagando la misma lectura y la misma
 * escritura. El resultado de todo eso se descarta.
 *
 * **Se reserva antes de comparar** (ADR-050). Anotando el fallo despues, todos
 * los intentos simultaneos contra un codigo pasaban la comprobacion del bloqueo
 * antes de que ninguno contara y se comparaban contra el PIN real —de 4 a 22 de
 * 25 en rafaga—; reservando con el candado del empleado cogido, solo los que
 * caben antes del primer escalon llegan al hash real. El que acierta borra la
 * cuenta, con su propia marca dentro. Saltarse cualquiera de los pasos dejaria una diferencia
 * medible desde fuera: quien la midiera averiguaria que codigos de empleado
 * existen sin acertar ni un PIN (RS-03, regla dura 17).
 *
 * `Hash::check()` contra el señuelo cuesta lo mismo que contra el hash real —es
 * el mismo algoritmo y el mismo factor de coste— y es el trabajo dominante, asi
 * que no hace falta ningun suelo artificial. Es la misma decision que toma
 * `Identity\Infrastructure\Adapter\HmacSignatureVerifier` con el payload del QR
 * —nombrado en prosa y no con `@see`, porque una referencia resoluble seria una
 * dependencia entre modulos que el §1.6 no concede—.
 *
 * **Estando bloqueado se compara contra el señuelo, no contra el hash real.** Es
 * lo que resuelve la tension entre las dos exigencias: RS-12 pide que un PIN no
 * se compruebe mientras el bloqueo esta activo —si se comprobara, el bloqueo
 * seria un oraculo que confirma cuando se acierta— y RS-03 pide que el tiempo no
 * delate nada.
 *
 * ## Cuatro rechazos, un solo valor hacia arriba de `CredentialResolution`
 *
 * Codigo inexistente, PIN incorrecto, PIN nunca emitido y empleado de baja
 * (RN-14) devuelven todos `PinVerification::rejected()`: sin `employeeUuid()` y
 * con `isVerified()` falso. **Desde RN-19 (ADR-043) el rechazo lleva ademas un
 * `PinClaim` interno** cuando el codigo es de alguien que puede fichar —PIN
 * incorrecto, no emitido o bloqueo—, y nunca con codigo inexistente, baja o
 * suspendido. El claim solo lo lee el fichaje del quiosco para escribir
 * `scan_events.claimed_employee_id`; la respuesta, el evento y el log siguen
 * sin poder distinguir nada. Se construye con lo que ya habia en `$employee`:
 * **ninguna E/S nueva**, asi que el tiempo de los cinco caminos no cambia.
 *
 * ## El contador del señuelo no bloquea a nadie
 *
 * Los codigos inexistentes comparten una sola entrada de cache, acotada por la
 * politica y con su TTL. Puede llegar a «bloquearse», y da igual: el bloqueo solo
 * gobierna el flujo cuando hay empleado detras. Quien prueba codigos al azar
 * sigue sin poder llenar la cache ni cerrarle la puerta a nadie —lo que frena ese
 * ataque es el limite por dispositivo y por IP del §7.1—, y ahora ademas no se
 * delata por el tiempo.
 *
 * ## El rastro sale de aqui, y por el mismo motivo que el contador
 *
 * OWASP A09. Cada rechazo escribe `auth.login_failed` en el log tecnico y suma en
 * `kronoqr_auth_attempts_total`; el bloqueo que se abre deja ademas asiento, y lo
 * deja **despues de responder** (ADR-039). Se emiten desde este adaptador y no
 * desde el portal y el quiosco por lo mismo que el contador de intentos: **son
 * dos puertas y una sola implementacion**, y repartirlo dejaria a cada una
 * registrando la mitad.
 *
 * **Los cuatro rechazos comparten motivo, tambien el bloqueo.** Ningun apunte
 * lleva el codigo de empleado, ni el PIN, ni el UUID, ni distingue el bloqueo del
 * PIN equivocado: el log no puede separar lo que la respuesta no separa (RS-03).
 * Donde el bloqueo si se ve es en el asiento `auth.lockout_started`.
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

    public function __construct(
        private PinAttempts $attempts,
        private AuthenticationJournal $journal,
    ) {}

    public function verify(
        string $employeeCode,
        #[SensitiveParameter] string $pin,
        PinOrigin $origin,
    ): PinVerification {
        $channel = $origin->authChannel();
        $employee = $this->findByCode($employeeCode);

        // Se reserva SIEMPRE, antes de comparar, y contra el señuelo cuando no
        // hay empleado (`null`). Con el candado del empleado cogido, el contador
        // lee el bloqueo y, si no lo hay, anota ya este intento como fallo: los
        // intentos simultaneos que no caben antes del escalon llegan bloqueados y
        // no se comparan contra el PIN real (ADR-050, RS-12).
        $reservation = $this->attempts->reserve($employee['uuid'] ?? null, $origin);

        // El bloqueo solo gobierna el flujo cuando hay alguien detras: el del
        // señuelo se lee y se descarta.
        $locked = $employee !== null && $reservation->isLocked();

        // Se compara SIEMPRE y contra algo: con el hash real solo cuando hay
        // empleado y NO esta bloqueado; con el señuelo en los otros cuatro
        // caminos. Ver el docblock de la clase.
        $matches = Hash::check($pin, $this->hashToCompare($employee, $locked));

        if ($locked) {
            // El resultado de la comparacion de arriba se descarta a proposito:
            // se pago por el tiempo, no por la respuesta. Y el motivo del apunte
            // es el mismo que el de abajo: el log no separa lo que la respuesta
            // no separa. La reserva no anoto nada: el bloqueo de quien ya lo
            // esta no crece por insistir (RS-12).
            $this->journal->failed($channel, null, AuthFailureReason::INVALID_CREDENTIALS);

            // RN-19 (ADR-043): el dueño del codigo baja con el bloqueo, solo para
            // la fila de `scan_events`. Sin E/S nueva: todo esta ya en `$employee`.
            return PinVerification::locked($reservation->lockSeconds(), $this->claimOf($employee, lockout: true));
        }

        // RN-14 despues de la comparacion, no antes, para que dar de baja a
        // alguien no cambie el tiempo de respuesta de su codigo. Y el codigo
        // inexistente entra por esta misma rama, contra el señuelo, para que el
        // trabajo restante sea el mismo.
        if ($employee === null || $this->isRejected($employee, $matches)) {
            $this->journal->failed($channel, null, AuthFailureReason::INVALID_CREDENTIALS);

            // El fallo ya quedo anotado al reservar. El asiento del bloqueo, **en
            // el flanco**: solo el intento cuya reserva lo abrio. El flanco lo
            // decide el contador con su candado cogido, y como reservar con el
            // bloqueo abierto no anota, ningun intento lo abre dos veces ni sube
            // de escalon en silencio. El señuelo no anuncia nada: nadie esta
            // detras de el.
            $opened = $employee === null ? 0 : $reservation->openedSeconds();

            if ($employee !== null && $opened > 0) {
                $this->journal->lockoutStarted($channel, $employee['uuid'], $opened);
            }

            // RN-19: con un codigo inexistente `claimOf()` devuelve `null`.
            return PinVerification::rejected($this->claimOf($employee, lockout: $opened > 0));
        }

        // Acertar borra el castigo acumulado en las dos puertas —y la marca que
        // reservo este mismo intento, que no era un fallo—: el PIN es el bueno,
        // asi que quien fallara antes era la misma persona teniendo un mal dia.
        $this->attempts->clear($employee['uuid']);

        $this->rehashIfStale($employee, $pin);

        return PinVerification::verified($employee['uuid']);
    }

    /**
     * Vuelve a hashear el PIN si el hash guardado se quedo por debajo del coste
     * vigente (hallazgo **H-13** de la revision interna ASVS de 2026-09).
     *
     * Misma razon que en el acceso de gestion —nombrada en prosa y sin `@see`,
     * porque una referencia resoluble a `Identity` seria una dependencia entre
     * modulos que el §1.6 no concede—: el coste esta fijado y verificado, pero
     * sin esto no existe el camino para cambiarlo, y el PIN es la credencial que
     * mas veces al dia se comprueba en una instalacion.
     *
     * **Solo en el camino del acierto, y despues de responder al contador.** Los
     * cinco rechazos de arriba no pasan por aqui, que es lo que impide que este
     * `bcrypt` de mas convierta el acierto y el fallo en dos tiempos distintos
     * medibles desde fuera (RS-03, regla dura 17). Quien acierta ya se distingue
     * por el desenlace, asi que ahi no hay nada que igualar.
     *
     * **Se escribe por la tabla y no por el modelo**, con el mismo criterio con
     * el que se lee: `Employee` tiene `pin_hash` fuera de `$fillable` y en
     * `$hidden` justamente para que no salga por un `toArray()`, y traerlo a un
     * objeto para guardarlo rompe esa promesa sin ganar nada. Tampoco se toca
     * `updated_at`: el PIN de la persona no ha cambiado, solo su hash.
     *
     * ## Oportunista: fuera del orden de candados, y por diseño (ADR-046 §4, A-4)
     *
     * Esta en el camino del fichaje por PIN y del acceso al portal, asi que no
     * puede esperar a una operacion de gestion que tenga la ficha, ni pisar el
     * hash nuevo de un restablecimiento con el del PIN viejo. Por eso:
     *
     * - el hash nuevo se calcula **antes** de tocar la fila;
     * - la fila se toma con `FOR NO KEY UPDATE SKIP LOCKED` en la misma
     *   sentencia: si otra transaccion la tiene, no se espera;
     * - se escribe solo si el hash guardado sigue siendo **el que se leyo**;
     * - si la fila estaba ocupada o el hash ya cambio, no hace nada y no lo dice:
     *   el rehash se repetira en el siguiente acierto;
     * - **nunca toma la cadena** de `audit_log`.
     *
     * Una sola sentencia y no un `SELECT` seguido de un `UPDATE`: sin transaccion
     * alrededor, el candado de la fila dura lo que dura la sentencia.
     *
     * @param  array{uuid: string, status: string, pin_hash: string|null}  $employee
     */
    private function rehashIfStale(array $employee, #[SensitiveParameter] string $pin): void
    {
        $hash = $employee['pin_hash'];

        // El señuelo no llega aqui: solo se rehashea el PIN de quien acerto el
        // suyo, y para acertarlo tiene que haber uno emitido.
        if ($hash === null || ! Hash::needsRehash($hash)) {
            return;
        }

        $rehashed = Hash::make($pin);

        DB::update(
            'UPDATE employees SET pin_hash = ? '
            .'WHERE id = (SELECT id FROM employees WHERE uuid = ? AND pin_hash = ? FOR NO KEY UPDATE SKIP LOCKED) '
            .'AND pin_hash = ?',
            [$rehashed, $employee['uuid'], $hash, $hash],
        );
    }

    /**
     * El dueño del codigo de un PIN rechazado, solo si puede fichar (RN-19,
     * ADR-043). Sin E/S: todo esta ya en `$employee`, y por eso no altera el
     * tiempo de ningun camino (RS-03).
     *
     * @param  array{uuid: string, status: string, pin_hash: string|null}|null  $employee
     */
    private function claimOf(?array $employee, bool $lockout): ?PinClaim
    {
        if ($employee === null || ! EmploymentStatus::from($employee['status'])->canClock()) {
            return null;
        }

        return PinClaim::of($employee['uuid'], $lockout);
    }

    /**
     * Contra que hash se compara este intento.
     *
     * **Nunca devuelve nada que no sea un hash valido**: el señuelo cubre los
     * cuatro caminos donde no hay uno real —no hay empleado, no hay PIN emitido,
     * o lo hay pero el bloqueo esta puesto y RS-12 prohibe mirarlo—.
     *
     * @param  array{uuid: string, status: string, pin_hash: string|null}|null  $employee
     */
    private function hashToCompare(?array $employee, bool $locked): string
    {
        if ($locked || $employee === null) {
            return self::DECOY_HASH;
        }

        return $employee['pin_hash'] ?? self::DECOY_HASH;
    }

    /**
     * Los cuatro rechazos que llegan hasta aqui, en una sola condicion **y sin
     * orden de cortocircuito que importe**: los cuatro producen el mismo valor y
     * el trabajo caro ya se pago antes de preguntar.
     *
     * RN-14 se evalua la ultima a proposito: dar de baja a alguien no puede
     * cambiar el tiempo de respuesta de su codigo.
     *
     * @param  array{uuid: string, status: string, pin_hash: string|null}  $employee
     */
    private function isRejected(array $employee, bool $matches): bool
    {
        return ! $matches || ! EmploymentStatus::from($employee['status'])->canClock();
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

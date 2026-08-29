<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\AuthFailureReason;
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
 * ## Los cinco caminos hacen el mismo trabajo, y eso **es** el control
 *
 * ```
 * 1. Buscar al empleado por su codigo            1 consulta, exista o no
 * 2. Elegir el sujeto: el real, o el señuelo
 * 3. Leer el bloqueo del sujeto                  1 lectura de cache
 * 4. Comparar el PIN contra el hash o el señuelo 1 bcrypt          <- RS-03
 * 5. Anotar el fallo y releer el bloqueo         1 lectura + 1 escritura
 * ```
 *
 * **Los cinco rechazos ejecutan esa secuencia entera y en el mismo orden**:
 * aunque no haya nadie con ese codigo, aunque no haya PIN emitido, aunque la
 * persona este de baja y aunque el bloqueo ya estuviera puesto. Lo que cambia es
 * contra **quien**: un codigo que no existe se cuenta contra
 * {@see self::DECOY_SUBJECT}, un PIN que no se puede comparar se compara contra
 * {@see self::DECOY_HASH} y un empleado ya bloqueado anota su fallo tambien
 * contra el señuelo —su bloqueo no crece por insistir (RS-12)—. El resultado de
 * todo eso se descarta. Saltarse cualquiera de los pasos dejaria una diferencia
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
 * ## Cuatro rechazos, un solo valor
 *
 * Codigo inexistente, PIN incorrecto, PIN nunca emitido y empleado de baja
 * (RN-14) devuelven todos `PinVerification::rejected()`. No hay ninguna rama que
 * los distinga hacia arriba, y por tanto no hay ninguna forma de que se filtren
 * por descuido en un `Resource` futuro.
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

    /**
     * Sujeto señuelo contra el que se cuentan los intentos de un codigo que no
     * existe.
     *
     * El UUID nulo, que ninguna fila de `employees` puede tener: los UUID de la
     * plantilla los genera PostgreSQL. Es al contador lo que
     * {@see self::DECOY_HASH} es a la comparacion —el trabajo se paga y el
     * resultado se tira—, y sin el, un codigo inexistente se ahorraba una lectura
     * y una escritura de cache que el codigo real si pagaba.
     */
    private const string DECOY_SUBJECT = '00000000-0000-0000-0000-000000000000';

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

        // Contra quien se cuenta: la persona, o el señuelo si no hay ninguna.
        $subject = $employee['uuid'] ?? self::DECOY_SUBJECT;

        // Se lee SIEMPRE, y por eso se lee contra el señuelo cuando no hay
        // empleado. Los segundos se guardan porque la rama bloqueada los
        // necesita: preguntar dos veces seria una segunda llamada cuya presencia
        // depende de la respuesta a la primera, que es justo la asimetria que
        // este metodo evita.
        $lockSeconds = $this->attempts->secondsUntilUnlock($subject, $origin);

        // El bloqueo solo gobierna el flujo cuando hay alguien detras: el del
        // señuelo se lee y se descarta.
        $locked = $employee !== null && $lockSeconds > 0;

        // Se compara SIEMPRE y contra algo: con el hash real solo cuando hay
        // empleado y NO esta bloqueado; con el señuelo en los otros cuatro
        // caminos. Ver el docblock de la clase.
        $matches = Hash::check($pin, $this->hashToCompare($employee, $locked));

        if ($locked) {
            // El resultado de la comparacion de arriba se descarta a proposito:
            // se pago por el tiempo, no por la respuesta. Y el motivo del apunte
            // es el mismo que el de abajo: el log no separa lo que la respuesta
            // no separa.
            //
            // El fallo se anota **contra el señuelo**: el bloqueo de quien ya lo
            // esta no crece por insistir (RS-12) y el camino cuesta exactamente
            // lo mismo que el de abajo. Sin esto, tres intentos contra un codigo
            // bastaban para saber si existe: a partir del bloqueo, el suyo se
            // ahorraba dos viajes a la cache que un codigo inexistente seguia
            // pagando.
            $this->recordFailure(self::DECOY_SUBJECT, $origin);
            $this->journal->failed($channel, null, AuthFailureReason::INVALID_CREDENTIALS);

            return PinVerification::locked($lockSeconds);
        }

        // RN-14 despues de la comparacion, no antes, para que dar de baja a
        // alguien no cambie el tiempo de respuesta de su codigo. Y el codigo
        // inexistente entra por esta misma rama, contra el señuelo, para que el
        // trabajo restante sea el mismo.
        if ($employee === null || $this->isRejected($employee, $matches)) {
            $opened = $this->recordFailure($subject, $origin);
            $this->journal->failed($channel, null, AuthFailureReason::INVALID_CREDENTIALS);

            // El asiento del bloqueo, **en el flanco**: solo el fallo que lo
            // abre. Que sea el flanco se sabe sin guardar nada —si ya estuviera
            // bloqueado, la rama de arriba habria contestado—, y repetirlo
            // mientras dura llenaria la cadena de ADR-010 con la insistencia de
            // quien ataca. El señuelo llega hasta aqui y no anuncia nada: nadie
            // esta detras de el.
            if ($employee !== null && $opened > 0) {
                $this->journal->lockoutStarted($channel, $employee['uuid'], $opened);
            }

            return PinVerification::rejected();
        }

        // Acertar borra el castigo acumulado en las dos puertas: el PIN es el
        // bueno, asi que quien fallara antes era la misma persona teniendo un
        // mal dia.
        $this->attempts->clear($employee['uuid']);

        return PinVerification::verified($employee['uuid']);
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
     * Anota el fallo y devuelve los segundos de bloqueo que quedan **despues** de
     * anotarlo: cero si no hay bloqueo, y la duracion del escalon si este fallo
     * acaba de abrirlo.
     *
     * **Las dos llamadas van juntas y siempre**, tambien cuando el sujeto es el
     * señuelo. Preguntar «¿esta bloqueado?» y solo entonces «¿cuantos segundos?»
     * eran dos viajes a la cache para el mismo dato, y el segundo solo ocurria
     * cuando el primero decia que si: una llamada cuya presencia depende de la
     * respuesta a la anterior es la asimetria que ADR-039 saca del camino del
     * rechazo. `AuthenticateUserHandler` resuelve su flanco con este mismo
     * idioma.
     */
    private function recordFailure(string $subject, PinOrigin $origin): int
    {
        $this->attempts->recordFailure($subject, $origin);

        return $this->attempts->secondsUntilUnlock($subject, $origin);
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

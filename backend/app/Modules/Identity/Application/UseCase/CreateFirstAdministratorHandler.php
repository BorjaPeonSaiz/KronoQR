<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\CreateFirstAdministratorCommand;
use App\Modules\Identity\Application\Exception\ManagementAccountAlreadyExists;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\ManagementAccountRegistry;
use App\Modules\Identity\Application\Port\PasswordHasher;
use App\Modules\Identity\Application\Support\ManagementAccountRosterLock;
use App\Modules\Identity\Domain\Event\ManagementRoleAssigned;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;

/**
 * Crea la **primera** cuenta de gestion de la instalacion (**RF-PD-03**, paso 1
 * del asistente; `POST /api/v1/setup/administrator`).
 *
 * ## Por que esto existe, y por que no lo hace el instalador
 *
 * El instalador **no crea cuentas y no debe crearlas** (decision de la tarea
 * 5.4, doc 07 §6): una contrasena generada por un script acaba en el historial
 * del shell, en la lista de procesos o en un fichero de despliegue, y ahi se
 * queda. Sin este endpoint, una instalacion recien montada no tendria ninguna
 * puerta de entrada a su propio panel, y el cliente tendria que usar SSH — que
 * es lo que RF-PD-06 y el §11.6 dicen por escrito que no puede hacer falta.
 *
 * ## La unica escritura publica del producto, y su unica guarda
 *
 * Que no exista ninguna cuenta de gestion. **Ni activa ni desactivada**: contar
 * solo las activas convertiria «dar de baja a una persona» en «reabrir la
 * creacion publica de un administrador».
 *
 * ## No devuelve sesion: devuelve un reto
 *
 * RS-06 exige segundo factor a los roles de alcance global, y `admin` es uno de
 * ellos. La cuenta nace **sin poder entrar** y con un reto abierto; el TOTP se
 * da de alta y se confirma con `/auth/2fa/enrol` y `/auth/2fa/confirm`, que son
 * los endpoints que ya existen para eso y los unicos por los que sale un secreto
 * TOTP.
 *
 * **No se duplica aqui la generacion del secreto**, aunque habria sido mas corto
 * devolverlo en la misma respuesta: aquellos endpoints traen consigo el bloqueo
 * por intentos de codigo, la sustitucion del secreto sin confirmar y el asiento
 * de `auth.two_factor_enabled`. Una segunda via para lo mismo seria una segunda
 * via que mantener y una segunda por la que equivocarse.
 *
 * ## El rol deja asiento, y en la misma transaccion
 *
 * `role_assignment.changed` (regla dura 6, RS-05). Un `admin` creado sin traza
 * no tiene respuesta a «¿quien puso a esta persona al frente de la
 * instalacion?», que es la primera pregunta despues de un incidente. Va **dentro
 * de la transaccion** (ADR-010): si el asiento falla, la cuenta no se crea.
 *
 * **Sin actor en el asiento**, y no es un descuido: no hay ninguna sesion
 * detras, porque no puede haberla. Es lo mismo que hace `identity:create-user`
 * desde consola, y por el mismo motivo — atribuirselo a alguien seria falsificar
 * el trail.
 *
 * ## El token se emite FUERA de la transaccion
 *
 * Un reto emitido dentro de una transaccion que despues se deshace seria un
 * token vivo de una cuenta que no existe.
 */
final readonly class CreateFirstAdministratorHandler
{
    public function __construct(
        private ManagementAccountRegistry $accounts,
        private AccessTokenIssuer $tokens,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        private SerializedLedgerWrite $serialized,
        private PasswordHasher $hasher,
    ) {}

    /**
     * @throws ManagementAccountAlreadyExists si la instalacion ya tiene cuentas
     */
    public function handle(CreateFirstAdministratorCommand $command): LoginOutcome
    {
        // El hash, FUERA de todo candado: cuesta decenas de milisegundos y la
        // cadena de auditoria es la misma por la que pasa cada fichaje.
        $hash = $this->hasher->hash($command->password);

        // Orden unico del producto (ADR-010): cadena de auditoria, padron de
        // cuentas y fila. Antes se tomaba el padron y despues la cadena; no habia
        // ciclo real, pero un solo orden no exige razonarlo.
        $user = $this->serialized->withChainLock(
            fn (): AuthenticatedUser => $this->registerTheFirstOne($command, $hash),
        );

        return LoginOutcome::challenge(
            $user,
            $this->tokens->issuePendingFor($user, $command->deviceName),
            // Siempre `true`: la cuenta acaba de nacer y no puede tener un
            // segundo factor confirmado. Se pasa literal en lugar de preguntarlo
            // para que quede escrito que este camino **nunca** entrega sesion.
            enrolmentRequired: true,
        );
    }

    /**
     * La comprobacion y la escritura, **dentro de la misma transaccion y detras
     * de un candado**.
     *
     * ## El hueco que esto cierra
     *
     * Hasta la revision de la 5.5, la comprobacion vivia fuera de la transaccion:
     * dos peticiones simultaneas con correos distintos la pasaban las dos —no hay
     * ninguna cuenta todavia— y creaban **dos administradores**. En el unico
     * endpoint publico de escritura del producto, eso no es una carrera teorica:
     * es una segunda cuenta con acceso total a la instalacion, creada por quien
     * pasara por ahi en el mismo segundo. El `UNIQUE` de `users.email` no lo
     * impedia, porque los correos son distintos — que es justo el caso.
     *
     * ## Candado consultivo y no un indice
     *
     * Se valoro un indice unico parcial sobre `model_has_roles` acotado a los
     * roles de gestion, para que la garantia fuera del esquema como
     * `sites_single_row_uidx` lo es para el centro. **Se descarto**, y la razon es
     * que la invariante no es «una sola cuenta de gestion»: una instalacion
     * normal tiene varias —RRHH, auditoria, responsables— y crearlas es lo
     * corriente. Lo que es irrepetible es **la primera**, y eso es una condicion
     * de carrera de este caso de uso, no una forma del esquema. Un indice que
     * impidiera la segunda cuenta romperia el producto el dia 2.
     *
     * El candado se suelta al confirmar o al revertir: no hay forma de olvidarlo.
     */
    private function registerTheFirstOne(CreateFirstAdministratorCommand $command, string $hash): AuthenticatedUser
    {
        ManagementAccountRosterLock::acquire($this->connection);

        // DENTRO del candado, y por eso es correcta: quien llegue segundo espera
        // aqui a que el primero confirme, y entonces ve su cuenta.
        if ($this->accounts->anyManagementAccountExists()) {
            throw new ManagementAccountAlreadyExists;
        }

        return $this->register($command, $hash);
    }

    private function register(CreateFirstAdministratorCommand $command, string $hash): AuthenticatedUser
    {
        $user = $this->accounts->create(
            $command->name,
            $command->email,
            $hash,
            $command->locale,
            UserRole::ADMIN,
            // Contrasena propia: la eligio su titular en el asistente.
            null,
        );

        $this->events->publish(new ManagementRoleAssigned(
            userUuid: $user->uuid,
            role: UserRole::ADMIN,
            actorUuid: null,
            occurredAt: $this->clock->now(),
        ));

        return $user;
    }
}

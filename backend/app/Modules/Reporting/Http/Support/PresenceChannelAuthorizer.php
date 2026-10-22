<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Modules\Reporting\Application\Support\PresenceChannels;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\AuthorizationJournal;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Illuminate\Support\Facades\Gate;

/**
 * Quien puede suscribirse a la presencia en vivo por Reverb, y el asiento de
 * esa suscripcion (bloque 11 de la 2.3.0, tarea 11.4, R4-BE-05; RS-05, RL-15,
 * ADR-011, enmienda de ADR-037).
 *
 * ## Por que deja asiento
 *
 * La presencia por sondeo (`GET /reports/live`) deja `personal_data.accessed`
 * en cada lectura (agrupado). La suscripcion al canal entrega lo mismo —quien
 * entra y sale, en vivo— y no dejaba nada: con el tiempo real encendido, el
 * panel de presencia se podia mirar horas sin traza. Lo que se audita es la
 * **autorizacion** de la suscripcion (`broadcasting/auth`): cada mensaje que
 * llega despues no pasa por el servidor de la aplicacion.
 *
 * ## El asiento antes del `true`
 *
 * Si `audit_log` no se puede escribir, la excepcion sube y la suscripcion no
 * se concede: el panel cae al sondeo, que tambien deja asiento. Nunca un canal
 * abierto sin constancia.
 *
 * ## Contenido
 *
 * Conjunto `live_presence_stream`, `record_count = 0` (la suscripcion no
 * entrega filas: abre el grifo), y el contexto `{channel, department_id?,
 * scope}`. Sin nombres (regla dura 21). Se agrupa por actor y canal en la
 * ventana de `compliance.disclosure_grouping` —el cliente se reconecta a cada
 * caida de red—.
 *
 * ## Denegaciones
 *
 * Por rol o por licencia, `false` sin mas, como cualquier policy. Fuera de
 * alcance —un responsable que pide el canal de otro departamento, o el de toda
 * la instalacion—, `access.denied` por el diario de autorizacion, que lo agrupa
 * igual que en el resto de rutas.
 */
final readonly class PresenceChannelAuthorizer
{
    /** El conjunto del asiento, distinto del de la lectura por sondeo. */
    public const string DATASET = 'live_presence_stream';

    public function __construct(
        private FeatureGate $features,
        private ScopeGuard $scopes,
        private AuthorizationJournal $journal,
        private PersonalDataAccessLog $disclosures,
    ) {}

    /** El canal de toda la instalacion: solo un alcance sin restricciones. */
    public function joinAll(mixed $user): bool
    {
        if (! $this->allowedByRoleAndLicense($user)) {
            return false;
        }

        $scope = $this->scopes->scopeOf($user);

        return $this->granted(
            $scope,
            PresenceChannels::mayJoinAll($scope),
            ['channel' => 'all'],
        );
    }

    /** El canal de un departamento: solo si el alcance llega a el. */
    public function joinDepartment(mixed $user, string $departmentId): bool
    {
        if (! $this->allowedByRoleAndLicense($user) || ! ctype_digit($departmentId)) {
            return false;
        }

        $scope = $this->scopes->scopeOf($user);
        $id = (int) $departmentId;

        return $this->granted(
            $scope,
            PresenceChannels::mayJoinDepartment($scope, $id),
            ['channel' => 'department', 'department_id' => $id],
        );
    }

    private function allowedByRoleAndLicense(mixed $user): bool
    {
        return $this->features->isEnabled(Feature::RealtimePresence)
            && Gate::forUser($user)->allows('view', PresenceBoard::class);
    }

    /**
     * @param  array{channel: string, department_id?: int}  $channel
     */
    private function granted(AccessScope $scope, bool $reaches, array $channel): bool
    {
        if (! $reaches) {
            $this->journal->recordScopeDenial(self::DATASET, null, $channel);

            return false;
        }

        $this->disclosures->recordDisclosure(self::DATASET, 0, [
            ...$channel,
            'scope' => $scope->isUnrestricted() ? 'all' : 'departments',
        ]);

        return true;
    }
}

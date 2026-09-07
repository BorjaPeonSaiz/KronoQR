<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Persistence;

use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\ProvisionedDevice;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El alta y la lista de `devices`, escritas con el constructor de consultas
 * (**RF-PD-06**, RF-PA-07, doc 01 §5.5).
 *
 * Hermana de {@see DbDeviceFleet} y por los mismos motivos: `devices` es de este
 * modulo, y un segundo modelo Eloquent sobre la misma tabla —con sus `fillable`,
 * sus `casts` y sus `hidden`— seria una segunda definicion de la fila que habria
 * que mantener sincronizada con la de `Identity` sin que nada lo verifique.
 *
 * ## `provision()` reactiva por NOMBRE, y ese es el diseño
 *
 * Confirmar un codigo con el nombre de un quiosco **revocado** reactiva su fila,
 * con el mismo `uuid` (ADR-028): la fila es «el quiosco de Recepcion» y sobrevive
 * a la tablet que lo atiende. Es como se sustituye un aparato averiado sin partir
 * en dos la historia del puesto, y ademas nada se borra (regla dura 5).
 *
 * Con un quiosco **activo** con ese nombre se devuelve `null` y el borde responde
 * un `422` colgado del campo `name`: dos quioscos que se llaman igual convierten
 * cualquier diagnostico en una adivinanza, y el indice
 * `devices_site_id_name_unique` no lo permitiria de todos modos.
 *
 * **La reactivacion limpia `token_hash` y la telemetria.** El token anterior ya
 * no vale —`RevokeDeviceToken` lo borro al desvincular— y `last_seen_at` o
 * `pending_queue_size` de la tablet anterior dirian mentiras sobre la nueva: el
 * panel mostraria una cola de treinta y siete fichajes que ya no existe en ningun
 * aparato.
 *
 * ## Se lee y se escribe dentro de la transaccion del caso de uso
 *
 * El `SELECT ... FOR UPDATE` del nombre existente bloquea la fila mientras dura
 * el `confirm`, de modo que dos confirmaciones simultaneas con el mismo nombre no
 * puedan reactivarla las dos. La otra mitad de la carrera —el mismo codigo— la
 * arbitra el `UPDATE` condicional de {@see DbPairingRequests}.
 */
final readonly class DbDeviceRegistry implements DeviceRegistry
{
    private const string ACTIVE = 'active';

    private const string REVOKED = 'revoked';

    public function provision(int $siteId, string $name, ?string $appVersion, DateTimeImmutable $now): ?ProvisionedDevice
    {
        $existing = DB::table('devices')
            ->where('site_id', $siteId)
            ->where('name', $name)
            ->lockForUpdate()
            ->first(['id', 'uuid', 'status']);

        if ($existing !== null) {
            /** @var object{id: int|string, uuid: string, status: string} $existing */
            if ($existing->status === self::ACTIVE) {
                // El nombre esta en uso por un quiosco vivo. No es un rechazo del
                // codigo: es un `422` sobre `name`.
                return null;
            }

            DB::table('devices')->where('id', $existing->id)->update([
                'status' => self::ACTIVE,
                'paired_at' => $this->timestamp($now),
                // Ver el docblock: la telemetria de la tablet anterior no habla
                // de la que se acaba de colgar en la pared.
                'token_hash' => null,
                'app_version' => $appVersion,
                'last_seen_at' => null,
                'pending_queue_size' => 0,
                'updated_at' => $this->timestamp($now),
            ]);

            return new ProvisionedDevice(
                id: (int) $existing->id,
                uuid: $existing->uuid,
                name: $name,
                status: self::ACTIVE,
                reactivated: true,
                previousStatus: $existing->status,
            );
        }

        $uuid = Str::uuid7()->toString();

        try {
            $id = DB::table('devices')->insertGetId([
                'uuid' => $uuid,
                'site_id' => $siteId,
                'name' => $name,
                'token_hash' => null,
                'app_version' => $appVersion,
                'last_seen_at' => null,
                'pending_queue_size' => 0,
                'status' => self::ACTIVE,
                'paired_at' => $this->timestamp($now),
                'created_at' => $this->timestamp($now),
                'updated_at' => $this->timestamp($now),
            ]);
        } catch (QueryException $collision) {
            if (! $this->isDuplicateName($collision)) {
                throw $collision;
            }

            // **La carrera que el `SELECT ... FOR UPDATE` de arriba no cubre.**
            // Aquel bloquea una fila que EXISTE; cuando el nombre es nuevo no hay
            // fila que bloquear, asi que dos confirmaciones simultaneas con el
            // mismo nombre nuevo llegan las dos hasta aqui y una choca contra
            // `devices_site_id_name_unique`.
            //
            // Es el mismo desenlace que el nombre ya ocupado —un `422` sobre
            // `name`— y no un `500`: quien lo recibe tiene que cambiar el nombre,
            // que es exactamente lo que le dice esa respuesta. Se resuelve con el
            // indice y no con una consulta previa por el mismo motivo que el
            // codigo de emparejamiento (ver `DbPairingRequests::create()`).
            return null;
        }

        return new ProvisionedDevice(
            id: $id,
            uuid: $uuid,
            name: $name,
            status: self::ACTIVE,
            reactivated: false,
            previousStatus: null,
        );
    }

    public function all(): array
    {
        // Los activos primero y despues por nombre: quien abre la pantalla busca
        // el quiosco que no responde, no el que se dio de baja el año pasado.
        $rows = DB::table('devices')
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [self::ACTIVE])
            ->orderBy('name')
            ->get($this->columns());

        return array_values($rows->map(fn (object $row): DeviceSummary => $this->hydrate($row))->all());
    }

    public function findByUuid(string $deviceUuid): ?DeviceSummary
    {
        $row = DB::table('devices')->where('uuid', $deviceUuid)->first($this->columns());

        return $row === null ? null : $this->hydrate($row);
    }

    public function findById(int $deviceId): ?DeviceSummary
    {
        $row = DB::table('devices')->where('id', $deviceId)->first($this->columns());

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Si el fallo es el nombre duplicado dentro del centro.
     *
     * **Por el nombre del indice y no por el codigo `23505` a secas**: `devices`
     * tiene otros dos UNIQUE —el del `uuid` y el parcial del `token_hash`— y
     * confundirlos convertiria un fallo de verdad, como un UUID repetido, en un
     * `422` silencioso sobre un campo que no tiene nada que ver.
     */
    private function isDuplicateName(QueryException $failure): bool
    {
        return str_contains($failure->getMessage(), 'devices_site_id_name_unique');
    }

    /**
     * Las columnas que salen, escritas una vez.
     *
     * **`token_hash` no esta y no estara**: quien lo viera tendria la mitad del
     * trabajo hecho para suplantar a un dispositivo. Un `select *` habria dejado
     * esa decision al azar de lo que tenga la tabla el año que viene.
     *
     * @return list<string>
     */
    private function columns(): array
    {
        return ['id', 'uuid', 'name', 'status', 'app_version', 'last_seen_at', 'pending_queue_size', 'paired_at'];
    }

    private function hydrate(object $row): DeviceSummary
    {
        /**
         * @var object{
         *     id: int|string,
         *     uuid: string,
         *     name: string,
         *     status: string,
         *     app_version: string|null,
         *     last_seen_at: string|null,
         *     pending_queue_size: int|string,
         *     paired_at: string|null,
         * } $row
         */
        return new DeviceSummary(
            id: (int) $row->id,
            uuid: $row->uuid,
            name: $row->name,
            status: $row->status === self::ACTIVE ? self::ACTIVE : self::REVOKED,
            appVersion: $row->app_version,
            lastSeenAt: $this->instant($row->last_seen_at),
            pendingQueueSize: (int) $row->pending_queue_size,
            pairedAt: $this->instant($row->paired_at),
        );
    }

    private function instant(?string $value): ?DateTimeImmutable
    {
        // Regla dura 3: lo que sale de una columna `TIMESTAMPTZ` se normaliza a
        // UTC aqui, y la zona del centro solo se aplica en presentacion.
        return $value === null
            ? null
            : (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function timestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}

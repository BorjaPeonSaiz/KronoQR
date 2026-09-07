<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Persistence;

use App\Modules\Kiosk\Application\Port\PairingRequests;
use App\Modules\Kiosk\Domain\Model\PairingRequest;
use App\Modules\Kiosk\Domain\ValueObject\PairingStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `device_pairing_requests` escrita con el constructor de consultas
 * (**RF-PD-06**, tarea 5.6).
 *
 * ## Por que el constructor de consultas y no un modelo Eloquent
 *
 * Por lo mismo que {@see DbDeviceFleet}: un modelo con sus `fillable`, sus
 * `casts` y sus `hidden` seria una segunda definicion de la fila, y aqui lo que
 * hace falta son cuatro sentencias muy concretas —dos de ellas condicionales— que
 * dicen mas escritas que envueltas. Ademas, un modelo invita a `save()`, y
 * `save()` es exactamente la escritura no condicional que este puerto no puede
 * permitirse.
 *
 * ## LAS DOS ESCRITURAS CONDICIONALES SON EL CORAZON DE ESTA CLASE
 *
 * `markConfirmed()` y `markClaimed()` llevan el estado esperado **en el `WHERE`**
 * y devuelven si afectaron a alguna fila. No hay `SELECT` previo, y no puede
 * haberlo: entre leer y escribir cabe otra peticion, y el resultado seria dos
 * dispositivos para el mismo codigo o dos tokens para la misma tablet. Es la
 * misma decision que la idempotencia del fichaje, que se apoya en el UNIQUE de
 * `scan_events.scan_id` y no en una comprobacion previa (regla dura 8).
 *
 * ## El sorteo del codigo reintenta contra el indice, no contra un `SELECT`
 *
 * `create()` inserta y, si el UNIQUE parcial
 * `device_pairing_requests_code_hash_pending_uidx` se queja, **quien llama vuelve
 * a sortear**. Preguntar antes «¿existe este codigo?» tendria condicion de
 * carrera con otro `POST /kiosk/pair` simultaneo; el indice no la tiene.
 *
 * ## Los instantes se escriben con zona explicita
 *
 * `Y-m-d H:i:s.uP` sobre columnas `TIMESTAMPTZ` (regla dura 3). Sin el sufijo de
 * zona, PostgreSQL interpretaria el literal en la zona de la sesion, y una
 * caducidad de diez minutos podria acabar valiendo dos horas menos.
 */
final readonly class DbPairingRequests implements PairingRequests
{
    public function create(
        string $codeHash,
        string $secretHash,
        ?string $appVersion,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): ?string {
        $uuid = Str::uuid7()->toString();

        try {
            DB::table('device_pairing_requests')->insert([
                'uuid' => $uuid,
                'code_hash' => $codeHash,
                'secret_hash' => $secretHash,
                'status' => PairingStatus::Pending->value,
                'app_version' => $appVersion,
                'device_id' => null,
                'confirmed_by_user_id' => null,
                'expires_at' => $this->timestamp($expiresAt),
                'confirmed_at' => null,
                'claimed_at' => null,
                'created_at' => $this->timestamp($now),
                'updated_at' => $this->timestamp($now),
            ]);
        } catch (QueryException $collision) {
            if (! $this->isPendingCodeCollision($collision)) {
                // Cualquier otro fallo de base de datos sube: tragarselo dejaria
                // una tablet esperando un codigo que nunca llego a existir.
                throw $collision;
            }

            // El codigo lo tenia otra solicitud pendiente. Quien llama sortea
            // otro; la invariante la ha defendido el indice, no un `SELECT`.
            return null;
        }

        return $uuid;
    }

    public function findPendingByCodeHash(string $codeHash): ?array
    {
        $row = DB::table('device_pairing_requests')
            ->where('code_hash', $codeHash)
            ->where('status', PairingStatus::Pending->value)
            ->first(['uuid', 'status', 'expires_at', 'device_id', 'app_version', 'created_at']);

        if ($row === null) {
            return null;
        }

        /** @var object{app_version: string|null, created_at: string} $row */
        return [
            'request' => $this->hydrate($row),
            'app_version' => $row->app_version,
            // Normalizado a UTC aqui, como todo lo que sale de un `TIMESTAMPTZ`
            // (regla dura 3): la zona del centro solo se aplica en presentacion.
            'requested_at' => (new DateTimeImmutable($row->created_at))->setTimezone(new DateTimeZone('UTC')),
        ];
    }

    public function findByPairingId(string $pairingId): ?array
    {
        $row = DB::table('device_pairing_requests')
            ->where('uuid', $pairingId)
            ->first(['uuid', 'status', 'expires_at', 'device_id', 'secret_hash']);

        if ($row === null) {
            return null;
        }

        return [
            'request' => $this->hydrate($row),
            'secret_hash' => (string) $row->secret_hash,
        ];
    }

    public function markConfirmed(
        string $pairingId,
        int $deviceId,
        ?int $confirmedByUserId,
        DateTimeImmutable $now,
    ): bool {
        // El estado esperado va en el WHERE. Ver el docblock de la clase.
        return DB::table('device_pairing_requests')
            ->where('uuid', $pairingId)
            ->where('status', PairingStatus::Pending->value)
            ->update([
                'status' => PairingStatus::Confirmed->value,
                'device_id' => $deviceId,
                'confirmed_by_user_id' => $confirmedByUserId,
                'confirmed_at' => $this->timestamp($now),
                'updated_at' => $this->timestamp($now),
            ]) === 1;
    }

    public function markClaimed(string $pairingId, DateTimeImmutable $now): bool
    {
        return DB::table('device_pairing_requests')
            ->where('uuid', $pairingId)
            ->where('status', PairingStatus::Confirmed->value)
            ->update([
                'status' => PairingStatus::Claimed->value,
                'claimed_at' => $this->timestamp($now),
                'updated_at' => $this->timestamp($now),
            ]) === 1;
    }

    public function purgeOlderThan(DateTimeImmutable $olderThan): int
    {
        return DB::table('device_pairing_requests')
            ->where('created_at', '<', $this->timestamp($olderThan))
            ->delete();
    }

    public function purgeExpiredPending(DateTimeImmutable $now): int
    {
        // El indice unico parcial no sabe de relojes: una pendiente caducada
        // sigue ocupando su codigo hasta que alguien la borra. Ver el puerto.
        return DB::table('device_pairing_requests')
            ->where('status', PairingStatus::Pending->value)
            ->where('expires_at', '<', $this->timestamp($now))
            ->delete();
    }

    public function countLivePending(DateTimeImmutable $now): int
    {
        return DB::table('device_pairing_requests')
            ->where('status', PairingStatus::Pending->value)
            ->where('expires_at', '>=', $this->timestamp($now))
            ->count();
    }

    /**
     * Si el fallo es la colision del codigo con otra solicitud pendiente.
     *
     * Se distingue **por el nombre del indice** y no por el codigo de estado
     * `23505` a secas: la tabla tiene otro UNIQUE —el del `uuid`— y confundirlos
     * haria que un identificador duplicado, que seria un fallo de verdad, se
     * tragara en silencio como si fuera una colision de sorteo.
     */
    private function isPendingCodeCollision(QueryException $failure): bool
    {
        return str_contains(
            $failure->getMessage(),
            'device_pairing_requests_code_hash_pending_uidx',
        );
    }

    private function hydrate(object $row): PairingRequest
    {
        /** @var object{uuid: string, status: string, expires_at: string, device_id: int|string|null} $row */
        return PairingRequest::reconstitute(
            uuid: $row->uuid,
            status: PairingStatus::from($row->status),
            expiresAt: new DateTimeImmutable($row->expires_at, new DateTimeZone('UTC')),
            deviceId: $row->device_id === null ? null : (int) $row->device_id,
        );
    }

    private function timestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}

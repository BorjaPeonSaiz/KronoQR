<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Domain\Exception\EmployeeAlreadyHasCredential;
use App\Modules\Identity\Domain\Model\Credential as CredentialEntity;
use DateTimeImmutable;
use Illuminate\Database\QueryException;

/**
 * Las credenciales sobre Eloquent y PostgreSQL.
 *
 * **Traduce en los dos sentidos y no deja salir un modelo Eloquent.** Hacia
 * arriba solo viaja {@see CredentialEntity}. Si el caso de uso tuviera el modelo
 * tendria tambien `->delete()`, y una credencial borrada es un hueco en la
 * explicacion de por que alguien dejo de poder fichar (regla dura 5).
 *
 * **La unicidad de la credencial activa se detecta por la excepcion de
 * PostgreSQL, no por un `SELECT` previo.** Entre la consulta y la insercion cabe
 * otra emision —dos pestañas del panel, dos personas de RRHH— y esa comprobacion
 * solo daria una falsa sensacion de seguridad. Quien decide son los indices
 * parciales `one_pending_credential_per_employee` y
 * `one_active_credential_per_key_and_employee`.
 *
 * **Las marcas de tiempo se escriben en UTC explicito** (regla dura 3). Las
 * columnas son `TIMESTAMPTZ(6)`: pasar una cadena sin desplazamiento dejaria que
 * PostgreSQL la interpretara con la zona de la sesion.
 */
final readonly class EloquentCredentialRepository implements CredentialRepository
{
    public function add(CredentialEntity $credential): int
    {
        try {
            $row = Credential::query()->create($this->toRow($credential));
        } catch (QueryException $exception) {
            throw $this->translate($exception);
        }

        return $row->id;
    }

    /**
     * Escribe SOLO `revoked_at` y `revoked_reason`.
     *
     * **A propósito no es un `update($this->toRow(...))`.** Ese `toRow()` escribe
     * también `uuid`, `employee_id`, `key_id`, `secret_hash` e `issued_at`: son
     * inmutables desde que se crea la fila, y un `update()` de todas las columnas
     * las deja abiertas a reescritura por cualquier caso de uso futuro que llame a
     * `save()` con una entidad mal construida, sin que la base de datos lo impida
     * (revisión de seguridad de la tarea 1.5).
     *
     * La impresión y la entrega **no pasan por aquí** y tienen sus propios
     * métodos: las dos necesitan una condición en el `WHERE` que decida quién
     * gana cuando dos procesos llegan a la vez, y un `save()` genérico no puede
     * expresarla sin volverse un cajón de banderas.
     */
    public function save(CredentialEntity $credential): void
    {
        try {
            Credential::query()
                ->where('uuid', $credential->uuid)
                ->update([
                    'revoked_at' => $this->utc($credential->revokedAt),
                    'revoked_reason' => $credential->revokedReason,
                ]);
        } catch (QueryException $exception) {
            throw $this->translate($exception);
        }
    }

    /**
     * `UPDATE ... WHERE printed_at IS NULL`, y se comprueba el recuento de filas.
     *
     * Las tres columnas van en la misma sentencia porque el CHECK
     * `credentials_chk_minted_at_print` las exige juntas: PostgreSQL rechazaria
     * cualquier escritura parcial, que es exactamente la red que se quiere tener
     * debajo.
     *
     * `revoked_at IS NULL` va tambien en el `WHERE` aunque el agregado ya lo haya
     * comprobado: entre la lectura y esta sentencia cabe una revocacion, e
     * imprimir una tarjeta ya retirada produciria un QR que el quiosco rechaza y
     * una persona delante de la tablet sin entender por que.
     */
    public function markPrinted(CredentialEntity $credential): bool
    {
        try {
            $affected = Credential::query()
                ->where('uuid', $credential->uuid)
                ->whereNull('printed_at')
                ->whereNull('revoked_at')
                ->update([
                    'key_id' => $credential->keyId,
                    'secret_hash' => $credential->secretHash,
                    'printed_at' => $this->utc($credential->printedAt),
                ]);
        } catch (QueryException $exception) {
            throw $this->translate($exception);
        }

        return $affected > 0;
    }

    /**
     * `UPDATE ... WHERE delivered_at IS NULL AND printed_at IS NOT NULL`
     * (RF-QR-06).
     *
     * Las dos columnas de la entrega van juntas por el CHECK
     * `credentials_chk_delivery_is_signed`: no hay forma de escribir un momento
     * sin responsable ni al reves.
     */
    public function markDelivered(CredentialEntity $credential): bool
    {
        try {
            $affected = Credential::query()
                ->where('uuid', $credential->uuid)
                ->whereNull('delivered_at')
                ->whereNotNull('printed_at')
                ->whereNull('revoked_at')
                ->update([
                    'delivered_at' => $this->utc($credential->deliveredAt),
                    'delivered_by_user_id' => $credential->deliveredByUserId,
                ]);
        } catch (QueryException $exception) {
            throw $this->translate($exception);
        }

        return $affected > 0;
    }

    /**
     * La credencial vigente de cada empleado y, si no le queda ninguna activa, la
     * ultima que tuvo. **Una sola consulta para toda la plantilla.**
     *
     * `DISTINCT ON` es de PostgreSQL y aqui hace exactamente lo que hace falta:
     * agrupa por empleado y se queda con la primera fila del orden que se le da.
     * El orden dice la regla en una linea —primero las activas, luego la mas
     * reciente— y la alternativa portable seria una subconsulta con
     * `ROW_NUMBER()` que expresa lo mismo con el triple de SQL.
     *
     * `id DESC` desempata: dos credenciales emitidas en el mismo microsegundo son
     * improbables, pero un orden no determinista en un panel que RRHH consulta
     * dos veces seguidas si es un problema real.
     */
    public function latestForEmployees(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = Credential::query()
            ->select('credentials.*')
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('employee_id')
            ->orderByRaw('(revoked_at IS NULL) DESC')
            // Durante una rotacion con solape una persona tiene DOS activas: la
            // que lleva encima y la reemision pendiente de imprimir. El panel
            // responde a «quien no puede fichar todavia», asi que manda la que
            // esta usando —entregada antes que impresa, impresa antes que
            // pendiente— y no la mas nueva. Sin este orden, una rotacion pintaria
            // a toda la plantilla como «pendiente de imprimir» y la metrica
            // `employees_without_delivered_credential` se dispararia sin que
            // nadie hubiera dejado de poder fichar.
            ->orderByRaw('(delivered_at IS NOT NULL) DESC')
            ->orderByRaw('(printed_at IS NOT NULL) DESC')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->distinct('employee_id')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->employee_id] = $this->toEntity($row);
        }

        return $latest;
    }

    public function pendingPrintForEmployees(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        // El orden de la hoja A4 lo fija quien pide el lote —el directorio de
        // empleados ya viene ordenado por centro, departamento y apellido— y
        // aqui se respeta reindexando por empleado. Ordenar por `credentials.id`
        // dejaria las tarjetas en el orden en que se emitieron, que no es el
        // orden en que se reparten.
        $byEmployee = [];

        foreach (
            Credential::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereNull('printed_at')
                ->whereNull('revoked_at')
                ->get() as $row
        ) {
            $byEmployee[$row->employee_id] = $this->toEntity($row);
        }

        $pending = [];

        foreach ($employeeIds as $employeeId) {
            if (isset($byEmployee[$employeeId])) {
                $pending[] = $byEmployee[$employeeId];
            }
        }

        return $pending;
    }

    public function findByUuid(string $uuid): ?CredentialEntity
    {
        $row = Credential::query()->where('uuid', $uuid)->first();

        return $row instanceof Credential ? $this->toEntity($row) : null;
    }

    /**
     * El mismo orden que {@see latestForEmployees()} y por el mismo motivo: con
     * un solape de claves hay dos activas, y la que interesa —la que se revoca
     * al reemitir por perdida— es la que la persona lleva encima, no la que
     * sigue en la cola de impresion.
     */
    public function activeForEmployee(int $employeeId): ?CredentialEntity
    {
        $row = Credential::query()
            ->where('employee_id', $employeeId)
            ->whereNull('revoked_at')
            ->orderByRaw('(delivered_at IS NOT NULL) DESC')
            ->orderByRaw('(printed_at IS NOT NULL) DESC')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->first();

        return $row instanceof Credential ? $this->toEntity($row) : null;
    }

    public function countSignedWith(string $keyId): int
    {
        return Credential::query()->where('key_id', $keyId)->count();
    }

    public function activeSignedWith(string $keyId): array
    {
        $rows = Credential::query()
            ->where('key_id', $keyId)
            ->whereNull('revoked_at')
            ->whereNotNull('printed_at')
            ->orderBy('employee_id')
            ->orderBy('id')
            ->get();

        return array_values(array_map($this->toEntity(...), $rows->all()));
    }

    public function activeCountsByKeyId(): array
    {
        $counts = [];

        // `pluck` sobre un `GROUP BY` y no un `count()` por clave: el llavero
        // tiene dos claves hoy, pero la pregunta que este metodo responde es
        // «que claves quedan vivas», y esa incluye las que ya no estan
        // configuradas — que son justo las que delatan una rotacion sin cerrar.
        $rows = Credential::query()
            ->whereNull('revoked_at')
            ->whereNotNull('key_id')
            ->groupBy('key_id')
            ->selectRaw('key_id, count(*) as total')
            ->pluck('total', 'key_id');

        foreach ($rows as $keyId => $total) {
            if (\is_string($keyId) && is_numeric($total)) {
                $counts[$keyId] = (int) $total;
            }
        }

        return $counts;
    }

    public function otherActivePrintedForEmployee(int $employeeId, string $exceptUuid): array
    {
        $rows = Credential::query()
            ->where('employee_id', $employeeId)
            ->where('uuid', '!=', $exceptUuid)
            ->whereNull('revoked_at')
            ->whereNotNull('printed_at')
            ->orderBy('id')
            ->get();

        return array_values(array_map($this->toEntity(...), $rows->all()));
    }

    public function findByKeyAndSecretHash(string $keyId, string $secretHash): ?CredentialEntity
    {
        // Consulta exacta sobre `credentials_key_id_secret_hash_unique`: un solo
        // acceso al indice, sin recorrer nada. Es el camino mas caliente del
        // producto y tambien el que RS-03 obliga a que cueste lo mismo acierte o
        // falle, cosa que un indice unico da de serie.
        $row = Credential::query()
            ->where('key_id', $keyId)
            ->where('secret_hash', $secretHash)
            ->first();

        return $row instanceof Credential ? $this->toEntity($row) : null;
    }

    public function countActiveSignedWith(string $keyId): int
    {
        return Credential::query()
            ->where('key_id', $keyId)
            ->whereNull('revoked_at')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(CredentialEntity $credential): array
    {
        return [
            'uuid' => $credential->uuid,
            'employee_id' => $credential->employeeId,
            'key_id' => $credential->keyId,
            'secret_hash' => $credential->secretHash,
            'issued_at' => $this->utc($credential->issuedAt),
            'printed_at' => $this->utc($credential->printedAt),
            'delivered_at' => $this->utc($credential->deliveredAt),
            'delivered_by_user_id' => $credential->deliveredByUserId,
            'revoked_at' => $this->utc($credential->revokedAt),
            'revoked_reason' => $credential->revokedReason,
        ];
    }

    private function utc(?DateTimeImmutable $instant): ?string
    {
        return $instant?->format('Y-m-d H:i:s.uP');
    }

    private function toEntity(Credential $row): CredentialEntity
    {
        return new CredentialEntity(
            uuid: $row->uuid,
            employeeId: $row->employee_id,
            keyId: $row->key_id,
            secretHash: $row->secret_hash,
            issuedAt: $row->issued_at->toDateTimeImmutable(),
            printedAt: $row->printed_at?->toDateTimeImmutable(),
            deliveredAt: $row->delivered_at?->toDateTimeImmutable(),
            deliveredByUserId: $row->delivered_by_user_id,
            revokedAt: $row->revoked_at?->toDateTimeImmutable(),
            revokedReason: $row->revoked_reason,
            id: $row->id,
        );
    }

    private function translate(QueryException $exception): QueryException|EmployeeAlreadyHasCredential
    {
        // Las dos mitades de la invariante: una pendiente por empleado y una
        // escaneable por empleado y clave. Las dos significan lo mismo hacia
        // arriba —esa persona ya tiene esa tarjeta— y las dos son un 409.
        if (str_contains($exception->getMessage(), 'credential_per_employee')
            || str_contains($exception->getMessage(), 'credential_per_key_and_employee')) {
            return EmployeeAlreadyHasCredential::make();
        }

        // Cualquier otro fallo de base de datos sube tal cual: convertirlo en un
        // conflicto de negocio ocultaria un problema real.
        return $exception;
    }
}

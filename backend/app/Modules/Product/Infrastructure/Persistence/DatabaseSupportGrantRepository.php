<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Domain\Model\SupportGrant as SupportGrantEntity;
use App\Modules\Product\Domain\ValueObject\SupportGrantAuthor;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * La tabla `support_grants` (**RF-PD-11**).
 *
 * ## Aqui la lectura NO es tolerante, al contrario que la licencia
 *
 * `DatabaseLicenseRepository` devuelve `null` ante cualquier `Throwable` porque
 * su lectura esta en el camino de cualquier pantalla del panel y una fila
 * corrupta no puede tumbar el producto (ADR-019). Este repositorio es lo
 * contrario: **una lectura que falla en silencio aqui es un acceso del fabricante
 * que no aparece en la lista**, y esa lista es la unica mitad visible para el
 * cliente de RF-PD-11. Si algo va mal, tiene que verse.
 *
 * Lo que si es tolerante es el `scope` de una fila concreta: si tuviera un valor
 * que este binario no conoce —imposible con el `CHECK`, salvo restauracion
 * parcial— la fila se sirve con el alcance mas estrecho en lugar de romper el
 * listado entero. Fallar cerrado y seguir contando.
 *
 * ## El JOIN con `users` no es opcional
 *
 * El contrato exige `granted_by: {uuid, name}`, y esa es la pregunta que hace
 * quien mira la pantalla: no «la concedio el usuario 4». La clave ajena es
 * `restrict`, asi que la cuenta no puede desaparecer y el `INNER JOIN` no pierde
 * filas.
 */
final readonly class DatabaseSupportGrantRepository implements SupportGrantRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function store(SupportGrantEntity $grant): int
    {
        $id = $this->connection->table('support_grants')->insertGetId([
            'uuid' => $grant->uuid,
            'granted_by_user_id' => $grant->grantedBy->id,
            'reason' => $grant->reason,
            'scope' => $grant->scope->value,
            'granted_at' => self::utc($grant->grantedAt),
            'expires_at' => self::utc($grant->expiresAt),
            'created_at' => self::utc($grant->grantedAt),
            'updated_at' => self::utc($grant->grantedAt),
        ]);

        return (int) $id;
    }

    public function findByUuid(string $uuid): ?SupportGrantEntity
    {
        $rows = $this->connection->select($this->selectWithAuthor().' WHERE g.uuid = ?', [$uuid]);

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    public function authorOf(int $userId): ?SupportGrantAuthor
    {
        $rows = $this->connection->select('SELECT id, uuid, name FROM users WHERE id = ?', [$userId]);

        if ($rows === []) {
            return null;
        }

        return self::authorFrom(Row::of($rows[0]));
    }

    public function authorByEmail(string $email): ?SupportGrantAuthor
    {
        $rows = $this->connection->select(
            'SELECT id, uuid, name FROM users WHERE lower(email) = lower(?) AND is_active = true',
            [$email],
        );

        return $rows === [] ? null : self::authorFrom(Row::of($rows[0]));
    }

    public function soleAuthor(): ?SupportGrantAuthor
    {
        // `LIMIT 2` y no `LIMIT 1`: hay que poder distinguir «una» de «varias»,
        // y con `LIMIT 1` las dos se verian igual.
        $rows = $this->connection->select(
            'SELECT id, uuid, name FROM users WHERE is_active = true ORDER BY id LIMIT 2'
        );

        return \count($rows) === 1 ? self::authorFrom(Row::of($rows[0])) : null;
    }

    public function recent(int $limit): array
    {
        return $this->hydrateAll($this->connection->select(
            $this->selectWithAuthor().' ORDER BY g.granted_at DESC, g.id DESC LIMIT '.max(1, $limit)
        ));
    }

    public function active(DateTimeImmutable $now): array
    {
        return $this->hydrateAll($this->connection->select(
            $this->selectWithAuthor().' WHERE g.revoked_at IS NULL AND g.expires_at > ? ORDER BY g.granted_at DESC, g.id DESC',
            [self::utc($now)],
        ));
    }

    public function markRevoked(int $grantId, DateTimeImmutable $revokedAt, ?int $revokedByUserId): int
    {
        /*
         * `WHERE revoked_at IS NULL` ademas del identificador.
         *
         * Es lo que hace idempotente la revocacion **en la base de datos** y no
         * solo en el caso de uso: con dos pestañas pulsando el boton a la vez, la
         * segunda actualiza cero filas en lugar de reescribir el instante de la
         * primera. Lo que consta entonces es la revocacion que de verdad retiro
         * el acceso, que es la que importa.
         *
         * **Y el recuento se devuelve**, porque es la unica forma de que el caso
         * de uso sepa cual de las dos fue: la comprobacion en memoria del
         * dominio no distingue una carrera, y sin esto se escribirian dos
         * asientos del mismo hecho.
         */
        return $this->connection->table('support_grants')
            ->where('id', $grantId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => self::utc($revokedAt),
                'revoked_by_user_id' => $revokedByUserId,
                'updated_at' => self::utc($revokedAt),
            ]);
    }

    public function storeTokenHash(int $grantId, string $tokenHash): void
    {
        $this->connection->table('support_grants')
            ->where('id', $grantId)
            ->update(['token_hash' => $tokenHash]);
    }

    public function markAccessed(int $grantId, DateTimeImmutable $accessedAt): void
    {
        // Una sola columna y ninguna mas: este `UPDATE` corre en CADA peticion de
        // una sesion de soporte, y escribir la fila entera aqui pisaria una
        // revocacion hecha un segundo antes desde otra pantalla.
        $this->connection->table('support_grants')
            ->where('id', $grantId)
            ->update(['accessed_at' => self::utc($accessedAt)]);
    }

    private function selectWithAuthor(): string
    {
        return <<<'SQL'
            SELECT g.id, g.uuid, g.reason, g.scope, g.granted_at, g.expires_at,
                   g.revoked_at, g.revoked_by_user_id, g.accessed_at,
                   u.id AS author_id, u.uuid AS author_uuid, u.name AS author_name
              FROM support_grants g
              INNER JOIN users u ON u.id = g.granted_by_user_id
            SQL;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<SupportGrantEntity>
     */
    private function hydrateAll(array $rows): array
    {
        $grants = [];

        foreach ($rows as $row) {
            // El controlador de consultas devuelve `mixed` y el driver de
            // PostgreSQL no promete el tipo: la comprobacion es la misma que hace
            // `Row` con cada columna, un escalon mas arriba. Una fila que no sea
            // un objeto no puede darse y, si se diera, saltarsela es preferible a
            // romper el listado entero de accesos de soporte.
            if (\is_object($row)) {
                $grants[] = $this->hydrate(Row::of($row));
            }
        }

        return $grants;
    }

    private function hydrate(Row $row): SupportGrantEntity
    {
        return SupportGrantEntity::fromStorage(
            id: $row->int('id'),
            uuid: $row->string('uuid'),
            grantedBy: new SupportGrantAuthor(
                id: $row->int('author_id'),
                uuid: $row->string('author_uuid'),
                name: $row->string('author_name'),
            ),
            reason: $row->string('reason'),
            // Fallar cerrado: un alcance ilegible se sirve como el mas estrecho.
            scope: SupportScope::tryFrom($row->string('scope')) ?? SupportScope::default(),
            grantedAt: $row->instant('granted_at'),
            expiresAt: $row->instant('expires_at'),
            revokedAt: $row->nullableInstant('revoked_at'),
            revokedByUserId: $row->nullableInt('revoked_by_user_id'),
            accessedAt: $row->nullableInstant('accessed_at'),
        );
    }

    private static function authorFrom(Row $row): SupportGrantAuthor
    {
        return new SupportGrantAuthor(
            id: $row->int('id'),
            uuid: $row->string('uuid'),
            name: $row->string('name'),
        );
    }

    /** Todo instante se guarda en UTC (regla dura 3). */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La tabla `license`, con **lectura tolerante y escritura estricta** (tarea 5.3).
 *
 * ## La lectura no puede fallar
 *
 * Es la misma politica que la tarea 5.1 impuso a la configuracion, y aqui pesa
 * lo mismo: este adaptador lo consulta el `FeatureGate`, y el `FeatureGate` lo
 * consulta cualquier pantalla del panel. Si una fila corrupta —o una tabla que
 * todavia no existe porque la migracion no se ha aplicado— pudiera lanzar, el
 * sintoma en casa del cliente seria «el panel se ha caido» y la causa seria la
 * licencia. ADR-019 existe precisamente para que eso no pase.
 *
 * Asi que {@see self::current()} devuelve `null` ante cualquier `Throwable` y
 * deja un `warning` sin datos personales ni clave. `null` significa «sin
 * licencia», que es un estado normal del producto y no una averia.
 *
 * ## Sin cache
 *
 * A diferencia de `installation_settings`, que se lee en **cada escaneo** y por
 * eso lleva Redis delante, la licencia solo se resuelve en la API de gestion y
 * en la consola: una fila, un indice unico y unas pocas consultas por pantalla.
 * Una cache aqui añadiria una ventana en la que una clave recien activada
 * todavia no surte efecto, que es justo lo que no se quiere el dia que alguien
 * renueva.
 *
 * La memoria **por peticion** si existe, y la pone el contenedor con `scoped()`
 * sobre el `FeatureGate`.
 */
final readonly class DatabaseLicenseRepository implements LicenseRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
    ) {}

    public function current(): ?StoredLicense
    {
        try {
            $rows = $this->connection->select(
                'SELECT signed_key, activated_at, last_verified_at FROM license LIMIT 1'
            );
        } catch (Throwable $exception) {
            // Sin `license` no hay funcionalidad accesoria, y ya esta. Ni el
            // fichaje ni la consulta del registro pasan por aqui.
            $this->logger->warning('product.license_unreadable', [
                'reason' => $exception::class,
            ]);

            return null;
        }

        if ($rows === []) {
            return null;
        }

        $row = Row::of($rows[0]);

        try {
            return new StoredLicense(
                signedKey: $row->string('signed_key'),
                activatedAt: $row->instant('activated_at'),
                lastVerifiedAt: $row->nullableInstant('last_verified_at'),
            );
        } catch (Throwable $exception) {
            $this->logger->warning('product.license_row_malformed', [
                'reason' => $exception::class,
            ]);

            return null;
        }
    }

    public function activate(
        string $signedKey,
        License $license,
        DateTimeImmutable $activatedAt,
        ?int $actorUserId,
    ): void {
        $values = [
            'signed_key' => $signedKey,
            'license_id' => $license->licenseId,
            'customer_name' => $license->customerName,
            'plan' => $license->plan,
            'max_employees' => $license->limits->maxEmployees,
            'max_devices' => $license->limits->maxDevices,
            'features' => json_encode($license->featureNames(), JSON_THROW_ON_ERROR),
            'valid_from' => self::utc($license->validFrom),
            'valid_until' => self::utc($license->validUntil),
            'issued_at' => self::utc($license->issuedAt),
            'activated_at' => self::utc($activatedAt),
            'activated_by_user_id' => $actorUserId,
            'last_verified_at' => self::utc($activatedAt),
        ];

        /*
         * Sustituye la fila que haya, sea cual sea su `id`.
         *
         * `UPDATE` primero y `INSERT` si no habia nada, en vez de un `upsert`
         * por clave: el indice unico va sobre una expresion constante y no sobre
         * una columna, asi que no hay conflicto que `ON CONFLICT` pueda nombrar.
         * Las dos sentencias van dentro de la transaccion del caso de uso.
         */
        $updated = $this->connection->table('license')->update($values);

        if ($updated === 0) {
            $this->connection->table('license')->insert($values);
        }
    }

    /**
     * Anota la ultima verificacion correcta, y **no puede fallar hacia arriba**.
     *
     * Es una escritura en el camino de una LECTURA: la recorren
     * `GET /api/v1/license` y `license:show`, que son las dos superficies desde
     * las que se diagnostica un problema de licencia. Si la base de datos esta
     * en solo lectura, el disco lleno o el rol sin permiso de `UPDATE`, dejar
     * subir la excepcion convertiria «mira como esta tu licencia» en un `500`
     * con traza — justo cuando alguien intenta averiguar que pasa.
     *
     * Y lo que se pierde al tragarla es **una marca de diagnostico**, no un dato
     * con valor: el estado se recalcula siempre desde la clave firmada, y la
     * activacion —que si es una escritura deliberada— sigue siendo estricta
     * dentro de su transaccion.
     *
     * El aviso no lleva la clave ni el nombre del cliente: sale en el log
     * tecnico y en el paquete de diagnostico (regla dura 21, ADR-020).
     */
    public function markVerified(DateTimeImmutable $verifiedAt): void
    {
        try {
            $this->connection->table('license')->update(['last_verified_at' => self::utc($verifiedAt)]);
        } catch (Throwable $exception) {
            $this->logger->warning('product.license_touch_failed', [
                'reason' => $exception::class,
            ]);
        }
    }

    /**
     * Formato explicito y en UTC (regla dura 3). Sin esto, el driver aplicaria
     * la zona de la sesion y la caducidad de una licencia se desplazaria segun
     * el servidor.
     */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}

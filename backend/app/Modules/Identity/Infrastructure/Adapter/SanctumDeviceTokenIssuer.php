<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\DeviceRepository;
use App\Modules\Identity\Application\Port\DeviceTokenIssuer;
use App\Modules\Identity\Domain\Model\Device as DeviceEntity;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenRecord;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\Device;
use DateTimeImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * El token de un quiosco, sobre Laravel Sanctum (RF-ID-04, RS-04, doc 02 §7.3).
 *
 * **Tres decisiones que sostienen la promesa del §7.3.**
 *
 * 1. **Los ambitos salen de {@see TokenAbility::kioskAbilities()} y no de una
 *    lista escrita aqui.** Es lo unico que impide que un token de quiosco acabe
 *    con `employees:*` el dia que alguien copie y pegue el emisor de sesiones.
 * 2. **Emparejar retira lo anterior; rotar, no del todo.** El emparejamiento
 *    borra los tokens que ese dispositivo tuviera antes de crear el nuevo: una
 *    tablet emparejada dos veces con dos tokens vivos deja uno funcionando
 *    cuando se revoca el otro. La rotacion (ADR-044) deja el anterior **en
 *    solape**, con la caducidad adelantada, hasta que el nuevo se use o venza el
 *    plazo: como mucho hay dos, y revocar el dispositivo borra los dos a la vez.
 * 3. **El hash se copia a `devices.token_hash` en la misma operacion.** Sanctum
 *    guarda su propio hash en `personal_access_tokens.token`; la copia existe
 *    para que la fila del dispositivo se explique sola (ver el modelo). Los dos
 *    son SHA-256 del mismo valor, asi que no pueden discrepar en contenido, solo
 *    en existencia — y por eso se escriben juntos.
 *
 * **El token en claro no se registra en ningun sitio.** Sale dentro de
 * {@see IssuedAccessToken}, viaja en la respuesta que lo entrega y se olvida.
 */
final readonly class SanctumDeviceTokenIssuer implements DeviceTokenIssuer
{
    public function __construct(private DeviceRepository $devices) {}

    public function issueFor(DeviceEntity $device, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): IssuedAccessToken
    {
        $row = $this->rowOf($device);

        $this->deleteTokensOf($row);

        return $this->create($row, $device, $expiresAt);
    }

    public function issueAlongside(DeviceEntity $device, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): IssuedAccessToken
    {
        return $this->create($this->rowOf($device), $device, $expiresAt);
    }

    public function revokeAllFor(DeviceEntity $device): void
    {
        $this->deleteTokensOf($this->rowOf($device));
    }

    public function lockedTokensOf(DeviceEntity $device): array
    {
        $row = Device::query()->whereKey($device->id)->lockForUpdate()->first();

        if (! $row instanceof Device) {
            throw new RuntimeException('El dispositivo ha dejado de existir mientras se rotaba su token.');
        }

        $records = [];

        /** @var PersonalAccessToken $token */
        foreach ($row->tokens()->orderBy('id')->get() as $token) {
            $issuedAt = $token->created_at?->toDateTimeImmutable();
            $key = $token->getKey();

            if ($issuedAt === null || ! is_numeric($key)) {
                // Sanctum siempre rellena `created_at`. Una fila sin el se ha
                // tocado a mano y no se puede razonar sobre su vida: se omite, y
                // si era la que firmo, la politica no rota.
                continue;
            }

            $records[] = new DeviceTokenRecord(
                id: (int) $key,
                issuedAt: $issuedAt,
                expiresAt: $token->expires_at?->toDateTimeImmutable(),
            );
        }

        return $records;
    }

    public function shortenExpiry(DeviceEntity $device, int $tokenId, DateTimeImmutable $until): void
    {
        $this->rowOf($device)->tokens()
            ->whereKey($tokenId)
            ->where(static function (Builder $query) use ($until): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $until);
            })
            ->update(['expires_at' => $until]);
    }

    public function retire(DeviceEntity $device, array $tokenIds): void
    {
        if ($tokenIds === []) {
            return;
        }

        $this->rowOf($device)->tokens()->whereKey($tokenIds)->delete();
    }

    /**
     * Retira los tokens del dispositivo **anteriores** al que acaba de usarse
     * por primera vez (ADR-044): el relevo llego, y el solape sobra.
     *
     * Lo llama la comprobacion de cada peticion autenticada de
     * `IdentityServiceProvider`, y solo en el primer uso de un token —con
     * `last_used_at` vacio—, asi que en el caso normal no escribe nada.
     */
    public static function retireSupersededBy(Device $row, PersonalAccessToken $token): void
    {
        $row->tokens()->where('id', '<', $token->getKey())->delete();
    }

    private function create(Device $row, DeviceEntity $device, DateTimeImmutable $expiresAt): IssuedAccessToken
    {
        $token = $row->createToken(
            name: 'kiosk:'.$device->uuid,
            abilities: array_map(
                static fn (TokenAbility $ability): string => $ability->value,
                TokenAbility::kioskAbilities(),
            ),
            expiresAt: $expiresAt,
        );

        // Mismo algoritmo que usa Sanctum para su columna `token`.
        $this->devices->storeTokenHash($device->id, hash('sha256', $this->secretOf($token->plainTextToken)));

        return new IssuedAccessToken($token->plainTextToken, $expiresAt);
    }

    private function rowOf(DeviceEntity $device): Device
    {
        $row = Device::query()->whereKey($device->id)->first();

        if (! $row instanceof Device) {
            // Solo puede ocurrir si el dispositivo desaparece entre que el caso
            // de uso lo lee y emite su token. No se degrada a «token sin dueno».
            throw new RuntimeException('El dispositivo ha dejado de existir mientras se emitia su token.');
        }

        return $row;
    }

    private function deleteTokensOf(Device $row): void
    {
        $row->tokens()->delete();
    }

    /**
     * La mitad secreta de un token de Sanctum: `<id>|<secreto>`.
     *
     * Se hashea solo el secreto porque es lo que Sanctum guarda en su columna.
     * Hashear la cadena entera daria un valor distinto y `devices.token_hash`
     * dejaria de poder cotejarse con `personal_access_tokens.token`, que es toda
     * su razon de ser.
     */
    private function secretOf(string $plainTextToken): string
    {
        $separator = strpos($plainTextToken, '|');

        return $separator === false ? $plainTextToken : substr($plainTextToken, $separator + 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Policy;

use App\Modules\Identity\Domain\ValueObject\DeviceTokenLifetime;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenRecord;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenRotation;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Cuando se rota el token de un quiosco y que pasa con el anterior (RF-ID-04,
 * doc 02 §7.3, ADR-044).
 *
 * ## La regla
 *
 * Se decide **por el token que firmo el latido**, no por el dispositivo:
 *
 * 1. **Es el mas reciente del dispositivo y ha pasado el umbral** (0,8 de su
 *    vida, {@see DeviceTokenLifetime}): se emite el relevo y el firmante **entra
 *    en solape** hasta `min(su caducidad, ahora + solape)`. Si la respuesta se
 *    pierde, el quiosco sigue fichando con el viejo.
 * 2. **Es el mas reciente y no ha pasado el umbral**: no se emite nada.
 * 3. **No es el mas reciente**, es decir, esta en solape: el relevo que se le
 *    emitio **no se ha usado nunca** —su primer uso habria retirado a este— y por
 *    tanto la respuesta que lo llevaba no llego. Se retira ese relevo y se emite
 *    otro. **El solape del firmante no se toca**: un token en solape no puede
 *    abrir un solape nuevo ni alargar el suyo, asi que un token robado en ese
 *    intervalo no se convierte en una cadena de tokens validos mas alla de la
 *    fecha que ya tenia.
 *
 * En los dos primeros casos, cualquier token **anterior** al firmante se retira:
 * si el mas reciente esta firmando, el relevo ya llego y el solape sobra. Es la
 * red de seguridad del primer uso, que ya lo retira al autenticar.
 *
 * ## Por que el valor no se guarda para reentregarlo
 *
 * Porque entonces habria un token en claro en la base de datos. Reemitir es
 * igual de barato, deja un asiento propio y hace que cada valor salga del
 * servidor **una sola vez**.
 *
 * **Sin reloj dentro** (regla dura 2): el instante entra como parametro.
 */
final readonly class DeviceTokenRotationPolicy
{
    public function __construct(
        /** Fraccion de vida a partir de la cual se rota (§7.3: 0,8). */
        public float $rotationThreshold,
        /** Duracion maxima del solape del token relevado, en segundos. */
        public int $overlapSeconds,
    ) {
        if ($rotationThreshold <= 0.0 || $rotationThreshold > 1.0) {
            throw new InvalidArgumentException('El umbral de rotacion es una fraccion de vida entre 0 y 1.');
        }

        if ($overlapSeconds < 1) {
            throw new InvalidArgumentException('El solape del token relevado dura al menos un segundo.');
        }
    }

    /**
     * @param  list<DeviceTokenRecord>  $tokens  Los tokens vivos del dispositivo.
     * @param  int  $presentedTokenId  El que firmo la peticion.
     */
    public function decide(array $tokens, int $presentedTokenId, DateTimeImmutable $now): DeviceTokenRotation
    {
        $presented = self::find($tokens, $presentedTokenId);

        // El firmante ya no existe —se revoco o se retiro entre la autenticacion
        // y esta decision—: no hay nada que rotar ni nadie a quien entregarlo.
        if (! $presented instanceof DeviceTokenRecord) {
            return DeviceTokenRotation::none();
        }

        $others = self::idsOtherThan($tokens, $presented->id);

        if ($presented->id !== self::latestId($tokens)) {
            return self::redeliverTo($presented, $others);
        }

        $expiresAt = $presented->expiresAt;

        if (! $expiresAt instanceof DateTimeImmutable || ! $this->isDue($presented->issuedAt, $expiresAt, $now)) {
            return DeviceTokenRotation::none($others);
        }

        // El solape nunca alarga la vida del token relevado: el menor de los dos.
        return DeviceTokenRotation::rotate(
            $presented->id,
            min($now->add(new DateInterval('PT'.$this->overlapSeconds.'S')), $expiresAt),
            $others,
        );
    }

    /**
     * Caso 3: el firmante esta en solape y su relevo no llego.
     *
     * @param  list<int>  $others
     */
    private static function redeliverTo(DeviceTokenRecord $presented, array $others): DeviceTokenRotation
    {
        // Sin caducidad no habria solape que respetar, y ese token no existe en
        // este producto: ante la duda, no se emite nada.
        if (! $presented->expiresAt instanceof DateTimeImmutable) {
            return DeviceTokenRotation::none();
        }

        return DeviceTokenRotation::redeliver($presented->id, $presented->expiresAt, $others);
    }

    private function isDue(DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        // Un token sin caducidad (filtrado por quien llama), o con una caducidad
        // que no es posterior a su emision, no tiene «80 % de vida»: rotarlo por
        // si acaso emitiria un token sin motivo.
        if ($expiresAt <= $issuedAt) {
            return false;
        }

        return (new DeviceTokenLifetime($issuedAt, $expiresAt, $this->rotationThreshold))->isRotationDue($now);
    }

    /**
     * @param  list<DeviceTokenRecord>  $tokens
     */
    private static function find(array $tokens, int $id): ?DeviceTokenRecord
    {
        foreach ($tokens as $token) {
            if ($token->id === $id) {
                return $token;
            }
        }

        return null;
    }

    /**
     * El mas reciente: los identificadores crecen con cada emision.
     *
     * @param  list<DeviceTokenRecord>  $tokens
     */
    private static function latestId(array $tokens): ?int
    {
        $ids = array_map(static fn (DeviceTokenRecord $token): int => $token->id, $tokens);

        return $ids === [] ? null : max($ids);
    }

    /**
     * @param  list<DeviceTokenRecord>  $tokens
     * @return list<int>
     */
    private static function idsOtherThan(array $tokens, int $id): array
    {
        $others = [];

        foreach ($tokens as $token) {
            if ($token->id !== $id) {
                $others[] = $token->id;
            }
        }

        return $others;
    }
}

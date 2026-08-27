<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * La vida de un token de quiosco y la regla que decide cuando rotarlo
 * (RF-ID-04, doc 02 §7.3: «90 dias, rotacion automatica al 80 % de vida»).
 *
 * **Por que la rotacion es al 80 % y no el ultimo dia.** Una tablet colgada en
 * la pared de una cocina puede pasar dias sin conexion con el servidor. Si el
 * token se renovara al caducar, un quiosco que estuvo apagado una semana
 * volveria con un token muerto y **dejaria de admitir fichajes**, que es
 * exactamente lo que la regla dura 19 prohibe. Renovando al 80 % quedan 18 dias
 * de margen: hace falta que el quiosco este mas de dos semanas incomunicado para
 * que el token caduque, y para entonces la alerta de latido ya sono.
 *
 * **Sin reloj dentro** (regla dura 2): el instante entra como parametro. Sin eso
 * no se puede probar el limite exacto del 80 % sin esperar 72 dias.
 */
final readonly class DeviceTokenLifetime
{
    public function __construct(
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        /** Fraccion de vida consumida a partir de la cual toca rotar. */
        public float $rotationThreshold,
    ) {
        if ($expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('Un token de dispositivo caduca despues de emitirse.');
        }

        if ($rotationThreshold <= 0.0 || $rotationThreshold > 1.0) {
            throw new InvalidArgumentException('El umbral de rotacion es una fraccion de vida entre 0 y 1.');
        }
    }

    /**
     * Momento a partir del cual el token se renueva en el siguiente latido.
     */
    public function rotationDueAt(): DateTimeImmutable
    {
        $life = $this->expiresAt->getTimestamp() - $this->issuedAt->getTimestamp();

        // `(int)` trunca hacia cero, asi que el instante de rotacion nunca cae
        // MAS TARDE del 80 % exacto: ante el redondeo, se rota antes.
        return $this->issuedAt->modify('+'.(int) ($life * $this->rotationThreshold).' seconds');
    }

    public function isRotationDue(DateTimeImmutable $now): bool
    {
        return $now >= $this->rotationDueAt();
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Query;

use App\Modules\Kiosk\Domain\ValueObject\ConfirmOutcome;
use App\Modules\Kiosk\Domain\ValueObject\ProvisionedDevice;
use DateTimeImmutable;

/**
 * El desenlace de teclear un codigo en el panel
 * (`POST /api/v1/kiosk/pair/confirm`, **RF-PD-06**).
 *
 * **No lleva el token**: quien lo necesita es la tablet, que lo recoge en su
 * siguiente sondeo. Devolverselo al navegador del administrador seria ponerlo en
 * un sitio donde no hace falta y desde el que se copia.
 *
 * **Si lleva de que solicitud vino** (`requestedAppVersion`, `requestedAt`), y no
 * es adorno: un codigo de seis digitos se teclea mal con facilidad, y si hay mas
 * de una solicitud viva en la instalacion, un digito cambiado confirma OTRA
 * tablet. Ver la version de la PWA y la hora en que esa tablet pidio el codigo es
 * lo que permite darse cuenta en el acto —y desvincular— en vez de descubrirlo
 * semanas despues en el registro horario. Ninguno de los dos es dato personal
 * (regla dura 21).
 *
 * `nameTaken` distingue las dos formas de decir que no, porque al administrador
 * le cambia lo que tiene que hacer: con el codigo rechazado le pide otro codigo a
 * la tablet, y con el nombre en uso cambia el nombre. Son dos respuestas
 * distintas del contrato —`PairingCodeRejected` y un `422` de validacion colgado
 * de `name`— y esta es la unica distincion que el `confirm` hace: **las tres
 * causas del codigo siguen siendo indistinguibles entre si**.
 */
final readonly class PairingConfirmation
{
    private function __construct(
        public ConfirmOutcome $outcome,
        public ?ProvisionedDevice $device,
        /** Version de la PWA que declaro la tablet al pedir el codigo. */
        public ?string $requestedAppVersion,
        /** Cuando esa tablet pidio el codigo, en UTC. */
        public ?DateTimeImmutable $requestedAt,
        public bool $nameTaken,
    ) {}

    public static function confirmed(
        ProvisionedDevice $device,
        ?string $requestedAppVersion,
        DateTimeImmutable $requestedAt,
    ): self {
        return new self(ConfirmOutcome::Confirmed, $device, $requestedAppVersion, $requestedAt, false);
    }

    /** Codigo inexistente, caducado o ya usado: **la misma respuesta para los tres**. */
    public static function rejected(): self
    {
        return new self(ConfirmOutcome::Rejected, null, null, null, false);
    }

    /** El nombre lo tiene un quiosco **activo**. El codigo sigue siendo valido. */
    public static function nameTaken(): self
    {
        return new self(ConfirmOutcome::Rejected, null, null, null, true);
    }
}

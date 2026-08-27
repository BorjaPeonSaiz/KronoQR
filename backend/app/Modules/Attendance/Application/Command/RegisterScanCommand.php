<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use DateTimeImmutable;

/**
 * La orden de registrar un escaneo (RF-AT-01).
 *
 * DTO `readonly` con los datos ya tipados y validados en el borde: ni un array
 * asociativo, ni una cadena donde hay un enum, ni una fecha sin zona. Lo que
 * llega aqui ya paso por el `FormRequest`, asi que el caso de uso no vuelve a
 * comprobar formatos — comprueba **reglas**, que es otra cosa.
 *
 * **El comando no dice que hacer.** No trae «entrada» ni «salida»: eso lo
 * decide el agregado `WorkDay` por el estado de la jornada (RF-AT-02,
 * RF-AT-03). Lo unico que el cliente puede aportar es la **intencion**, porque
 * con la pausa modelada como dos tramos (ADR-024) un `break_start` y un
 * `clock_out` son indistinguibles para el servidor.
 *
 * **Dos marcas de tiempo y solo una viaja aqui** (regla dura 9, RF-AT-09).
 * `occurredAt` es del dispositivo y puede llegar con dias de retraso desde la
 * cola offline; `recordedAt` lo pone el caso de uso pidiendoselo al puerto
 * `Clock`, nunca el cliente. Si el cliente pudiera declarar la hora de
 * recepcion, el desfase de reloj dejaria de ser medible.
 *
 * **El dispositivo llega con sus dos identificadores.** `deviceId` es la clave
 * interna que necesita la clave foranea de `scan_events`; `deviceUuid` es el
 * identificador publico, y es el unico que puede aparecer en un log tecnico, en
 * una metrica o en `error_events` (regla dura 21, doc 02 §8.1). Los dos, y no
 * uno resuelto dos veces, porque quien autentica el token ya conoce ambos y
 * volver a consultarlos seria una ida a la base de datos por fichaje.
 */
final readonly class RegisterScanCommand
{
    /**
     * @param  string  $scanId  UUID v7 generado en el cliente: la clave de idempotencia
     *                          (regla dura 8, RF-AT-07).
     * @param  string|null  $qrPayload  `FH1.<key_id>.<token>.<sig>` (doc 02 §5.1). Opaco: se
     *                                  entrega tal cual al `CredentialResolver` y **no se
     *                                  almacena**, solo su huella.
     *                                  **Nulo en el fichaje por PIN** (RF-AT-11): ahi no hay
     *                                  tarjeta, luego no hay nada de que tomar huella, y
     *                                  `scan_events.payload_fingerprint` se queda a nulo. La
     *                                  alternativa —inventar una huella del codigo de empleado—
     *                                  habria metido en la columna dos cosas distintas con el
     *                                  mismo nombre, y quien investigara un escaneo no sabria
     *                                  cual esta mirando.
     * @param  DateTimeImmutable  $occurredAt  Momento real del escaneo, en UTC.
     * @param  array<string, scalar>  $clientMeta  Version de la app, modelo de tablet, calidad
     *                                             del escaneo. Nunca datos personales.
     */
    public function __construct(
        public string $scanId,
        public ?string $qrPayload,
        public DateTimeImmutable $occurredAt,
        public int $deviceId,
        public string $deviceUuid,
        public ScanOrigin $origin = ScanOrigin::QR_KIOSK,
        public ScanIntent $intent = ScanIntent::AUTO,
        public array $clientMeta = [],
    ) {}
}

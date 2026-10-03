<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\DiscardedScanReviewPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Un aviso de fichaje descartado con dueño** (RN-22, ADR-047): la fila de
 * `discarded_scan_reports` que la revision diaria puede convertir en incidencia.
 *
 * Es el hecho, no la conclusion: si cae dentro de la ventana de revision lo
 * decide {@see DiscardedScanReviewPolicy},
 * y la incidencia la agrupa la revision por persona y jornada.
 *
 * **Sin datos personales** (regla dura 21): el dueño por su UUID, el quiosco por
 * el suyo, el escaneo por su `scan_id`. Ni el contenido del QR ni el codigo de
 * empleado llegan nunca aqui, porque no se guardan.
 */
final readonly class DiscardedScan
{
    public function __construct(
        public string $scanId,
        /** `employee_uuid` de a quien se atribuyo. */
        public string $ownerUuid,
        /** `devices.uuid` del quiosco que lo descarto. */
        public string $deviceUuid,
        public ScanOrigin $origin,
        /** Momento real del fichaje descartado, en UTC. Lo puso la tablet. */
        public DateTimeImmutable $occurredAt,
        /** Recepcion del aviso en servidor, en UTC. */
        public DateTimeImmutable $recordedAt,
        public int $httpStatus,
        /** El `type` del problema recibido, tal cual; `null` si no traia. */
        public ?string $problemType,
        public DiscardedScanAttributionMethod $attribution,
        /** Emision de la tarjeta que atribuyo; solo por tarjeta. */
        public ?DateTimeImmutable $credentialIssuedAt,
        /** Fecha civil del alta del dueño (`YYYY-MM-DD`): la cota de un aviso por PIN. */
        public string $ownerHiredOn,
    ) {
        if ($scanId === '' || $ownerUuid === '') {
            throw new InvalidArgumentException('Un aviso con dueño necesita su scan_id y su dueño.');
        }

        if ($attribution === DiscardedScanAttributionMethod::NONE) {
            throw new InvalidArgumentException('Un aviso sin atribuir no llega a la revision.');
        }

        if (($attribution === DiscardedScanAttributionMethod::CREDENTIAL) !== ($credentialIssuedAt instanceof DateTimeImmutable)) {
            throw new InvalidArgumentException('Solo el aviso atribuido por tarjeta lleva la emision de la tarjeta.');
        }

        TimeRange::assertUtc('occurredAt', $occurredAt);
        TimeRange::assertUtc('recordedAt', $recordedAt);
    }

    /**
     * Lo que va tras `urn:kronoqr:problem:` si es un problema del catalogo del
     * producto, o `other`; vacio si no traia ninguno (F9 del dictamen del bloque
     * 18). El `context` de la incidencia solo admite cadenas de 64: aqui no
     * entra nada que no sea un identificador corto de este producto.
     */
    public function problem(): string
    {
        if ($this->problemType === null) {
            return '';
        }

        $prefix = 'urn:kronoqr:problem:';
        $slug = str_starts_with($this->problemType, $prefix) ? substr($this->problemType, \strlen($prefix)) : '';

        return \in_array($slug, self::KNOWN_PROBLEMS, true) ? $slug : 'other';
    }

    /**
     * Los problemas de este producto que un quiosco puede recibir al enviar un
     * fichaje y que no son el rechazo generico: el catalogo cerrado del
     * `context` (F9). Lo demas es `other`.
     *
     * Sin `unauthenticated`, `forbidden`, `csrf-token-mismatch` ni
     * `too-many-requests`: el quiosco no descarta por ellos (reautentica o
     * reintenta). `DiscardedScanProblemCatalogTest` ata cada entrada a un
     * `urn:kronoqr:problem:*` del contrato.
     */
    public const array KNOWN_PROBLEMS = [
        'invalid-request',
        'validation-failed',
        'bad-request',
        'unsupported-media-type',
        'payload-too-large',
        'method-not-allowed',
        'not-found',
        'conflict',
        'http-error',
    ];
}

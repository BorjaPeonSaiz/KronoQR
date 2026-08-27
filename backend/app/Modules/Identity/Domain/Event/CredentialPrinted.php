<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha **acuñado el QR** de una credencial y se ha dibujado su tarjeta
 * (RF-QR-04, ADR-034).
 *
 * **Es el momento en que una persona pasa a poder fichar**, y por eso es un hecho
 * con relevancia legal (regla dura 6). Antes de esto la credencial existia como
 * derecho administrativo y ningun escaneo la alcanzaba: no habia hash por el que
 * resolverla. A partir de aqui hay una tarjeta capaz de registrar jornada en
 * nombre de alguien, y si mañana se discute un fichaje, este asiento es el que
 * dice desde cuando existia esa tarjeta y con que clave se firmo.
 *
 * **Este evento lleva `keyId` y {@see CredentialIssued} ya no** (ADR-034). En la
 * emision todavia no hay ninguna clave elegida: la de firma es la vigente al
 * imprimir, que es lo que permite que una tarjeta emitida antes de una rotacion
 * salga con la clave nueva (doc 02 §5.3). Durante un solape, `key_id` es lo que
 * dice que tarjetas quedan por reimprimir antes de retirar la anterior.
 *
 * **Lo que NO lleva**: ni el token, ni su firma, ni su hash, ni el nombre del
 * titular (reglas duras 10 y 21). El token existe los milisegundos que se tarda
 * en dibujar el PDF y se olvida; escribirlo en un evento —que acaba en
 * `audit_log` y en cualquier listener futuro— seria devolverlo a un sitio del que
 * ADR-034 lo saco a proposito.
 *
 * `batch` distingue la impresion individual de la hoja A4 de un centro completo.
 * No cambia nada de lo que ocurre, y cambia mucho lo que se puede reconstruir
 * despues: sesenta asientos seguidos con `batch: true` son una tarde de
 * preparacion de temporada; sesenta sueltos, otra cosa.
 */
final readonly class CredentialPrinted implements DomainEvent
{
    public function __construct(
        public int $credentialId,
        public string $credentialUuid,
        public string $employeeUuid,
        /** Clave con la que se firmo la tarjeta. Nunca el material de la clave. */
        public string $keyId,
        /** Si formaba parte de una impresion por lotes (`credentials:print-batch`). */
        public bool $batch,
        /** Quien la imprimio, o `null` si fue un comando de consola sin persona detras. */
        public ?int $actorUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'identity.credential_printed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Alguien se ha llevado el cuadro de impacto y adopcion como fichero
 * (**RF-IN-08**, regla dura 6).
 *
 * ## Por que esto se audita si **leer** el cuadro no
 *
 * Porque lo que se audita no es el acceso a un dato personal —el cuadro es un
 * agregado de la instalacion entera y no lleva ni un identificador (regla dura
 * 21)— sino **que un documento del sistema ha salido del sistema**. Ese documento
 * sostiene la renovacion de la licencia: va a una reunion, se adjunta a un correo
 * y se archiva fuera del producto. De un papel asi el cliente tiene que poder
 * responder quien lo saco, cuando y de que periodo hablaba, y eso es exactamente
 * lo que este hecho registra.
 *
 * Abrir la pantalla, en cambio, no deja nada fuera. Un asiento por cada apertura
 * llenaria el trail —cuatro años de retencion (RL-02)— de filas que no describen
 * ninguna divulgacion, y con ello haria mas dificil encontrar las que si.
 *
 * ## La huella que viaja es la del CONTENIDO, no la del binario
 *
 * Es la misma que imprime el pie del PDF, la que va en `X-Kronoqr-Report-Digest` y
 * la misma para los tres formatos del mismo cuadro. Eso es lo que convierte el
 * asiento en algo comprobable: quien tiene un papel delante puede confirmar que es
 * el que salio de aqui. Una huella del fichero cambiaria entre el CSV y el PDF del
 * mismo cuadro y no serviria para compararlo con nada.
 *
 * `sizeBytes` si es del fichero, y va al lado por lo contrario: es lo que permite
 * reconocer el adjunto concreto en una conversacion sobre una brecha.
 *
 * ## Ni un dato personal
 *
 * Periodo, formato, huella y tamaño. Ni un nombre, ni un `employee_uuid`, ni una
 * hora trabajada (regla dura 21) — y aqui no cuesta nada, porque el documento
 * tampoco los lleva.
 *
 * ## Se publica DENTRO del camino de la descarga y antes de entregarla
 *
 * Como los del informe en diferido y por lo mismo: el unico suscriptor es el
 * asiento, que es sincrono y **tiene que poder impedir el hecho si falla** (regla
 * dura 6, ADR-027). Un documento que sale sin traza es peor que una descarga que
 * no llega a ocurrir.
 */
final readonly class AdoptionReportExported implements DomainEvent
{
    public function __construct(
        /** Primera jornada del periodo, `AAAA-MM-DD`, en la zona del centro. */
        public string $from,
        public string $to,
        /** `csv`, `xlsx` o `pdf`. */
        public string $format,
        /** Huella SHA-256 del **contenido** del cuadro. Ver el docblock. */
        public string $sha256,
        /** Tamaño del fichero entregado, en bytes. */
        public int $sizeBytes,
        /** Cuenta de gestion que pidio la descarga: es de quien se responde. */
        public int $exportedByUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'reporting.adoption_report_exported';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

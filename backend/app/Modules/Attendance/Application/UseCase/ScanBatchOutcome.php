<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

/**
 * Lo que le paso a **un** escaneo del lote.
 *
 * Tres desenlaces y no dos, y el tercero es el que importa:
 *
 * | Desenlace | Que paso | Que hace el quiosco con su cola |
 * |---|---|---|
 * | Procesado | Se decidio: tramo, anti-rebote o rechazo | Lo saca de la cola |
 * | Rechazado | La credencial no resolvio (`RegisterScanResult::isRejected()`) | Lo saca de la cola |
 * | **No procesado** | El servidor **no llego a decidir nada** | **Lo conserva y reintenta** |
 *
 * ## Por que existe «no procesado»
 *
 * Sin el, un fallo transitorio en el elemento tres de un lote de cincuenta
 * obligaria a elegir entre dos cosas malas: abortar el envio entero —y dejar sin
 * registrar los cuarenta y siete que si se podian— o devolver un rechazo, que el
 * quiosco entiende como «esta tarjeta no vale» y saca de la cola para siempre.
 * Las dos pierden jornadas, y la regla dura 19 no lo permite: el empleado no
 * tiene la culpa de que la base de datos parpadeara mientras su fichaje viajaba.
 *
 * ## Y el cuarto: aplazado (RN-21, ADR-047)
 *
 * Tras el primer elemento **no procesado**, los posteriores del mismo lote **no
 * se procesan**: se devuelven aplazados, en su orden. Desde RF-AT-12 la
 * atribucion de cada escaneo depende del ultimo aceptado de la persona, asi que
 * procesar la salida de las 15:00 con la entrada de las 07:00 todavia sin
 * decidir abriria un turno en vez de cerrarlo, y la entrada, al reenviarse, ya
 * no cabria (RN-18). El servidor no sabe de quien era el elemento que fallo —el
 * fallo pudo saltar resolviendo su credencial—, asi que se aplaza **todo** lo
 * que viene detras. Para el quiosco es lo mismo que «no procesado»: conservar y
 * reintentar.
 */
final readonly class ScanBatchOutcome
{
    private function __construct(
        public string $scanId,
        /** Nulo exactamente cuando el escaneo no se pudo procesar o se aplazo. */
        public ?RegisterScanResult $result,
        /** El escaneo no se miro porque uno anterior del lote quedo sin procesar (RN-21). */
        public bool $heldBack = false,
    ) {}

    public static function processed(RegisterScanResult $result): self
    {
        return new self($result->scanId, $result);
    }

    /**
     * El servidor no llego a decidir nada sobre este escaneo. **No es un
     * rechazo**: sigue pendiente y hay que reintentarlo.
     */
    public static function notProcessed(string $scanId): self
    {
        return new self($scanId, null);
    }

    /**
     * El servidor **no ha mirado** este escaneo, a proposito: uno anterior del
     * mismo lote quedo sin procesar y este no puede adelantarlo (RN-21).
     */
    public static function heldBack(string $scanId): self
    {
        return new self($scanId, null, heldBack: true);
    }

    public function wasProcessed(): bool
    {
        return $this->result instanceof RegisterScanResult;
    }

    public function wasHeldBack(): bool
    {
        return $this->heldBack;
    }
}

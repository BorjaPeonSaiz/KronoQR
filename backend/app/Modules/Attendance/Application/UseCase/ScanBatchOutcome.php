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
 * Es tambien lo que impide que un elemento envenenado bloquee la cola: el resto
 * del lote avanza y solo el problematico vuelve a intentarse.
 */
final readonly class ScanBatchOutcome
{
    private function __construct(
        public string $scanId,
        /** Nulo exactamente cuando el escaneo no se pudo procesar. */
        public ?RegisterScanResult $result,
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

    public function wasProcessed(): bool
    {
        return $this->result instanceof RegisterScanResult;
    }
}

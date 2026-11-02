<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;
use LogicException;

/**
 * Cifra el paquete con datos personales para la clave publica de soporte del
 * fabricante (**RL-19**, RF-PD-09; ADR-020, enmienda del 01-11-2026).
 *
 * Solo cifra: la instalacion no tiene la clave privada y nada en el producto
 * abre un sobre. Lo abre el fabricante con `tools/diagnostics-opener/`.
 *
 * ## `available()` antes de recoger nada
 *
 * Sin una clave publica valida el paquete con datos personales no se genera:
 * ni se recogen los datos ni queda asiento, y nunca sale en claro. Quien lo
 * pide lo pregunta antes de recolectar, para que un paquete que no se va a
 * poder cifrar no llegue a existir ni en memoria.
 */
interface DiagnosticsBundleSealer
{
    /** Si hay una clave publica de soporte valida con la que cifrar. */
    public function available(): bool;

    /**
     * Los 16 hexadecimales del SHA-256 de la clave publica, o nulo si no hay
     * una valida. Lo enseñan `product:doctor` y el sobre.
     */
    public function recipientKeyId(): ?string;

    /**
     * @param  int  $periodDays  Los dias que se pidieron: la cabecera los dice
     *                           aunque la seccion no quepa.
     *
     * @throws LogicException Si se llama sin clave valida, o con un paquete anonimizado.
     */
    public function seal(DiagnosticsBundle $bundle, int $periodDays): SealedDiagnosticsBundle;
}

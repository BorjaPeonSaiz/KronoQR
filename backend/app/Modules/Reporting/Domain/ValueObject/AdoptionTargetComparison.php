<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Como se lee el objetivo del §1.3 de un indicador (**RF-IN-08**).
 *
 * ## `reduction` no es un objetivo que el producto pueda juzgar
 *
 * Los dos primeros son comparaciones: el indicador cumple o no cumple, y el
 * cuadro lo dice. El tercero describe **una mejora contra un punto de partida que
 * el sistema no observa**: las horas al mes que alguien dedicaba a consolidar
 * hojas de horas antes de que KronoQR existiera (§1.3, «−80 %»). Ninguna metrica
 * de una aplicacion puede medir el trabajo anterior a su instalacion, asi que la
 * linea base la **declara** el cliente y el cuadro la presenta con el objetivo al
 * lado, como referencia. {@see AdoptionTargetComparison::judgesCompliance()} es lo
 * que impide que una pantalla lo pinte en verde o en rojo por su cuenta.
 */
enum AdoptionTargetComparison: string
{
    /** Cumple si el valor es mayor o igual que el objetivo: jornadas completas, QR, disponibilidad. */
    case AtLeast = 'at_least';

    /**
     * Cumple si el valor es **estrictamente menor**: correcciones, tiempo hasta
     * resolver.
     *
     * Estricto y no «menor o igual» porque el §1.3 lo escribe asi —«< 2 %», «< 24
     * h»— y el requisito manda sobre la implementacion. La diferencia solo aparece
     * en el valor clavado, y ahi es donde importa: veinticuatro horas exactas para
     * resolver un turno sin cerrar **no** es cumplir el objetivo de detectarlo
     * pronto.
     */
    case AtMost = 'at_most';

    /**
     * El objetivo es un porcentaje de **reduccion** sobre el valor, que aqui es
     * una linea base declarada y no una medida.
     */
    case Reduction = 'reduction';

    /**
     * ¿Se puede decidir con este objetivo si el indicador va bien o mal?
     *
     * Vive en el dominio y no en la pantalla para que las tres —panel, CSV y
     * PDF— no puedan responder cosas distintas. Con `reduction` es `false`: el
     * cuadro no sabe cuantas horas cuesta hoy consolidar las hojas de horas,
     * porque **ya no se consolidan a mano**, que es justamente el punto.
     */
    public function judgesCompliance(): bool
    {
        return $this !== self::Reduction;
    }
}

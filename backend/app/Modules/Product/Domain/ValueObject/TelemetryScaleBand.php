<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * El tamaño de la plantilla **por tramos**, que es lo unico que sale de la
 * instalacion (**RF-PD-12**, ficha 5.10 punto 8, ADR-020).
 *
 * ## Por que un tramo y no la cifra
 *
 * Porque la cifra exacta es un dato del cliente y el tramo responde igual de
 * bien a la unica pregunta que la telemetria tiene que responder: *¿el producto
 * se esta usando en hoteles de veinte personas o de cuatrocientas?* «317
 * empleados activos» no mejora esa respuesta y si permite reconocer a un cliente
 * concreto entre los pocos que tienen ese tamaño — que es exactamente lo que
 * ADR-020 promete que no ocurre.
 *
 * Los cortes son los del catalogo comercial (planes por tamaño), no una escala
 * inventada: asi el tramo significa lo mismo en telemetria que en una revision
 * de plan.
 *
 * `0` es un tramo propio y no se pliega a `1-25`: «recien instalado y sin
 * plantilla» y «hotel pequeño» son dos situaciones distintas, y distinguirlas es
 * la mitad de lo que sirve para saber si una instalacion llego a arrancar.
 */
enum TelemetryScaleBand: string
{
    case None = '0';
    case UpTo25 = '1-25';
    case UpTo100 = '26-100';
    case UpTo250 = '101-250';
    case UpTo500 = '251-500';
    case Above500 = '501+';

    /**
     * Una cifra negativa —que no deberia existir— cae en `0` y no revienta: la
     * telemetria nunca puede ser la causa de un fallo.
     */
    public static function of(int $count): self
    {
        return match (true) {
            $count <= 0 => self::None,
            $count <= 25 => self::UpTo25,
            $count <= 100 => self::UpTo100,
            $count <= 250 => self::UpTo250,
            $count <= 500 => self::UpTo500,
            default => self::Above500,
        };
    }
}

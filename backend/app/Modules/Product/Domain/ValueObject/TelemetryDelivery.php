<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * El resultado de intentar entregar el documento (**RF-PD-12**, ficha 5.10
 * puntos 8 y 9).
 *
 * ## Un resultado y no una excepcion
 *
 * Porque **un fallo de red no es una averia del producto**. La telemetria es
 * accesoria (ADR-023) y el escenario normal de este producto es una instalacion
 * sin salida a internet (doc 02 §11.6.2): si el envio lanzara, alguien acabaria
 * capturandolo en el sitio equivocado o el planificador dejaria un `error` en el
 * log todas las semanas, que es justo el «recordatorio insistente» que RF-PD-12
 * prohibe.
 *
 * `failure` lleva la **clase** de la excepcion o `http_<codigo>`, nunca el
 * mensaje: un mensaje de red lleva el host y a veces la URL entera.
 */
final readonly class TelemetryDelivery
{
    private function __construct(
        public bool $delivered,
        public ?int $statusCode,
        public ?string $failure,
        public int $attempts,
    ) {}

    public static function delivered(int $statusCode, int $attempts): self
    {
        return new self(true, $statusCode, null, $attempts);
    }

    public static function failed(string $failure, int $attempts, ?int $statusCode = null): self
    {
        return new self(false, $statusCode, $failure, $attempts);
    }
}

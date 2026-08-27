<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Una dependencia que no responde, con el detalle que **solo ve el servidor**.
 *
 * El reparto es deliberado: `component` y `detail` van al log de la instalacion
 * y ninguno de los dos sale en la respuesta de `GET /api/v1/ready`, que es
 * publica y no autenticada. Una sonda que enumera los servicios caidos es un
 * mapa gratuito de la instalacion; el diagnostico por componente es del
 * administrador (RF-PD-09, RF-PD-13, Fase 5).
 *
 * `detail` es el mensaje de la excepcion de conexion —host, puerto, driver— y
 * nunca lleva datos de una persona: aqui no hay ningun empleado (regla dura 21).
 */
final readonly class DependencyFailure
{
    public function __construct(
        public string $component,
        public string $detail,
    ) {}
}

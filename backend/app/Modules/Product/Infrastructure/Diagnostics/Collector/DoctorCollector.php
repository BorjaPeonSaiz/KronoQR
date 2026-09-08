<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;

/**
 * Seccion `doctor`: el informe completo de `product:doctor` dentro del paquete
 * (contrato `DoctorReport`, RF-PD-13).
 *
 * ## Va entero, y va el mismo
 *
 * No un resumen ni «las que fallan»: el informe completo, tal cual lo devuelve
 * `--json`. Lo que un cliente considera irrelevante suele ser lo que explica la
 * incidencia, y un resumen obliga a la segunda ronda de preguntas que ADR-020
 * existe para evitar.
 *
 * ## Siempre en el idioma del producto por defecto, no en el del cliente
 *
 * `es`. El paquete lo lee soporte, no el cliente: que llegara en el idioma que
 * tuviera puesta la instalacion convertiria el idioma del hotel en una variable
 * del proceso de soporte. Quien quiera leerlo en su idioma ejecuta
 * `product:doctor --lang=` en su terminal, que es donde importa.
 */
final readonly class DoctorCollector implements DiagnosticsCollector
{
    /** Idioma del informe que viaja al fabricante. */
    public const string SUPPORT_LOCALE = 'es';

    public function __construct(private RunDoctorHandler $doctor) {}

    public function section(): string
    {
        return 'doctor';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        return $this->doctor->handle(self::SUPPORT_LOCALE)->toArray();
    }
}

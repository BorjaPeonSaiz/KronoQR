<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Quien genero el paquete, **sin identificarlo** (contrato
 * `DiagnosticsManifest.generated_by`).
 *
 * Tres valores y ningun nombre, ningun UUID y ningun identificador de fila. El
 * paquete sale de la instalacion hacia el fabricante y el manifiesto es lo
 * primero que se lee: que ahi apareciera «lo genero Marta Lopez» seria un dato
 * personal viajando por el canal que ADR-020 existe para vaciar. El actor
 * concreto queda en `audit_log`, que es del cliente y no sale.
 *
 * La distincion si importa para diagnosticar: `console` significa que alguien
 * entro por SSH —seguramente porque el panel no responde— y `support_grant`
 * significa que el paquete lo genero el propio fabricante con una concesion
 * temporal, que es un contexto muy distinto del de un cliente pidiendo ayuda.
 */
enum DiagnosticsActor: string
{
    case User = 'user';
    case SupportGrant = 'support_grant';
    case Console = 'console';
}

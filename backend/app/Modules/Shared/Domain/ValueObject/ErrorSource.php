<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * De donde viene un error del historico (`error_events.source`, RF-PD-15,
 * doc 01 §5, tarea 5.12).
 *
 * **En Shared y no en Product por la misma razon que `UserRole`**: cruza la
 * frontera entre modulos. La tabla y la escritura son de `Product`, pero quien
 * reporta desde el latido es `Kiosk`, y Deptrac le prohibe importar `Product`
 * (doc 02 §1.6). El catalogo vive en el unico sitio que ambos alcanzan; el
 * contrato (`ErrorSource` en `openapi.yaml`) y `ClientDocumentationTest` lo
 * atan a estos siete valores.
 *
 * **Los cuatro del servidor los decide el contexto de ejecucion** (peticion,
 * trabajo de cola, planificador, consola). **Los tres de cliente los decide el
 * TIPO DE TOKEN** con el que llego el reporte —dispositivo, sesion de gestion,
 * sesion de portal—, nunca un campo del cuerpo: un cliente no elige como se
 * le clasifica (ficha 5.12, decision 7).
 */
enum ErrorSource: string
{
    /** Una peticion HTTP de la API. */
    case Api = 'api';

    /** Un trabajo de cola (Horizon). */
    case Worker = 'worker';

    /** Una tarea del planificador. */
    case Scheduler = 'scheduler';

    /** Un comando de consola lanzado a mano. */
    case Console = 'console';

    /** La PWA de la tablet, reportado dentro del latido. */
    case Kiosk = 'kiosk';

    /** El panel de gestion, reportado con una sesion de gestion. */
    case Admin = 'admin';

    /** El portal del empleado, reportado con una sesion de portal. */
    case Portal = 'portal';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }

    public function isClient(): bool
    {
        return match ($this) {
            self::Kiosk, self::Admin, self::Portal => true,
            self::Api, self::Worker, self::Scheduler, self::Console => false,
        };
    }
}

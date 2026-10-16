<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

use App\Modules\Shared\Application\Port\UseCaseMetrics;
use Closure;
use Throwable;

/**
 * Donde {@see UseCaseInstrumentation} encuentra el adaptador de
 * {@see UseCaseMetrics}.
 *
 * ## Por que un registro estatico y no una dependencia inyectada
 *
 * Los casos de uso son clases `final` sin interfaz y se construyen a mano en
 * cientos de pruebas (`new RegisterEmployeeHandler(...)`). Inyectar el puerto
 * habria cambiado todos esos constructores para añadir algo que no forma parte
 * de la logica del caso de uso, y un decorador no cabe sin bus ni interfaz.
 *
 * `SharedServiceProvider` lo rellena con un resolutor **perezoso**: no se
 * construye nada hasta la primera medicion, y cada aplicacion que arranca —una
 * por prueba de *feature*— sustituye al de la anterior. **Vacio, no hace
 * nada**: es el estado de las pruebas unitarias, que no arrancan Laravel, y
 * medir no puede ser requisito para ejecutar un caso de uso.
 */
final class UseCaseMetricsRegistry
{
    /** @var (Closure(): UseCaseMetrics)|null */
    private static ?Closure $resolver = null;

    private function __construct() {}

    /**
     * @param  Closure(): UseCaseMetrics  $resolver
     */
    public static function resolveWith(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function forget(): void
    {
        self::$resolver = null;
    }

    /**
     * El adaptador en curso, o `null` si no hay ninguno o resolverlo falla:
     * quien llama lo trata igual en los dos casos, no mide.
     */
    public static function current(): ?UseCaseMetrics
    {
        if (! self::$resolver instanceof Closure) {
            return null;
        }

        try {
            return (self::$resolver)();
        } catch (Throwable) {
            return null;
        }
    }
}

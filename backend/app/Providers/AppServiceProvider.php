<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Environment\ProductionSafetyGuard;
use App\Support\Queue\AfterCommitFailoverConnector;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\QueueFailedOver;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Configuracion transversal de la aplicacion que no pertenece a ningun modulo.
 *
 * Lo que sea de un modulo va en su {Modulo}ServiceProvider, no aqui: este
 * fichero es el sitio donde se acumula lo que nadie quiso ubicar, y por eso se
 * mantiene lo mas vacio posible.
 *
 * Viven aqui la guarda de arranque de produccion, que no es de ningun modulo
 * porque no es del producto sino del DESPLIEGUE, y la degradacion de la cache y
 * de la cola cuando Redis no responde (CH1), que es de su configuracion y no de
 * quien las usa.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El driver de la conexion `resilient` de config/queue.php. Se registra
        // al resolver el gestor de colas, que el framework carga en diferido.
        $this->app->afterResolving(QueueManager::class, static function (QueueManager $queues, $app): void {
            $queues->addConnector(
                'failover-after-commit',
                static fn (): AfterCommitFailoverConnector => new AfterCommitFailoverConnector(
                    $queues,
                    $app->make(Dispatcher::class),
                ),
            );
        });
    }

    public function boot(): void
    {
        // Lo primero, y antes de que exista ninguna peticion. Ver el porque en
        // el docblock de ProductionSafetyGuard: con las trazas encendidas en
        // produccion, un error cualquiera publica las claves de la instalacion.
        ProductionSafetyGuard::assert(
            (string) $this->app->environment(),
            (bool) config('app.debug'),
        );

        $this->reportFailovers();
    }

    /**
     * La cache o la cola han dejado de usar Redis y siguen por su respaldo (CH1).
     *
     * El framework emite cada evento **una vez por transicion** —la primera
     * operacion que falla, no cada una—, asi que estas lineas no inundan el log
     * mientras dura la averia. Llevan el almacen o la conexion y la clase de la
     * excepcion, nunca su mensaje, que trae host y puerto: la misma disciplina
     * que la sonda de `/ready` (regla dura 21). Sin metrica, porque las metricas
     * viven en el mismo Redis que acaba de caer; quien alerta es la sonda.
     */
    private function reportFailovers(): void
    {
        Event::listen(static function (CacheFailedOver $event): void {
            Log::warning('cache.failed_over', [
                'store' => $event->storeName,
                'failure' => $event->exception::class,
            ]);
        });

        Event::listen(static function (QueueFailedOver $event): void {
            Log::warning('queue.failed_over', [
                'connection' => $event->connectionName,
                'job' => \is_object($event->command) ? $event->command::class : 'string',
                'failure' => $event->exception::class,
            ]);
        });
    }
}

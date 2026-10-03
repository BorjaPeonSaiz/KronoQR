<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Logging;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;

/**
 * El gestor de logs de Laravel con **el canal `emergency` saneado** (ADR-048,
 * regla dura 21, RF-PD-15).
 *
 * ## El hueco que cierra
 *
 * Cuando el canal configurado no se puede construir —un `LOG_CHANNEL` mal
 * escrito, un fichero sin permisos, un manejador que lanza al crearse—,
 * Laravel escribe por el logger de emergencia. Ese logger lo monta
 * `LogManager::createEmergencyLogger()` **a mano**, con un `StreamHandler`
 * suelto: **no lee ni `tap` ni `processors`**, asi que declarar
 * {@see RedactPersonalData} en `config/logging.php` junto a `emergency` no
 * tendria ningun efecto. Y es justo el canal que escribe cuando algo ya ha ido
 * mal, que es cuando las excepciones llevan datos dentro.
 *
 * Esta clase sobrescribe ese unico metodo para anadir el mismo
 * {@see RedactPersonalDataProcessor} que llevan los demas canales. Todo lo demas
 * es el gestor de Laravel sin cambios.
 *
 * ## Por que vive aqui y no en `App\Support`
 *
 * El arranque de la aplicacion (`App\Support`, capa `AppFramework` de Deptrac)
 * no puede alcanzar ningun modulo, y el processor es de `Product`. La registra
 * `ProductServiceProvider` como el singleton `log`, sustituyendo al de
 * `LogServiceProvider`; una prueba comprueba que `app('log')` y la fachada
 * resuelven esta clase tras arrancar (H8).
 *
 * ## Por que no es `final`
 *
 * `Log::spy()` y `Log::shouldReceive()` construyen un doble de Mockery a partir
 * de la clase de la raiz de la fachada, y Mockery no puede doblar una clase
 * `final`. Las pruebas que espian el log de un caso de uso dejarian de
 * funcionar por el mero hecho de que el gestor de logs sanee mejor.
 */
class RedactingLogManager extends LogManager
{
    /**
     * @return LoggerInterface
     */
    protected function createEmergencyLogger()
    {
        $logger = parent::createEmergencyLogger();

        $monolog = $logger instanceof Logger ? $logger->getLogger() : null;

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactPersonalDataProcessor);
        }

        return $logger;
    }
}

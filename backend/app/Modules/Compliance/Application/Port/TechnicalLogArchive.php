<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * Los ficheros de log de la aplicacion (RL-11, 90 dias).
 *
 * Interfaz propia y no un `ShortCycleArchive` a secas porque el contenedor tiene
 * que poder inyectar **este** y no el otro: son dos almacenes con la misma forma
 * y distinto destino, y una lista etiquetada resolveria lo mismo escondiendo
 * cual es cual.
 *
 * La rotacion diaria de Monolog (`LOG_DAILY_DAYS`) es otra cosa y no la
 * sustituye: aquella limita cuantos ficheros hay, esta cumple el plazo de RL-11
 * sobre lo que quede —incluidos los de otros canales y los que alguien copio al
 * directorio a mano—.
 */
interface TechnicalLogArchive extends ShortCycleArchive {}

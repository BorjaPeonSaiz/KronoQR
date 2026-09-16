<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Los dos plazos con los que `kiosk:health` juzga un latido (RF-PA-07).
 *
 * ## De donde salen los dos numeros, y por que no hay un tercero
 *
 * - **`freshWithinSeconds` = 120 s.** Es lo que la documentacion entregada al
 *   cliente ya promete: el runbook `alta-nuevo-quiosco.md` §4.2 dice que hay que
 *   comprobar que «su ultimo contacto es de hace menos de dos minutos» y el §4.3
 *   que «si "ultimo contacto" pasa de dos o tres minutos, la tablet no esta
 *   hablando con el servidor». Con el latido cada 60 s (doc 02 §6), dos minutos
 *   son **dos latidos perdidos**: uno suelto puede ser un wifi que parpadea.
 *
 * - **`silentAfterSeconds` = 600 s.** Es el umbral de la alerta «Quiosco sin
 *   latido > 10 min, Critica (operaciones)» del doc 01 §9.3, con su runbook
 *   `quiosco-no-responde.md`. **No es un numero nuevo**: es el mismo, y tiene que
 *   serlo. Un comando que dijera «aviso» de un quiosco por el que la
 *   observabilidad esta paginando a las 06:00 seria peor que no tener comando.
 *
 * No hay un tercer plazo por lo mismo: cada umbral extra es otra opinion sobre
 * cuando un quiosco esta caido, y quien atiende la incidencia solo puede
 * sostener una.
 *
 * ## Y un umbral que NO es un plazo: `batteryLowPercent`
 *
 * 15 % de serie (`KIOSK_HEALTH_BATTERY_LOW_PERCENT`, tarea 3.3). No es un
 * tercer plazo de latido —no mide tiempo— sino el punto por debajo del cual una
 * tablet **que no esta cargando** se avisa antes de que se apague sola en mitad
 * de un turno. Se administra igual que los otros dos y por el mismo motivo: un
 * hotel cuyas tablets estan siempre enchufadas y otro que las rota a mano no
 * tienen el mismo margen razonable.
 *
 * ## Configuracion, no constantes (regla dura 13, ADR-017)
 *
 * Los valores llegan resueltos desde `config/kiosk.php` —claves
 * `KIOSK_HEALTH_FRESH_WITHIN_SECONDS` y `KIOSK_HEALTH_SILENT_AFTER_SECONDS`—
 * porque un hotel con la wifi justa y otro con red cableada no tienen la misma
 * paciencia razonable, y cambiarla no puede exigir tocar el repositorio.
 */
final readonly class KioskHealthThresholds
{
    public function __construct(
        /** Hasta aqui, un quiosco esta al dia. */
        public int $freshWithinSeconds,
        /** A partir de aqui, esta callado: es la alerta critica del doc 01 §9.3. */
        public int $silentAfterSeconds,
        /**
         * Por debajo de este nivel, y **sin cargar**, el quiosco avisa.
         *
         * El limite es INCLUSIVO —«al 15 % y descargandose» ya avisa— porque el
         * numero que el cliente escribe en su `.env` es el que quiere ver
         * avisado, no el primero que no lo esta.
         */
        public int $batteryLowPercent = 15,
    ) {
        if ($batteryLowPercent < 0 || $batteryLowPercent > 100) {
            // Un porcentaje fuera de 0..100 no avisaria nunca o avisaria
            // siempre, y las dos cosas acaban con alguien ignorando la columna.
            // La raiz de composicion lo acota antes de llegar aqui; esto atrapa
            // al que construya el objeto por otro camino.
            throw new InvalidArgumentException('El umbral de bateria baja va entre 0 y 100.');
        }

        if ($freshWithinSeconds < 1) {
            throw new InvalidArgumentException('El plazo de latido fresco es de al menos un segundo.');
        }

        if ($silentAfterSeconds <= $freshWithinSeconds) {
            // Si los dos plazos se cruzaran no habria zona de aviso: un quiosco
            // pasaria de correcto a fallo sin que nadie hubiera podido mirar la
            // red antes. La raiz de composicion los ordena antes de llegar aqui;
            // esto atrapa al que los construya por otro camino.
            throw new InvalidArgumentException('El plazo de silencio va despues del de latido fresco.');
        }
    }
}

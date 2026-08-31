<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\ValueObject\OperationalSettings;

/**
 * Los umbrales **operativos** del centro, resueltos desde
 * `installation_settings` (RF-PD-01, regla dura 14, ADR-025).
 *
 * **Por que existia ya antes de la tarea 5.1.** Porque sin el, el primer fichaje
 * no tenia con que evaluar RN-08 ni RF-AT-06, y la alternativa era escribir 12 h
 * y 60 s como constantes en PHP — que es exactamente lo que la regla dura 14 y
 * ADR-017 prohiben. Lo que la 5.1 le cambia por dentro es de donde salen los
 * valores; la forma que ve el nucleo —`forSite(int $siteId)`— no cambia.
 *
 * ## Esto corre en el camino de fichaje, y eso manda sobre todo lo demas
 *
 * `RegisterScanHandler` llama aqui en **cada** escaneo. Por eso la resolucion es
 * tolerante: una fila corrupta —un color de marca editado a mano— se descarta y
 * rige su valor de serie, en lugar de lanzar. Si lanzara, `POST /api/v1/scan`
 * responderia un error y nadie podria fichar por una clave que este adaptador ni
 * siquiera consume (regla dura 19). El descarte no es silencioso: se anuncia por
 * el puerto de anomalias y viaja en `meta.invalid_keys` de
 * `GET /api/v1/settings`.
 *
 * **Y solo se toman las cuatro claves que se consumen.** Del conjunto resuelto
 * salen `ATTENDANCE_*` y nada mas: la marca y los idiomas no entran aqui ni
 * pueden influir en lo que este adaptador devuelve.
 *
 * ## La cascada, ahora con dos escalones
 *
 *     fila de installation_settings  ->  valor por defecto del catalogo
 *
 * La resuelve {@see ResolvedSettings}, que es dominio puro, a traves de
 * {@see GetSettingsHandler}, que es el unico punto de lectura del modulo.
 *
 * **El ambito `site` desaparecio con la tarea 5.1** (ADR-040, migracion de
 * contraccion `2026_09_05_100000`): hay exactamente un centro por instalacion, y
 * un escalon que siempre resuelve al mismo sitio no es una cascada. `$siteId` se
 * conserva en la firma del puerto porque el nucleo lo pasa —`shift_entries` sigue
 * teniendo `site_id`— y quitarlo seria tocar `Attendance` y `Kiosk` por nada. La
 * memoria por peticion sigue indexada por el, para que el dia que el producto
 * volviera a tener varios centros esto no haya que rehacerlo.
 *
 * ## Sin fila, valor de serie: **no falla**
 *
 * Antes lanzaba `RuntimeException` cuando faltaba una clave. Con la cascada eso
 * seria justo lo contrario del resultado esperado de la tarea 5.1: *«una
 * instalacion sin ninguna fila en `installation_settings` arranca y funciona»*.
 * El valor de serie **es** el producto y vive en el catalogo (`SettingKey`), en
 * codigo, donde una prueba unitaria comprueba que cumple su propia definicion; no
 * es un valor de respaldo escondido, porque `GET /api/v1/settings` publica su
 * procedencia (`source: product_default`).
 *
 * ## Las horas y los minutos
 *
 * `ATTENDANCE_MAX_SHIFT_HOURS` se enuncia en horas porque asi lo dice el negocio
 * —«12 h»— y se convierte aqui, en el borde. El dominio razona en minutos porque
 * esa es la unidad del calculo (`duration_minutes`, `total_minutes`), y hacer la
 * conversion en un solo sitio evita que cada consumidor decida por su cuenta si
 * multiplicar.
 */
final class DbOperationalSettingsProvider implements OperationalSettingsProvider
{
    /**
     * Memoria por peticion.
     *
     * El caso de uso del fichaje pide la configuracion en **cada** escaneo. La
     * cache de Redis evita la consulta; esto evita ademas resolver la cascada
     * nueve veces por peticion. No es una cache con invalidacion: el enlace es
     * `scoped()` y muere con la peticion, asi que un cambio en el panel tiene
     * efecto en la siguiente.
     *
     * @var array<int, OperationalSettings>
     */
    private array $resolved = [];

    public function __construct(private readonly GetSettingsHandler $settings) {}

    public function forSite(int $siteId): OperationalSettings
    {
        if (isset($this->resolved[$siteId])) {
            return $this->resolved[$siteId];
        }

        $settings = $this->settings->handle();

        return $this->resolved[$siteId] = new OperationalSettings(
            anomalousShiftMinutes: $settings->integer(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS) * 60,
            debounceSeconds: $settings->integer(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS),
            maximumClockSkewMinutes: $settings->integer(SettingKey::ATTENDANCE_MAX_CLOCK_SKEW_MINUTES),
            minimumTransitSeconds: $settings->integer(SettingKey::ATTENDANCE_MIN_TRANSIT_SECONDS),
        );
    }
}

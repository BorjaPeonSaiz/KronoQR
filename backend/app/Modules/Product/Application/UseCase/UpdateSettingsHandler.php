<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Command\UpdateSettingsCommand;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SettingsMetrics;
use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Domain\Event\InstallationSettingChanged;
use App\Modules\Product\Domain\Exception\InvalidSettingValue;
use App\Modules\Product\Domain\Exception\UnknownSettingKey;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Cambia la configuracion de la instalacion y deja traza de cada clave
 * (**RF-PD-01**, RL-04, regla dura 6).
 *
 * ## El orden de los pasos significa algo
 *
 * 1. **Se valida clave a clave** (`SettingValue::of`), fuera de la transaccion
 *    porque es puro y porque un cuerpo mal formado no tiene por que tomar ningun
 *    candado. Aqui salen «esa clave no existe» y «ese valor no cabe», las dos con
 *    nombre y apellidos para que el `422` señale el campo.
 * 2. **Se abre la transaccion y se toma el candado.** Ver abajo.
 * 3. **Se lee lo que hay, sin cache.** Sin el estado actual no se puede saber
 *    que cambia de verdad ni cual era el valor anterior, que es la mitad del
 *    asiento.
 * 4. **Se valida el CONJUNTO** (`ResolvedSettings::with`). Hay invariantes que
 *    ninguna clave puede comprobar sola —el idioma por defecto tiene que estar
 *    entre los disponibles—, y comprobarlas sobre el resultado es lo que impide
 *    que un `PATCH` de dos claves deje la instalacion en un estado imposible
 *    segun el orden en que se escriban. **Antes de tocar ninguna fila.**
 * 5. **Se descarta lo que no cambia.** Abrir la pantalla y pulsar «guardar» no
 *    escribe ni una fila ni un asiento. Sin esto, el trail se llenaria de
 *    entradas que dicen «alguien miro la configuracion», y la señal que de
 *    verdad importa —«alguien cambio el anti-rebote»— quedaria enterrada.
 * 6. **Se escribe y se audita, en la misma transaccion.** Las filas y sus
 *    asientos no pueden separarse: un umbral de calculo cambiado sin traza es un
 *    cambio que nadie puede explicar despues (ADR-027).
 *
 * ## El candado, y por que un `PATCH` sin el es incorrecto
 *
 * Leer el estado fuera de la transaccion abre una ventana entre la lectura y la
 * escritura. Dos `PATCH` simultaneos la aprovechan asi: A pone
 * `LOCALE_AVAILABLE = ["en"]` con `LOCALE_DEFAULT = "en"`, B —que leyo antes de
 * que A confirmara— pone `LOCALE_DEFAULT = "es"` y comprueba su invariante
 * contra `["es","en"]`, que ya no es lo que hay. Las dos escrituras son validas
 * por separado y el resultado es un estado que el producto declara imposible.
 *
 * `pg_advisory_xact_lock` lo cierra: serializa a los escritores de configuracion
 * —**solo** a ellos, no a los lectores— y se suelta solo al confirmar o revertir,
 * asi que no hay forma de olvidarse de liberarlo. No es un `SELECT … FOR UPDATE`
 * porque lo que hay que proteger no son unas filas sino **el conjunto**: la
 * invariante habla de dos claves que pueden no existir todavia, y un candado de
 * fila no protege lo que aun no esta escrito.
 *
 * ## La lectura del paso 3 se salta la cache
 *
 * `storedValuesForWrite()`. Entre el `COMMIT` de un escritor y la invalidacion de
 * la cache hay una ventana; leer ahi algo de antes produciria un asiento que
 * declara un valor anterior que nunca rigio. Cuesta una consulta por `PATCH`,
 * que ocurre una vez al año.
 *
 * ## Un asiento por clave
 *
 * El evento se publica **dentro** de la transaccion y el listener de
 * `Compliance` es sincrono: si el asiento falla, el cambio no se guarda. Cada
 * clave produce el suyo, con su valor anterior, su valor nuevo y si **afecta al
 * calculo de horas** (doc 01 §5, nota de `installation_settings`).
 *
 * ## Se relee despues de confirmar
 *
 * La respuesta no se compone a mano con lo que se acaba de enviar: se vuelve a
 * resolver desde la base de datos, ya con la cache invalidada. Asi lo que el
 * panel pinta es exactamente lo que quedo escrito, y no una reconstruccion que
 * podria diferir el dia que alguien añada una clave derivada.
 *
 * ## Sin facades y sin reloj propio
 *
 * `Application` no usa facades (doc 02 §3.5) y el instante entra por el puerto
 * `Clock` (regla dura 2): sin eso, «cuando se cambio este umbral» no se podria
 * probar de forma determinista, y esa fecha es la que se contrasta con la nomina
 * cuando algo no cuadra.
 */
final readonly class UpdateSettingsHandler
{
    /**
     * Clave del candado consultivo de la configuracion de la instalacion.
     *
     * Un entero arbitrario pero **fijo y unico en el producto**: el espacio de
     * `pg_advisory_lock` es global a la base de datos, asi que dos usos distintos
     * con el mismo numero se bloquearian entre si sin ninguna relacion. Se
     * compone del numero de fase y de tarea (5.1) para que el siguiente que
     * necesite uno vea la convencion y elija otro.
     */
    private const int LOCK_KEY = 5_010_001;

    public function __construct(
        private SettingsRepository $settings,
        private GetSettingsHandler $read,
        private ProductEventPublisher $events,
        private SettingsMetrics $metrics,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws UnknownSettingKey cuando una clave no esta en el catalogo
     * @throws InvalidSettingValue cuando un valor no cumple lo que su clave declara
     */
    public function handle(UpdateSettingsCommand $command): ResolvedSettings
    {
        $requested = [];

        foreach ($command->values as $key => $raw) {
            $requested[] = SettingValue::of(SettingKey::fromString($key), $raw);
        }

        /** @var list<SettingValue> $changed */
        $changed = $this->connection->transaction(function () use ($requested, $command): array {
            // Serializa a los escritores de configuracion, y solo a ellos. Se
            // suelta al confirmar o al revertir: no hay forma de olvidarlo.
            $this->connection->statement('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $current = ResolvedSettings::resolve($this->settings->storedValuesForWrite());

            // Valida las invariantes de conjunto contra lo que de verdad hay
            // confirmado. Se descarta el resultado a proposito: lo que interesa
            // es que NO lance.
            $current->with(...$requested);

            $changed = array_values(array_filter(
                $requested,
                static fn (SettingValue $value): bool => ! $value->equals($current->get($value->key)),
            ));

            if ($changed === []) {
                return [];
            }

            $this->settings->save($changed, $command->actorUserId);
            $this->publish($changed, $current);

            return $changed;
        });

        $this->observe($changed);

        return $this->read->handle();
    }

    /**
     * Un evento por clave cambiada, con el antes y el despues.
     *
     * @param  list<SettingValue>  $changed
     */
    private function publish(array $changed, ResolvedSettings $current): void
    {
        $at = $this->clock->now();
        $events = [];

        foreach ($changed as $value) {
            $previous = $current->get($value->key);

            $events[] = new InstallationSettingChanged(
                key: $value->key->value,
                previousValue: $previous->value(),
                newValue: $value->value(),
                impact: $value->key->definition()->impact->value,
                affectsWorkedHours: $value->affectsWorkedHours(),
                wasProductDefault: $previous->isProductDefault,
                occurredAt: $at,
            );
        }

        $this->events->publish(...$events);
    }

    /**
     * `installation_setting_changes_total{affects_worked_hours}` (doc 02 §8.2).
     *
     * Fuera de la transaccion y sin poder romperla: cuando se llega aqui las
     * filas estan escritas y sus asientos confirmados. Se separan las dos
     * etiquetas porque un `PATCH` puede cambiar a la vez un umbral que mueve
     * minutos y un color que no.
     *
     * @param  list<SettingValue>  $changed
     */
    private function observe(array $changed): void
    {
        $affecting = 0;

        foreach ($changed as $value) {
            if ($value->affectsWorkedHours()) {
                $affecting++;
            }
        }

        $this->metrics->settingsChanged(true, $affecting);
        $this->metrics->settingsChanged(false, count($changed) - $affecting);
    }
}

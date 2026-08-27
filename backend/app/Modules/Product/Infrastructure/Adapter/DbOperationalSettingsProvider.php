<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\ValueObject\OperationalSettings;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Los umbrales **operativos** del centro, leidos de `installation_settings`
 * (RF-PD-01, regla dura 14, ADR-025).
 *
 * **Por que existe ya, si la tarea que lo declara es la 5.1.** Porque sin el, el
 * primer fichaje no tiene con que evaluar RN-08 ni RF-AT-06 y la alternativa
 * seria escribir 12 h y 60 s como constantes en PHP — que es exactamente lo que
 * la regla dura 14 y ADR-017 prohiben. Lo que la tarea 5.1 anade encima es la
 * **edicion desde el panel** y la auditoria de ese cambio
 * (`calculation_setting.changed`); la lectura, que es lo que el nucleo necesita,
 * es esto y no cambiara de forma.
 *
 * ## La cascada
 *
 * El valor de ambito `site` gana sobre el de ambito `installation`. Un hotel con
 * dos edificios necesita otro `ATTENDANCE_MIN_TRANSIT_SECONDS` que uno con dos
 * tablets contiguas en la misma puerta (doc 01 §4, nota sobre RN-16), y esa
 * diferencia no puede obligar a partir la instalacion en dos.
 *
 * ## Las horas y los minutos
 *
 * `ATTENDANCE_MAX_SHIFT_HOURS` se enuncia en horas porque asi lo dice el
 * negocio —«12 h»— y se convierte aqui, en el adaptador. El dominio razona en
 * minutos porque esa es la unidad del calculo (`duration_minutes`,
 * `total_minutes`), y hacer la conversion en el borde evita que cada consumidor
 * decida por su cuenta si multiplicar o no.
 *
 * ## Sin valores por defecto en el codigo
 *
 * Si falta una clave, **falla**. No cae en un valor de respaldo escrito aqui:
 * un umbral de serie escondido en PHP es indistinguible de uno configurado
 * hasta que alguien compara la nomina con la configuracion del panel. Los
 * cuatro valores de serie los siembra la migracion de `installation_settings`
 * (tarea 1.3), que es donde el cliente puede cambiarlos sin desplegar nada.
 */
final class DbOperationalSettingsProvider implements OperationalSettingsProvider
{
    private const string MAX_SHIFT_HOURS = 'ATTENDANCE_MAX_SHIFT_HOURS';

    private const string DEBOUNCE_SECONDS = 'ATTENDANCE_DEBOUNCE_SECONDS';

    private const string MAX_CLOCK_SKEW_MINUTES = 'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES';

    private const string MIN_TRANSIT_SECONDS = 'ATTENDANCE_MIN_TRANSIT_SECONDS';

    /**
     * Memoria por peticion.
     *
     * El caso de uso del fichaje pide la configuracion en **cada** escaneo, y a
     * cincuenta por segundo (RNF-P-06) eso serian cincuenta consultas por
     * segundo a una tabla de cuatro filas que cambia una vez al ano. No es una
     * cache con invalidacion: el proceso muere con la peticion, asi que un
     * cambio en el panel tiene efecto en la siguiente.
     *
     * @var array<int, OperationalSettings>
     */
    private array $resolved = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function forSite(int $siteId): OperationalSettings
    {
        if (isset($this->resolved[$siteId])) {
            return $this->resolved[$siteId];
        }

        $values = $this->valuesFor($siteId);

        return $this->resolved[$siteId] = new OperationalSettings(
            anomalousShiftMinutes: $this->integer($values, self::MAX_SHIFT_HOURS) * 60,
            debounceSeconds: $this->integer($values, self::DEBOUNCE_SECONDS),
            maximumClockSkewMinutes: $this->integer($values, self::MAX_CLOCK_SKEW_MINUTES),
            minimumTransitSeconds: $this->integer($values, self::MIN_TRANSIT_SECONDS),
        );
    }

    /**
     * Las cuatro claves resueltas por la cascada, en una sola consulta.
     *
     * Se ordena por `scope` de forma que `site` se lea despues de
     * `installation`: al indexar por clave, el ultimo escrito gana. Es mas
     * barato y mas claro que un `DISTINCT ON` con el que habria que razonar cada
     * vez que se lee.
     *
     * @return array<string, mixed>
     */
    private function valuesFor(int $siteId): array
    {
        /** @var list<object{key: string, value: string, scope: string}> $rows */
        $rows = $this->connection->table('installation_settings')
            ->select(['key', 'value', 'scope'])
            ->whereIn('key', [
                self::MAX_SHIFT_HOURS,
                self::DEBOUNCE_SECONDS,
                self::MAX_CLOCK_SKEW_MINUTES,
                self::MIN_TRANSIT_SECONDS,
            ])
            ->where(function ($query) use ($siteId): void {
                $query->where('scope', 'installation')
                    ->orWhere(fn ($nested) => $nested->where('scope', 'site')->where('scope_id', $siteId));
            })
            ->orderBy('scope')
            ->get()
            ->all();

        $values = [];

        foreach ($rows as $row) {
            $values[$row->key] = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value)) {
            throw new RuntimeException(
                'La configuracion operativa no tiene un entero en '.$key.'. '
                .'La siembra la migracion de installation_settings; revisa si se ha editado a mano.',
            );
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Connection;

/**
 * `installation_settings` con una capa de cache delante (paso 6 de la tarea
 * 5.1).
 *
 * ## Por que hace falta
 *
 * La configuracion operativa se lee **en cada escaneo**: el anti-rebote de
 * RF-AT-06, la duracion anomala de RN-08, la tolerancia de desfase de RF-AT-10 y
 * el transito minimo de RN-16 se resuelven antes de decidir que hace ese
 * fichaje. A cincuenta escaneos por segundo (RNF-P-06) eso serian cincuenta
 * consultas por segundo a una tabla de nueve filas que cambia una vez al año.
 *
 * ## Que se guarda: las filas, no la resolucion
 *
 * En Redis va el mapa `clave => valor decodificado`, que son escalares, cadenas
 * y listas. **No va `ResolvedSettings`**, aunque sea lo que se usa despues, y la
 * diferencia importa en un producto que se instala en casa del cliente: un
 * objeto de valor serializado en el Redis de un cliente sobrevive al despliegue
 * que cambia su clase, y lo que sale del `unserialize` es un objeto de la
 * version anterior o un error. Es un fallo que no se reproduce en el laboratorio
 * y que se manifiesta en el camino de fichaje. Con escalares no puede ocurrir, y
 * resolver la cascada cuesta lo que cuesta recorrer nueve claves.
 *
 * ## Clave versionada
 *
 * {@see self::KEY} lleva `v1`. Cuando cambie la **forma** de lo que se guarda
 * —no su contenido—, se sube el numero y la version nueva deja de leer lo que
 * escribio la anterior, sin depender de que nadie se acuerde de vaciar el Redis
 * del cliente durante la actualizacion.
 *
 * ## TTL de seguridad
 *
 * {@see self::TTL_SECONDS} es una **red**, no el mecanismo: la invalidacion
 * explicita de `save()` es lo que hace que un cambio del panel se note en la
 * peticion siguiente. El TTL cubre lo que la invalidacion no puede ver —una fila
 * editada con `psql` durante una intervencion de soporte, un `UPDATE` de un
 * script de migracion de datos—, para que el desajuste se cure solo en cinco
 * minutos en lugar de durar hasta el proximo reinicio. Cinco minutos es corto
 * para una persona que esta mirando y largo para el camino de fichaje.
 *
 * Sin cache disponible —Redis caido— {@see CacheRepository} vuelve a consultar la
 * base de datos: se pierde el ahorro, no el fichaje.
 *
 * ## La invalidacion es dos veces, y no es redundancia
 *
 * Al guardar se borra la entrada **inmediatamente** y otra vez **al confirmar la
 * transaccion**. La primera cubre a quien lea despues en este mismo proceso; la
 * segunda cubre la carrera real: entre el borrado y el `COMMIT`, otra peticion
 * puede leer de la base de datos —todavia el valor viejo, porque el cambio no
 * esta confirmado— y volver a poblar la cache con el. Sin el segundo borrado,
 * ese valor viejo se quedaria hasta que venciera el TTL.
 */
final readonly class CachedSettingsRepository implements SettingsRepository
{
    /**
     * Prefijo propio del producto y version de la forma. Nunca lleva el nombre
     * del cliente: la instalacion es de uno solo (ADR-016) y meterlo seria dato
     * de cliente en el codigo (regla dura 13).
     */
    private const string KEY = 'kronoqr:product:installation_settings:v1';

    /** Cinco minutos. Ver el docblock: es la red, no el mecanismo. */
    private const int TTL_SECONDS = 300;

    public function __construct(
        private SettingsRepository $settings,
        private CacheRepository $cache,
        private Connection $connection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function storedValues(): array
    {
        $cached = $this->cache->get(self::KEY);

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $values = $this->settings->storedValues();

        $this->cache->put(self::KEY, $values, self::TTL_SECONDS);

        return $values;
    }

    /**
     * La lectura del camino de escritura: **directa a la base de datos**.
     *
     * La cache se invalida al escribir y otra vez al confirmar, pero entre el
     * COMMIT de un escritor y su invalidacion hay una ventana. Quien va a
     * escribir lee bajo candado para decidir el valor anterior de cada asiento y
     * para comprobar las invariantes entre claves: leer ahi algo de antes del
     * COMMIT anterior produciria un asiento que declara un valor que nunca
     * rigio. Cuesta una consulta por PATCH, que ocurre una vez al ano.
     *
     * **No repuebla la cache** a proposito: hacerlo la llenaria con el estado
     * de mitad de una transaccion que todavia puede revertir.
     *
     * @return array<string, mixed>
     */
    public function storedValuesForWrite(): array
    {
        return $this->settings->storedValuesForWrite();
    }

    /**
     * @param  list<SettingValue>  $values
     */
    public function save(array $values, int $actorUserId): void
    {
        $this->settings->save($values, $actorUserId);

        $this->cache->forget(self::KEY);

        if ($this->connection->transactionLevel() > 0) {
            // Dentro de una transaccion: el borrado de arriba puede quedar
            // deshecho por una lectura concurrente que repueble con el valor
            // todavia no confirmado. Se repite al confirmar. Si la transaccion
            // revierte, este callback no corre y el borrado de arriba solo ha
            // costado una consulta de mas.
            $this->connection->afterCommit(function (): void {
                $this->cache->forget(self::KEY);
            });
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Como escribe **el producto** un fichero del colector *textfile* de
 * `node-exporter`, escrito una vez (doc 02 §8.2).
 *
 * ## Por que existe
 *
 * Siete adaptadores publican metricas por fichero —proyeccion, incidencias,
 * retencion, credenciales, presencia, auditoria y exportacion legal— y su
 * **contenido** es y debe ser distinto: cada uno sabe que series tiene que
 * escribir y por que. Su **mecanica de escritura**, en cambio, tiene que ser la
 * misma: es el mismo colector el que los lee y las mismas averias las que se
 * llevan por delante.
 *
 * Es el mismo argumento de `Shared\Infrastructure\Export\CsvDialect` —nombrado
 * en prosa y no con `{@see}` porque un `use` hacia `Export` seria una arista que
 * Deptrac no concede a esta capa, y con razon— y con el mismo desenlace: *cuando
 * cada escritor declaraba sus propias constantes, dejaron de coincidir sin que
 * nadie se enterara*. Aqui pasó dos
 * veces, las dos en `TextfileLegalExportMetrics`: era el unico que no comprobaba
 * el retorno de `rename()` —un `.prom` conservando la cifra vieja
 * indefinidamente se lee en Grafana exactamente igual que una instalacion
 * tranquila— y el unico cuyo metodo de escritura no miraba
 * `observability.metrics.enabled`. Nada fallo; simplemente el producto pasó a
 * tener dos formas de publicar una metrica, y solo una de ellas era la correcta.
 *
 * Por eso esto no es un puñado de lineas en un sitio comodo: mientras el metodo
 * exista, no hay forma de publicar un `.prom` sin el guard, sin la escritura
 * atomica y sin el fallo ruidoso, salvo borrando antes esta clase.
 *
 * ## Las tres decisiones
 *
 * - **El guard de `observability.metrics.enabled`.** Una instalacion que apaga
 *   el colector no debe seguir escribiendo ficheros que nadie recoge. El valor
 *   por omision es `true`: apagarlo es una decision explicita.
 * - **Escritura atomica.** Temporal en el **mismo** directorio y `rename()`.
 *   `node-exporter` puede leer el fichero justo mientras se escribe, y media
 *   metrica es peor que ninguna: descarta el fichero entero. El temporal va al
 *   lado del destino a proposito, porque `rename()` solo es atomico dentro del
 *   mismo sistema de ficheros.
 * - **El fallo se lanza, no se traga.** Un directorio que no se puede crear, un
 *   disco lleno o un `rename()` que devuelve `false` dejan la serie congelada en
 *   su ultimo valor, que es justo el estado que ninguna alerta detecta: la serie
 *   sigue ahi, con su numero de ayer. Quien llama decide si eso puede tumbar su
 *   caso de uso —lo normal es que si, porque es un comando programado— o si lo
 *   registra y sigue, pero lo decide **viendo la excepcion**.
 *
 * ## Lo que NO decide esta clase
 *
 * Ni los nombres de las series, ni sus `# HELP` y `# TYPE`, ni si un contador se
 * acumula leyendo su valor anterior, ni que etiquetas lleva cada una. Eso es
 * contenido y pertenece a cada adaptador, que es quien conoce su metrica.
 */
final class TextfileExposition
{
    /**
     * No se instancia. Es la mecanica de escritura del producto, no un
     * colaborador: un adaptador que pudiera recibir «otra forma de escribir»
     * seria exactamente la puerta que esta clase cierra.
     */
    private function __construct() {}

    /**
     * Publica las lineas en `<textfile_path>/<file>` de forma atomica.
     *
     * No hace nada si el colector esta apagado. Lanza si no puede dejar el
     * fichero publicado.
     *
     * @param  string  $file  Nombre del fichero, `kronoqr_*.prom` por convenio.
     * @param  list<string>  $lines  Lineas ya compuestas, sin el salto final.
     *
     * @throws RuntimeException
     */
    public static function write(string $file, array $lines): void
    {
        if (! Config::boolean('observability.metrics.enabled', true)) {
            return;
        }

        $directory = self::directory();

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio de metricas «'.$directory.'».');
        }

        $target = $directory.'/'.$file;
        $temporary = $target.'.tmp';

        if (file_put_contents($temporary, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('No se ha podido escribir la metrica en «'.$temporary.'».');
        }

        if (! rename($temporary, $target)) {
            throw new RuntimeException('No se ha podido publicar la metrica en «'.$target.'».');
        }
    }

    /**
     * La ruta del fichero, para los adaptadores cuyo contador **se acumula** y
     * tienen que leer su valor anterior antes de reescribirlo.
     *
     * Es la misma resolucion que usa {@see self::write()}: si el destino de la
     * lectura y el de la escritura pudieran diferir, un `counter` volveria a
     * cero sin motivo.
     */
    public static function path(string $file): string
    {
        return self::directory().'/'.$file;
    }

    /**
     * El escapado que el formato de exposicion de Prometheus exige dentro del
     * **valor** de una etiqueta: `\`, `"` y el salto de linea.
     *
     * Sin esto, un centro llamado `Hotel "El Faro"` o un departamento llamado
     * `Sala "El Faro"` producen un fichero que `node-exporter` descarta entero
     * —y con el las series de todos los demas centros y departamentos—. Se
     * aplica a todo valor que venga de datos de la instalacion; los que salen de
     * un enum respaldado no lo necesitan, pero pasarlos por aqui no cuesta nada
     * y evita tener que acordarse de cual era cual.
     */
    public static function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $value);
    }

    private static function directory(): string
    {
        return rtrim(Config::string('observability.metrics.textfile_path'), '/');
    }
}

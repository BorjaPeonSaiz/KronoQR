<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Metrics;

use App\Modules\Compliance\Application\Port\IncidentMetrics;
use App\Modules\Compliance\Application\Port\IncidentTally;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Publica `incidents_open{type,severity}` para el colector *textfile* de
 * `node-exporter` (doc 02 §8.2, doc 01 §9.2).
 *
 * ```
 * incidents_open{type="open_shift_expired",severity="medium"} 3
 * incidents_metrics_timestamp_seconds
 * ```
 *
 * **Se escriben todos los tipos, tambien los que estan a cero.** Una serie que
 * desaparece es indistinguible de una que nunca tuvo nada, y aqui el cero es
 * justo lo que se mira: «no hay ningun turno abierto de mas de doce horas». Sin
 * el, la alerta del doc 01 §9.3 no podria distinguir «todo en orden» de «la tarea
 * programada dejo de ejecutarse» — y para eso ademas esta el sello de tiempo.
 *
 * Cada tipo se publica con **su** severidad (la que decide `IncidentType`), no
 * con las tres: publicar `short_shift` con severidad alta seria una serie que no
 * puede existir, y en un panel se lee igual que una que sí.
 *
 * **Escritura atomica**: temporal en el mismo directorio y `rename()`.
 * `node-exporter` no puede leer media metrica. Es el mismo patron que
 * `TextfileAuditMetrics` y `TextfilePresenceMetrics`.
 */
final readonly class TextfileIncidentMetrics implements IncidentMetrics
{
    private const string FILE = 'kronoqr_incidents.prom';

    public function publish(array $tallies, DateTimeImmutable $at): void
    {
        $lines = [
            '# HELP incidents_open Incidencias abiertas ahora mismo, por tipo y severidad (doc 02 §8.2). Baja cuando alguien las resuelve.',
            '# TYPE incidents_open gauge',
        ];

        foreach ($this->withZeroes($tallies) as $labels => $open) {
            $lines[] = 'incidents_open'.$labels.' '.$open;
        }

        $lines[] = '# HELP incidents_metrics_timestamp_seconds Momento del ultimo recuento. Su ausencia delata que la tarea programada dejo de ejecutarse.';
        $lines[] = '# TYPE incidents_metrics_timestamp_seconds gauge';
        $lines[] = 'incidents_metrics_timestamp_seconds '.$at->getTimestamp();

        $this->write($lines);
    }

    /**
     * Los recuentos reales, mas un cero por cada tipo del catalogo que no
     * aparece.
     *
     * Se indexa por la etiqueta completa para que un recuento con una severidad
     * distinta de la de serie —una incidencia escalada a mano— no pise al cero de
     * su tipo ni al reves.
     *
     * @param  list<IncidentTally>  $tallies
     * @return array<string, int>
     */
    private function withZeroes(array $tallies): array
    {
        $series = [];

        foreach (IncidentType::cases() as $type) {
            $series[$this->labelsFor($type->value, $type->defaultSeverity()->value)] = 0;
        }

        foreach ($tallies as $tally) {
            $series[$this->labelsFor($tally->type->value, $tally->severity->value)] = $tally->open;
        }

        ksort($series);

        return $series;
    }

    private function labelsFor(string $type, string $severity): string
    {
        // Los dos valores salen de enums respaldados, asi que no hay nada que
        // escapar: ni comillas, ni barras, ni saltos de linea. La cardinalidad
        // esta acotada por el catalogo, que es lo que impide que esta serie
        // explote.
        return '{type="'.$type.'",severity="'.$severity.'"}';
    }

    /**
     * @param  list<string>  $lines
     */
    private function write(array $lines): void
    {
        if (! Config::boolean('observability.metrics.enabled', true)) {
            return;
        }

        $directory = rtrim(Config::string('observability.metrics.textfile_path'), '/');

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio de metricas «'.$directory.'».');
        }

        $target = $directory.'/'.self::FILE;
        $temporary = $target.'.tmp';

        if (file_put_contents($temporary, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('No se ha podido escribir la metrica en «'.$temporary.'».');
        }

        if (! rename($temporary, $target)) {
            throw new RuntimeException('No se ha podido publicar la metrica en «'.$target.'».');
        }
    }
}

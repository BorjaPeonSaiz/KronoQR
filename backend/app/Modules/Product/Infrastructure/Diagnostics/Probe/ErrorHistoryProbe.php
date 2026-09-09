<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Sondas `app.error_history` de `product:doctor` (RF-PD-13, RF-PD-15,
 * decision 14).
 *
 * ## Que responde, y por que no basta con la pantalla
 *
 * `error_events` tiene techo por origen: al llegar a
 * `PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE`, los errores nuevos de ese origen
 * dejan de abrir fila y se cuentan en un grupo de desbordamiento. Eso es una
 * degradacion **silenciosa desde la pantalla**: el panel sigue listando grupos y
 * nada dice que se este perdiendo detalle.
 *
 * `doctor` es donde el IT del cliente mira cuando algo va raro, y aqui se lo
 * dice con el numero y con que hacer. Sin esta sonda, la unica forma de
 * enterarse seria leer el mensaje del grupo de desbordamiento entre otros
 * quinientos.
 *
 * ## Dos comprobaciones y dos umbrales
 *
 * - **Por origen**: `warning` al 80 % del techo —hay margen para reaccionar— y
 *   `failure` al llegar, que es cuando ya se esta perdiendo detalle.
 * - **Total de la tabla**: `warning` por encima de {@see self::BUSY_ROWS}. No es
 *   un limite del producto, es un orden de magnitud: una instalacion sana no
 *   acumula decenas de miles de grupos distintos en noventa dias, y si los
 *   acumula lo que hay es un problema de agrupacion —una huella que no agrupa—
 *   que conviene mirar antes de que la tabla sea el problema.
 *
 * ## Familia `app` y no una propia
 *
 * `doctor` ordena su informe por familias y el IT lee de arriba abajo. Una
 * familia nueva para dos lineas alargaria el informe sin anadir una categoria
 * que alguien reconozca; esto es, literalmente, el estado de la aplicacion.
 *
 * ## Ni un `failure` que pare una actualizacion por esto
 *
 * El techo alcanzado sale como `failure` —hay que actuar— y eso hace que
 * `product:doctor` devuelva `2`, que `update.sh` traduce a su codigo `6`. **Es
 * deliberado y esta acotado**: llegar al techo significa que la instalacion
 * lleva quinientos fallos abiertos sin atender de un mismo origen, que es
 * exactamente el momento de parar y mirar. A diferencia de la licencia (ADR-019,
 * regla dura 15), esto **no** es un estado comercial: es el producto diciendo
 * que esta perdiendo informacion de diagnostico.
 *
 * ## Nunca lanza
 *
 * Si la consulta falla —una instalacion a medio migrar, la tabla que aun no
 * existe— devuelve un aviso con el nombre de la clase y las otras siete familias
 * siguen comprobandose. Un `doctor` que revienta es inutil justo cuando hace
 * falta.
 */
final readonly class ErrorHistoryProbe implements DoctorProbe
{
    /** Fraccion del techo a partir de la cual se avisa. */
    private const float WARNING_RATIO = 0.8;

    /**
     * Grupos en total —abiertos y resueltos— por encima de los cuales conviene
     * mirar. Ver el docblock: es un orden de magnitud, no un limite.
     */
    private const int BUSY_ROWS = 20_000;

    public function __construct(
        private ConnectionInterface $database,
        private int $maxOpenGroupsPerSource,
    ) {}

    public function family(): string
    {
        return 'app';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        try {
            return [$this->groups()];
        } catch (Throwable $failure) {
            // La CLASE y nunca el mensaje: un error de PostgreSQL puede llevar
            // dentro el valor de una fila (regla dura 21), y este informe viaja
            // en el paquete de diagnostico.
            return [DoctorFinding::warning(
                'app.error_history',
                'unavailable',
                params: ['failure' => $failure::class],
                details: ['failure' => $failure::class],
            )];
        }
    }

    private function groups(): DoctorFinding
    {
        /** @var list<object{source: string, open: int|string}> $rows */
        $rows = $this->database->select(
            'SELECT source, count(*) AS open FROM error_events WHERE resolved_at IS NULL GROUP BY source',
        );

        /** @var object{total: int|string}|null $totalRow */
        $totalRow = $this->database->selectOne('SELECT count(*) AS total FROM error_events');

        $total = (int) ($totalRow->total ?? 0);

        // Todos los origenes siempre, tambien los que estan a cero: un informe
        // al que le falta `kiosk` no dice «el quiosco no tiene errores».
        $open = array_fill_keys(ErrorSource::names(), 0);
        $worst = 0;
        $worstSource = '';

        foreach ($rows as $row) {
            $count = (int) $row->open;
            $open[$row->source] = $count;

            if ($count > $worst) {
                $worst = $count;
                $worstSource = $row->source;
            }
        }

        $details = ['open_by_source' => $open, 'total_groups' => $total, 'cap' => $this->maxOpenGroupsPerSource];
        $params = ['source' => $worstSource, 'open' => $worst, 'cap' => $this->maxOpenGroupsPerSource, 'total' => $total];

        if ($this->maxOpenGroupsPerSource > 0 && $worst >= $this->maxOpenGroupsPerSource) {
            return DoctorFinding::failure('app.error_history', params: $params, details: $details);
        }

        if ($this->maxOpenGroupsPerSource > 0 && $worst >= (int) ($this->maxOpenGroupsPerSource * self::WARNING_RATIO)) {
            return DoctorFinding::warning('app.error_history', params: $params, details: $details);
        }

        if ($total > self::BUSY_ROWS) {
            return DoctorFinding::warning('app.error_history', 'busy', params: $params, details: $details);
        }

        return DoctorFinding::ok('app.error_history', $details, $params);
    }
}

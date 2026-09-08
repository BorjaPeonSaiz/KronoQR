<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;

/**
 * El documento **completo** que sale de la instalacion cuando la telemetria esta
 * activada (**RF-PD-12**, ADR-020, ADR-023, ficha 5.10 punto 8).
 *
 * ## Lista cerrada, y la lista esta escrita aqui
 *
 * {@see self::FIELDS} es el catalogo, en notacion con punto, de todo lo que
 * puede viajar. No es documentacion: `TelemetryReportTest` compara esa constante
 * con {@see self::fieldNames()} —lo que el documento produce de verdad— y con la
 * tabla de `docs/cliente/configuracion.md` §3 quinquies. Las tres tienen que
 * coincidir campo a campo, asi que **un campo nuevo no puede salir de aqui sin
 * aparecer antes en el documento que el cliente lee para decidir**.
 *
 * Es la misma idea que {@see FieldAllowlist} aplica al paquete de diagnostico y
 * por el mismo motivo: una lista de exclusiones falla en silencio cada vez que
 * alguien añade un campo.
 *
 * ## Lo que NUNCA lleva, dicho por escrito
 *
 * Nombres, apellidos, correos, codigos de empleado, PIN, DNI, horas de fichaje,
 * rutas del servidor, `APP_URL`, el nombre del hotel, la razon social de la
 * licencia, su `license_id`, la huella de la clave y el `uuid` de nadie —ni de
 * una persona, ni de un quiosco, ni de un tramo—. El unico identificador es
 * `installation_id`, que es un UUID v4 **aleatorio** acuñado por la propia
 * instalacion y que no deriva de nada del cliente.
 *
 * ## Por que hay tramos y no cifras en `scale.employees_active`
 *
 * Ver {@see TelemetryScaleBand}: la cifra exacta permite reconocer a un cliente
 * y no mejora la unica pregunta que la telemetria responde.
 *
 * ## `doctor` sin mensajes
 *
 * Solo el veredicto de cada comprobacion (`ok`, `warning`, `failure`). El
 * `summary`, el `fix` y los `details` de {@see DoctorCheck} llevan rutas, hosts
 * de correo y nombres de fichero: eso viaja en el paquete de diagnostico, que el
 * cliente decide enviar y puede abrir antes, no en un envio automatico semanal.
 */
final readonly class TelemetryReport
{
    /** Version del esquema del documento. Un receptor que no la reconozca lo descarta. */
    public const int SCHEMA_VERSION = 1;

    /**
     * **El catalogo cerrado.** Ver el docblock: coincide con la tabla de
     * `configuracion.md` §3 quinquies y una prueba lo ata.
     *
     * `doctor` aparece una sola vez porque sus claves son los identificadores de
     * las comprobaciones de `product:doctor`, que cambian con las sondas; lo
     * cerrado ahi es el **valor** —`ok`, `warning` o `failure`— y nada mas.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'schema_version',
        'installation_id',
        'sent_at',
        'product.version',
        'product.php_version',
        'product.database_version',
        'license.state',
        'license.plan',
        'license.features',
        'license.days_until_expiry',
        'scale.employees_active',
        'scale.devices_active',
        'scale.departments',
        'usage_7d.scans_accepted',
        'usage_7d.scans_rejected',
        'usage_7d.batches_synced',
        'usage_7d.incidents_open',
        'usage_7d.reports_generated',
        'doctor',
    ];

    /**
     * Las secciones cuyas claves NO son fijas y que por tanto se nombran enteras
     * en {@see self::FIELDS}.
     *
     * @var list<string>
     */
    private const array OPAQUE_SECTIONS = ['doctor'];

    /**
     * @param  list<string>  $licenseFeatures
     * @param  array<string, string>  $doctor  Identificador de la comprobacion => `ok|warning|failure`.
     */
    public function __construct(
        public string $installationId,
        public DateTimeImmutable $sentAt,
        public string $productVersion,
        public string $phpVersion,
        public ?string $databaseVersion,
        public string $licenseState,
        public ?string $licensePlan,
        public array $licenseFeatures,
        public ?int $daysUntilExpiry,
        public TelemetryScaleBand $employeesActive,
        public int $devicesActive,
        public int $departments,
        public TelemetryUsage $usage,
        public array $doctor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'installation_id' => $this->installationId,
            'sent_at' => UtcInstant::of($this->sentAt),
            'product' => [
                'version' => $this->productVersion,
                'php_version' => $this->phpVersion,
                'database_version' => $this->databaseVersion,
            ],
            'license' => [
                'state' => $this->licenseState,
                'plan' => $this->licensePlan,
                'features' => $this->licenseFeatures,
                'days_until_expiry' => $this->daysUntilExpiry,
            ],
            'scale' => [
                'employees_active' => $this->employeesActive->value,
                'devices_active' => $this->devicesActive,
                'departments' => $this->departments,
            ],
            'usage_7d' => $this->usage->toArray(),
            'doctor' => $this->doctor,
        ];
    }

    /**
     * La forma canonica, que es la que se envia.
     *
     * Canonica y no `json_encode()` a secas por lo mismo que el paquete de
     * diagnostico (ver {@see CanonicalJson}): dos envios de la misma instalacion
     * tienen que poder compararse linea a linea, y quien lo recibe tiene que
     * poder calcular una huella estable sobre el cuerpo.
     */
    public function toJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    /**
     * Los campos que este documento produce **de verdad**, en notacion con punto.
     *
     * No devuelve {@see self::FIELDS}: lo recorre. Si alguien añadiera una clave
     * en {@see self::toArray()} sin tocar la constante, esto la enseñaria y la
     * prueba fallaria — que es todo el sentido de que existan las dos.
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        $names = [];

        foreach ($this->toArray() as $key => $value) {
            $key = (string) $key;

            if (! is_array($value) || array_is_list($value) || \in_array($key, self::OPAQUE_SECTIONS, true)) {
                $names[] = $key;

                continue;
            }

            foreach (array_keys($value) as $sub) {
                $names[] = $key.'.'.$sub;
            }
        }

        return $names;
    }
}

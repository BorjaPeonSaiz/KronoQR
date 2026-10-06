<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;

/**
 * Sondas `network.*` de `product:doctor` (PP-01, 2.2.0).
 *
 * ## La pregunta que responde
 *
 * «Las tres redes que decide el cliente en el `.env`, ¿dicen lo que el cliente
 * cree que dicen?» Nginx las usa para tres candados —los quioscos que fichan sin
 * el limite de internet (`KIOSK_VLAN_CIDR`), desde donde se abre el portal del
 * empleado (`PORTAL_INTERNAL_CIDR`) y quien lee `/metrics`
 * (`METRICS_ALLOW_CIDR`)— y **ninguna de las tres falla de forma visible**:
 * un rango que no cubre a nadie produce un 403, no una averia.
 *
 * ## Por que existe: el primer despliegue real
 *
 * El valor de serie de `PORTAL_INTERNAL_CIDR` era la subred de la red de
 * desarrollo (`172.28.0.0/16`), que en produccion no existe. Toda la plantilla
 * recibia un 403 y ningun control lo avisaba: ni el instalador, ni `doctor.sh`,
 * ni esta herramienta. (El propietario, ademas, abre el portal a internet a
 * proposito: ese caso legitimo tiene que ser **visible**, no un fallo.)
 *
 * ## Que es un aviso y que un fallo
 *
 *  - **Fallo**: la sintaxis no es un CIDR IPv4. Nginx se niega a arrancar con
 *    ella (`04-kronoqr-required-env.sh`), asi que es un borde caido, no un
 *    matiz.
 *  - **Aviso**: el borde arranca pero el rango hace algo que conviene decir en
 *    voz alta: `0.0.0.0/0` (abierto a internet), direcciones publicas, o el
 *    valor de ejemplo de la red de desarrollo. **Nunca un fallo**: abrir el
 *    portal a internet es una decision del cliente (RF-ID-08), y un `2` abortaria
 *    `update.sh` por algo que no esta roto.
 *
 * ## Lo que esta sonda NO puede saber
 *
 * Si el rango cubre a los empleados de verdad: la aplicacion vive en una red de
 * contenedores y no conoce las direcciones del servidor ni las de la ofimatica.
 * Eso lo comprueba `doctor.sh` desde fuera, y la guia explica como averiguar la
 * IP con la que nginx ve a un empleado.
 *
 * ## Ni un rango en `details` ni en el texto
 *
 * El informe viaja en el paquete de diagnostico (ADR-020) y un rango de red
 * describe la topologia interna del cliente. Se publican categorias —«abierto»,
 * «publico», «ejemplo»—, nunca el valor.
 *
 * ## Sin valor no se comprueba, y se dice
 *
 * Si la aplicacion no recibe la variable (un servicio sin ella, una prueba) el
 * hallazgo es `ok` con la variante `not_provided`, que lo declara: una
 * comprobacion que calla no es una comprobacion.
 *
 * ## La cuarta red: `ADMIN_INTERNAL_CIDR` (PP-10, ADR-050 §4)
 *
 * Cierra el panel y `/api/v1/auth/*` a un rango. Al contrario que las otras
 * tres, **vacia es un valor legitimo y el de serie**: el propietario quiere el
 * panel abierto. Por eso aqui se distingue «no la recibo» (`null`) de «la
 * recibo vacia» (`''`): la segunda es un aviso informativo del riesgo aceptado
 * —panel alcanzable desde donde lo sea el portal—, nunca un fallo, porque el
 * instalador no puede imponer una red que el cliente no ha decidido.
 *
 * ## Lo que NO esta aqui: la longitud del PIN y los recuentos
 *
 * Los hallazgos que ADR-050 §3 pide junto a estos —portal expuesto con PIN de 6,
 * personas con PIN de 6 pendientes de restablecer, segundo factor— necesitan
 * la base de datos, y viven en {@see AccessHardeningProbe}. Si estuvieran aqui,
 * una base de datos caida tumbaria la familia entera ({@see RunDoctorHandler})
 * y con ella los cuatro hallazgos de red, que son justo los que no dependen de
 * nada. La clasificacion del portal es una sola, {@see self::portalCategory()},
 * para que las dos sondas no lleguen nunca a veredictos distintos.
 */
final readonly class EdgeNetworksProbe implements DoctorProbe
{
    /**
     * La red de desarrollo, que era el valor de serie de `PORTAL_INTERNAL_CIDR`
     * en `.env.example`. En produccion esa red no tiene subred fija, asi que el
     * valor no cubre a nadie.
     */
    private const string DEVELOPMENT_NETWORK_SAMPLE = '172.28.0.0/16';

    /**
     * Lo que no se enruta por internet: RFC 1918, bucle local, enlace local y
     * el rango CGNAT de las VPN (100.64/10). Un rango que no cabe entero aqui
     * incluye direcciones publicas.
     *
     * @var list<string>
     */
    private const array PRIVATE_NETWORKS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '100.64.0.0/10',
    ];

    /** Categorias de {@see self::portalCategory()} con las que el portal se alcanza desde internet. */
    public const array EXPOSED_PORTAL_CATEGORIES = ['open', 'public'];

    /**
     * @param  string|null  $adminInternal  `ADMIN_INTERNAL_CIDR`: nulo si la aplicacion no
     *                                      la recibe, cadena vacia si la recibe vacia (el valor de serie).
     */
    public function __construct(
        private string $kioskVlan,
        private string $portalInternal,
        private string $metricsAllow,
        private ?string $adminInternal = null,
    ) {}

    public function family(): string
    {
        return 'network';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        return [
            $this->portal(),
            $this->admin(),
            $this->openable('network.kiosk_vlan', $this->kioskVlan),
            $this->openable('network.metrics', $this->metricsAllow),
        ];
    }

    /**
     * Que es el rango del portal: `not_provided`, `invalid`, `open`
     * (`0.0.0.0/0`), `sample` (la red de desarrollo), `public` o `private`.
     *
     * Publico y estatico porque lo comparte {@see AccessHardeningProbe}: la
     * recomendacion del PIN de 8 cifras depende de esta misma respuesta, y dos
     * clasificaciones acabarian discrepando en el borde de un rango.
     */
    public static function portalCategory(string $raw): string
    {
        $value = trim($raw);

        if ($value === '') {
            return 'not_provided';
        }

        $bounds = self::bounds($value);

        if ($bounds === null) {
            return 'invalid';
        }

        if (self::prefix($value) === 0) {
            return 'open';
        }

        if ($value === self::DEVELOPMENT_NETWORK_SAMPLE) {
            return 'sample';
        }

        return self::isPrivate($bounds) ? 'private' : 'public';
    }

    private function portal(): DoctorFinding
    {
        $id = 'network.portal';
        $category = self::portalCategory($this->portalInternal);

        return match ($category) {
            'not_provided' => $this->notProvided($id),
            'invalid' => DoctorFinding::failure($id, 'invalid', details: ['category' => 'invalid']),
            'open', 'sample', 'public' => DoctorFinding::warning($id, $category, details: ['category' => $category]),
            default => DoctorFinding::ok($id, ['category' => 'private']),
        };
    }

    /**
     * `ADMIN_INTERNAL_CIDR` (PP-10). Vacia es el valor de serie y un riesgo
     * aceptado por el propietario (ADR-050, residuo 3): se avisa para que conste,
     * no para corregir nada que este roto.
     */
    private function admin(): DoctorFinding
    {
        $id = 'network.admin';

        if ($this->adminInternal === null) {
            return $this->notProvided($id);
        }

        $value = trim($this->adminInternal);

        if ($value === '') {
            return DoctorFinding::warning($id, 'unfiltered', details: ['category' => 'unfiltered']);
        }

        if (self::bounds($value) === null) {
            return DoctorFinding::failure($id, 'invalid', details: ['category' => 'invalid']);
        }

        if (self::prefix($value) === 0) {
            return DoctorFinding::warning($id, 'unfiltered', details: ['category' => 'open']);
        }

        return DoctorFinding::ok($id, ['category' => 'restricted']);
    }

    /**
     * Quioscos y `/metrics`: el unico matiz que admiten es `0.0.0.0/0`.
     */
    private function openable(string $id, string $raw): DoctorFinding
    {
        $value = trim($raw);

        if ($value === '') {
            return $this->notProvided($id);
        }

        if (self::bounds($value) === null) {
            return DoctorFinding::failure($id, 'invalid', details: ['category' => 'invalid']);
        }

        if (self::prefix($value) === 0) {
            return DoctorFinding::warning($id, 'open', details: ['category' => 'open']);
        }

        return DoctorFinding::ok($id, ['category' => 'restricted']);
    }

    private function notProvided(string $id): DoctorFinding
    {
        return new DoctorFinding($id, DoctorStatus::Ok, [], ['category' => 'not_provided'], 'not_provided');
    }

    /**
     * Extremos del rango como enteros, o nulo si no es un CIDR IPv4 valido.
     *
     * La MISMA sintaxis que exigen `04-kronoqr-required-env.sh` y
     * `lib/checks.sh`: un solo rango, octetos 0-255 sin ceros a la izquierda y
     * prefijo 0-32. Lo que aqui pasara y alli no daria un «todo bien» sobre un
     * borde que no arranca.
     *
     * @return array{int, int}|null
     */
    private static function bounds(string $cidr): ?array
    {
        $octet = '(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])';

        if (preg_match('/^'.$octet.'\.'.$octet.'\.'.$octet.'\.'.$octet.'\/(3[0-2]|[12]?[0-9])$/', $cidr, $m) !== 1) {
            return null;
        }

        $address = ((int) $m[1] << 24) | ((int) $m[2] << 16) | ((int) $m[3] << 8) | (int) $m[4];
        $size = 1 << (32 - (int) $m[5]);
        $start = $address & ~($size - 1) & 0xFFFFFFFF;

        return [$start, $start + $size - 1];
    }

    private static function prefix(string $cidr): int
    {
        return (int) substr($cidr, (int) strrpos($cidr, '/') + 1);
    }

    /**
     * @param  array{int, int}  $bounds
     */
    private static function isPrivate(array $bounds): bool
    {
        foreach (self::PRIVATE_NETWORKS as $network) {
            $private = self::bounds($network);

            if ($private !== null && $bounds[0] >= $private[0] && $bounds[1] <= $private[1]) {
                return true;
            }
        }

        return false;
    }
}

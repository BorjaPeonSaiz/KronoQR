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

    public function __construct(
        private string $kioskVlan,
        private string $portalInternal,
        private string $metricsAllow,
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
            $this->openable('network.kiosk_vlan', $this->kioskVlan),
            $this->openable('network.metrics', $this->metricsAllow),
        ];
    }

    private function portal(): DoctorFinding
    {
        $id = 'network.portal';
        $value = trim($this->portalInternal);

        if ($value === '') {
            return $this->notProvided($id);
        }

        $bounds = $this->bounds($value);

        if ($bounds === null) {
            return DoctorFinding::failure($id, 'invalid', details: ['category' => 'invalid']);
        }

        if ($this->prefix($value) === 0) {
            return DoctorFinding::warning($id, 'open', details: ['category' => 'open']);
        }

        if ($value === self::DEVELOPMENT_NETWORK_SAMPLE) {
            return DoctorFinding::warning($id, 'sample', details: ['category' => 'sample']);
        }

        if (! $this->isPrivate($bounds)) {
            return DoctorFinding::warning($id, 'public', details: ['category' => 'public']);
        }

        return DoctorFinding::ok($id, ['category' => 'private']);
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

        if ($this->bounds($value) === null) {
            return DoctorFinding::failure($id, 'invalid', details: ['category' => 'invalid']);
        }

        if ($this->prefix($value) === 0) {
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
    private function bounds(string $cidr): ?array
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

    private function prefix(string $cidr): int
    {
        return (int) substr($cidr, (int) strrpos($cidr, '/') + 1);
    }

    /**
     * @param  array{int, int}  $bounds
     */
    private function isPrivate(array $bounds): bool
    {
        foreach (self::PRIVATE_NETWORKS as $network) {
            $private = $this->bounds($network);

            if ($private !== null && $bounds[0] >= $private[0] && $bounds[1] <= $private[1]) {
                return true;
            }
        }

        return false;
    }
}

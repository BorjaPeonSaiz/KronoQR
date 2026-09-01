<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;

/**
 * Claves de licencia para las pruebas.
 *
 * ## El par se genera EN CADA EJECUCION, y esa es toda la decision
 *
 * `sodium_crypto_sign_keypair()` en {@see self::mintKeyPair()}. **Jamas un par
 * fijo en el repositorio**, ni siquiera «de pruebas»: un par fijo es una clave
 * privada de firma en un fichero versionado, lo cazaria `gitleaks` con razon
 * (§7.7, RS-08) y, peor, normalizaria la idea de que hay claves privadas que
 * pueden vivir en el arbol. La del fabricante se custodia fuera y el emisor
 * (`tools/license-issuer/`) la recibe por variable de entorno.
 *
 * ## Como se usa
 *
 * `LicenseKeys::install()` genera el par, deja la publica en
 * `config('license.public_key')` y devuelve el emisor con el que fabricar
 * claves. A partir de ahi, `->issue(...)` produce cadenas que el verificador
 * **del producto** acepta, porque comparten el mismo formato — y si dejaran de
 * compartirlo, la prueba de ida y vuelta del emisor real lo diria.
 */
final readonly class LicenseKeys
{
    private function __construct(
        public string $publicKeyHex,
        /** @var non-empty-string */
        private string $secretKey,
    ) {}

    /**
     * Genera un par y lo deja instalado como clave publica de la aplicacion.
     *
     * Ademas lo registra en el contenedor para que {@see self::current()} lo
     * devuelva **tipado**. Se hace asi y no con `$this->keys` porque PHPStan 9
     * no conoce las propiedades dinamicas de un caso de Pest, y en este
     * repositorio el analisis se ejecuta tambien sobre `tests/`.
     */
    public static function install(): self
    {
        $keys = self::mint();

        config()->set('license.public_key', $keys->publicKeyHex);
        app()->instance(self::class, $keys);

        return $keys;
    }

    /**
     * El par instalado por {@see self::install()} en el `beforeEach`.
     */
    public static function current(): self
    {
        /** @var self $keys */
        $keys = app(self::class);

        return $keys;
    }

    /**
     * Deja la instalacion con una licencia **vigente y con todo contratado**.
     *
     * ## Para que existe
     *
     * Para las pruebas de las funcionalidades **accesorias** que ADR-023 declara
     * degradables —el informe por periodo (2.8) y la presencia en tiempo real
     * (2.4)—, que a partir de la tarea 5.3 necesitan una licencia que las
     * conceda. Sin esto, esas pruebas comprobarian la degradacion en lugar de la
     * funcionalidad.
     *
     * **No se hace de serie en la suite entera**, y es deliberado: si toda
     * prueba arrancara con licencia, la degradacion no la veria nadie hasta
     * llegar a casa del cliente. Cada prueba que necesite una la pide.
     *
     * Y **no hace falta para nada del registro legal**: el fichaje, la consulta
     * de jornadas, el portal, la exportacion para la Inspeccion, la auditoria y
     * las copias funcionan sin licencia por diseño (regla dura 15, ADR-019).
     * Que sus pruebas no llamen a esto es la comprobacion silenciosa de eso.
     *
     * La vigencia va de 2020 a 2099 a proposito: estas pruebas fijan el reloj en
     * fechas muy distintas y ninguna de ellas trata sobre la caducidad, que
     * tiene sus propias pruebas con instantes exactos.
     */
    public static function grantAll(): void
    {
        $keys = self::install();

        app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($keys->issue([
            'features' => array_map(static fn (Feature $feature): string => $feature->value, Feature::cases()),
            'valid_from' => '2020-01-01T00:00:00Z',
            'valid_until' => '2099-12-31T23:59:59Z',
            // Holgados: lo que estas pruebas comprueban no es el plan, y un
            // exceso llenaria su `audit_log` de asientos ajenos a lo que miran.
            'max_employees' => 100000,
            'max_devices' => 1000,
        ])));

        // El `FeatureGate` memoriza por peticion: si ya se resolvio antes de
        // activar, seguiria diciendo que no hay licencia.
        app()->forgetInstance(FeatureGate::class);
    }

    /**
     * Genera un par **sin** instalarlo. Sirve para la prueba de «otro emisor»:
     * una clave firmada con este par no puede verificar contra el instalado.
     */
    public static function mint(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self(
            sodium_bin2hex(sodium_crypto_sign_publickey($pair)),
            sodium_crypto_sign_secretkey($pair),
        );
    }

    /**
     * Emite una clave con la carga util indicada, ya combinada con unos valores
     * de serie razonables.
     *
     * Se pasa `null` como valor para **quitar** un campo, que es como se prueban
     * las claves incompletas sin escribir el JSON a mano.
     *
     * @param  array<string, mixed>  $claims
     */
    public function issue(array $claims = []): string
    {
        $payload = array_filter(
            [...self::defaults(), ...$claims],
            static fn (mixed $value): bool => $value !== null,
        );

        return $this->sign(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Firma un JSON tal cual, para las pruebas que necesitan una carga util que
     * no es un objeto o que no es JSON.
     */
    public function sign(string $payload): string
    {
        $encoded = self::encode($payload);

        return 'KQL1.'.$encoded.'.'.self::encode(sodium_crypto_sign_detached($encoded, $this->secretKey));
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'license_id' => 'test-0000000000000001',
            'customer_name' => 'Hotel de Pruebas, S.L.',
            'plan' => 'estandar',
            'max_employees' => 50,
            'max_devices' => 3,
            'features' => ['advanced_reports', 'realtime_presence'],
            'valid_from' => '2026-01-01T00:00:00Z',
            'valid_until' => '2026-12-31T23:59:59Z',
            'issued_at' => '2025-12-15T10:00:00Z',
        ];
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

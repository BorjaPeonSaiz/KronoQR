<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\InvalidLicenseKey;
use App\Modules\Shared\Domain\ValueObject\Feature;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * La carga util **ya verificada** de una clave de licencia (RF-PD-04, ADR-018).
 *
 * Este objeto solo existe si la firma ed25519 cuadro con la clave publica del
 * fabricante: la comprobacion criptografica es de `Infrastructure/`
 * (`Product/Infrastructure/Adapter/Ed25519LicenseVerifier`) y
 * la decision sobre lo que dice la clave es de aqui. Esa es la separacion que
 * pide el «Terminado cuando» de la tarea: firma en `Infrastructure/`, estado en
 * el dominio.
 *
 * ## El instante NO se calcula aqui
 *
 * Esta clase no sabe que dia es hoy y no puede saberlo (regla dura 2). Quien
 * decide si esta vigente es {@see LicenseStatus}, que recibe el instante del
 * puerto `Clock`. Aqui solo estan las fechas que vienen escritas en la clave.
 *
 * ## Campos desconocidos: se ignoran, no se rechazan
 *
 * Una clave emitida por una version posterior del producto puede traer campos
 * que esta instalacion no conoce. **Se ignoran en silencio y la clave verifica
 * igual.**
 *
 * Es una decision, no un descuido. Rechazar lo desconocido significaria que un
 * hotel que sigue en la 1.2 no puede activar la renovacion que el fabricante
 * emitio con la 1.6, y el efecto de eso es una instalacion degradada por un
 * cambio aditivo del emisor. Con claves firmadas no hay riesgo de inyeccion: el
 * contenido ya paso la firma, y lo que no se lee no se aplica.
 *
 * `max_sites` es el caso concreto y por eso esta escrito: **no se admite y no se
 * modela**. ADR-040 punto 5 retiro ese limite; conservarlo por compatibilidad lo
 * dejaria a la vista de la primera persona que quiera comprobarlo, y un limite
 * que no limita nada no debe poder consultarse. Que la clave lo traiga no es un
 * error del emisor; simplemente no llega al dominio.
 *
 * ## Sin datos personales
 *
 * Lo mas parecido a un dato personal que hay aqui es `customer_name`, que es la
 * razon social del hotel. No aparece en logs tecnicos, ni en `error_events`, ni
 * en la sonda publica de salud (regla dura 21 y ADR-020): se enseña en el panel
 * autenticado y en `license:show`, que ejecuta quien administra la instalacion.
 */
final readonly class License
{
    /**
     * @param  list<Feature>  $features  Accesorias habilitadas, sin duplicados y en orden de catalogo.
     */
    private function __construct(
        public string $licenseId,
        public string $customerName,
        public string $plan,
        public LicenseLimits $limits,
        public array $features,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validUntil,
        public DateTimeImmutable $issuedAt,
    ) {}

    /**
     * Construye la licencia a partir de las afirmaciones de la clave firmada.
     *
     * Recibe un array de escalares y no un JSON: descodificar es de
     * `Infrastructure/`, decidir si lo descodificado sirve es de aqui.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidLicenseKey si falta un campo obligatorio o tiene un tipo imposible
     */
    public static function fromClaims(array $claims): self
    {
        $validFrom = self::instant($claims, 'valid_from');
        $validUntil = self::instant($claims, 'valid_until');

        if ($validUntil < $validFrom) {
            throw InvalidLicenseKey::validityInverted(
                $validFrom->format(DateTimeInterface::ATOM),
                $validUntil->format(DateTimeInterface::ATOM),
            );
        }

        return new self(
            licenseId: self::text($claims, 'license_id'),
            customerName: self::text($claims, 'customer_name'),
            plan: self::text($claims, 'plan'),
            limits: LicenseLimits::of(
                self::integer($claims, 'max_employees'),
                self::integer($claims, 'max_devices'),
            ),
            features: self::features($claims),
            validFrom: $validFrom,
            validUntil: $validUntil,
            issuedAt: self::instant($claims, 'issued_at'),
        );
    }

    public function grants(Feature $feature): bool
    {
        return \in_array($feature, $this->features, true);
    }

    /**
     * Las funcionalidades habilitadas, como cadenas, para persistirlas y para el
     * contrato.
     *
     * @return list<string>
     */
    public function featureNames(): array
    {
        return array_map(static fn (Feature $feature): string => $feature->value, $this->features);
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidLicenseKey
     */
    private static function text(array $claims, string $field): string
    {
        $value = $claims[$field] ?? throw InvalidLicenseKey::missingField($field);

        if (! \is_string($value) || trim($value) === '') {
            throw InvalidLicenseKey::fieldNotText($field);
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidLicenseKey
     */
    private static function integer(array $claims, string $field): int
    {
        $value = $claims[$field] ?? throw InvalidLicenseKey::missingField($field);

        // Estricto: "25" no es 25. Una clave la emite el fabricante con su
        // propia herramienta, asi que una cadena donde va un numero es un fallo
        // de emision que conviene ver al activar y no al contar.
        if (! \is_int($value)) {
            throw InvalidLicenseKey::fieldNotInteger($field);
        }

        return $value;
    }

    /**
     * Un instante ISO-8601 **en UTC** (regla dura 3).
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidLicenseKey
     */
    private static function instant(array $claims, string $field): DateTimeImmutable
    {
        $raw = self::text($claims, $field);

        // Solo la forma canonica con sufijo Z. Aceptar un desfase explicito
        // convertiria la caducidad de una licencia en algo que depende de la
        // zona con la que se emitio, y con una vigencia que empieza y acaba a
        // medianoche eso es un dia de diferencia.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $raw) !== 1) {
            throw InvalidLicenseKey::fieldNotADate($field);
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $raw, new DateTimeZone('UTC'));

        if ($parsed === false) {
            throw InvalidLicenseKey::fieldNotADate($field);
        }

        // `createFromFormat` acepta el 31 de febrero y lo desplaza a marzo. Una
        // fecha de caducidad desplazada en silencio es exactamente el error que
        // no se descubre hasta que alguien discute una factura.
        if ($parsed->format('Y-m-d\TH:i:s\Z') !== $raw) {
            throw InvalidLicenseKey::fieldNotADate($field);
        }

        return $parsed;
    }

    /**
     * Las funcionalidades accesorias habilitadas.
     *
     * **Un nombre desconocido se descarta**, por lo mismo que un campo
     * desconocido: la 1.6 puede emitir una clave con una funcionalidad que la
     * 1.2 todavia no tiene, y esa clave tiene que poder activarse en la 1.2.
     * Lo que la instalacion no conoce, no lo puede encender.
     *
     * @param  array<string, mixed>  $claims
     * @return list<Feature>
     *
     * @throws InvalidLicenseKey
     */
    private static function features(array $claims): array
    {
        $raw = $claims['features'] ?? throw InvalidLicenseKey::missingField('features');

        if (! \is_array($raw) || ! array_is_list($raw)) {
            throw InvalidLicenseKey::featuresNotAList();
        }

        $granted = [];

        foreach ($raw as $name) {
            if (! \is_string($name)) {
                throw InvalidLicenseKey::featuresNotAList();
            }

            $feature = Feature::tryFrom($name);

            if ($feature instanceof Feature) {
                $granted[] = $feature;
            }
        }

        // En orden de catalogo y sin repetidos, no en el orden de la clave: lo
        // que se persiste, lo que sale en el contrato y lo que imprime
        // `license:show` no deben depender de como ordeno la lista quien emitio.
        return array_values(array_filter(
            Feature::cases(),
            static fn (Feature $feature): bool => \in_array($feature, $granted, true),
        ));
    }
}

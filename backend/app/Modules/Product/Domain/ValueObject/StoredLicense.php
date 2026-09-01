<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Lo que hay guardado en la tabla `license` (doc 01 §5): la clave firmada tal y
 * como se activo, cuando se activo y cuando se verifico por ultima vez.
 *
 * ## La clave se guarda entera y a proposito
 *
 * Es lo unico que permite volver a verificarla despues de un despliegue, de una
 * restauracion de copia o de una rotacion de la clave publica del fabricante.
 * Los campos descompuestos —cliente, plan, limites— tambien estan en la tabla,
 * pero como **proyeccion legible** para consultas y diagnostico: la afirmacion
 * con valor es la firmada, y por eso el estado se recalcula desde
 * {@see self::$signedKey} en cada lectura y no desde las columnas.
 *
 * Si alguien edita `valid_until` con `psql`, la firma deja de cuadrar con la
 * carga util y el estado pasa a `unverifiable` — degradado, nunca detenido. Es
 * el comportamiento correcto para un control comercial (doc 01 §8.1).
 *
 * ## No es un secreto
 *
 * La clave de licencia no abre nada: es una afirmacion firmada sobre lo que el
 * cliente contrato. Aun asi **no se imprime entera** en `license:show` ni en el
 * paquete de diagnostico, porque copiarla a un ticket la difunde sin necesidad y
 * porque lleva el nombre del cliente. Lo que se enseña es su huella corta.
 */
final readonly class StoredLicense
{
    public function __construct(
        public string $signedKey,
        public DateTimeImmutable $activatedAt,
        public ?DateTimeImmutable $lastVerifiedAt,
    ) {}

    /**
     * Huella corta de la clave, para poder decir «es la misma que activaste» sin
     * enseñarla.
     *
     * SHA-256 truncado a 12 caracteres hexadecimales. No protege nada —la clave
     * no es un secreto— y no pretende hacerlo: es un identificador legible por
     * telefono.
     */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->signedKey);
    }

    /**
     * La misma huella, sin necesidad de tener la licencia guardada.
     *
     * **Es el unico sitio donde se calcula.** La activacion la necesita antes de
     * que exista la fila —para el asiento de `audit_log`— y el recurso la
     * necesita despues, y las dos tienen que dar exactamente lo mismo: es el
     * valor con el que alguien confirma por telefono que la clave activada es la
     * que se envio. Dos copias del calculo son dos huellas distintas el dia que
     * alguien cambie el algoritmo o la longitud en un solo sitio.
     */
    public static function fingerprintOf(string $signedKey): string
    {
        return substr(hash('sha256', $signedKey), 0, 12);
    }
}

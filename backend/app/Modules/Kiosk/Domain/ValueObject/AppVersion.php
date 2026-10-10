<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use App\Modules\Kiosk\Domain\Policy\AppVersionPolicy;

/**
 * Una version de la aplicacion con forma SemVer 2.0: la que declara un quiosco
 * en su latido (`app_version`) o la que el servidor tiene desplegada (DC8).
 *
 * ## Solo se construye desde texto, y puede no construirse
 *
 * {@see self::tryParse()} devuelve `null` para todo lo que no sea SemVer, y es
 * la unica entrada: no hay forma de tener en la mano una `AppVersion` con un
 * numero negativo o un componente vacio. **Quien decide que significa un
 * `null`** es {@see AppVersionPolicy}, no este
 * objeto.
 *
 * ## Lo que se conserva y lo que se tira
 *
 * - **El nucleo `X.Y.Z`**, que es lo unico que se compara (ver la politica).
 * - **La etiqueta de prerelease** (`-dev`, `-ci`, `-rc.1`), porque una de ellas
 *   —`dev`— cambia el significado de la version del servidor.
 * - **Los metadatos de build** (`+sha.abc`) se aceptan y se descartan: SemVer
 *   §10 dice que no participan en la precedencia, y dos builds del mismo commit
 *   no pueden estar uno «desfasado» respecto del otro.
 *
 * Los numeros sin ceros a la izquierda (SemVer §2) y de nueve cifras como
 * mucho: el campo del latido admite 32 caracteres y un componente mas largo no
 * es una version real, sino un entero que desbordaria la comparacion.
 */
final readonly class AppVersion
{
    private const string PATTERN = '/^(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})'
        .'(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?'
        .'(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    /** El primer identificador de prerelease que marca un build de desarrollo. */
    private const string DEVELOPMENT_TAG = 'dev';

    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        /** Sin el guion; `null` si la version no lleva etiqueta de prerelease. */
        public ?string $prerelease,
    ) {}

    /**
     * La version, o `null` si el texto no es SemVer (vacio y `null` incluidos).
     *
     * Sin `trim()`: el latido valida el campo sin espacios y el puerto del
     * servidor entrega la version ya recortada. Un espacio aqui es un dato
     * corrupto, no una version.
     */
    public static function tryParse(?string $value): ?self
    {
        if ($value === null || preg_match(self::PATTERN, $value, $parts) !== 1) {
            return null;
        }

        return new self(
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[3],
            ($parts[4] ?? '') === '' ? null : $parts[4],
        );
    }

    /**
     * `0.0.0`, con o sin sufijo: «no se que version soy».
     *
     * Es el respaldo de `DeployedVersion` en el servidor y el de `vite.config.ts`
     * (`0.0.0-dev`) en el quiosco, y lo que declaraba la 2.1.0 por leer su
     * `package.json`. Ninguna version publicada lo es.
     */
    public function isUnknown(): bool
    {
        return $this->major === 0 && $this->minor === 0 && $this->patch === 0;
    }

    /**
     * Prerelease cuyo primer identificador es exactamente `dev`
     * (`2.2.1-dev`, `2.2.1-dev.3`). `-devel` o `-ci` no lo son.
     */
    public function isDevelopmentBuild(): bool
    {
        return $this->prerelease !== null
            && explode('.', $this->prerelease)[0] === self::DEVELOPMENT_TAG;
    }

    /** `<0`, `0` o `>0` comparando solo `X.Y.Z`. */
    public function compareCore(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    /** `X.Y.Z`, sin prerelease ni build. */
    public function core(): string
    {
        return $this->major.'.'.$this->minor.'.'.$this->patch;
    }
}

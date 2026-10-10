<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\Policy;

use App\Modules\Kiosk\Domain\ValueObject\AppVersion;
use App\Modules\Kiosk\Domain\ValueObject\AppVersionStanding;

/**
 * Cuando esta desfasada la aplicacion de un quiosco (RF-KI-07, RF-PA-07;
 * bloque 1 de la 2.2.1).
 *
 * ## La version minima es la del servidor
 *
 * Quiosco y servidor salen del mismo fichero `VERSION` (DC8) y de la misma
 * etiqueta: la PWA que sirve Nginx es la de la version desplegada. Una tablet
 * que declara otra cosa es una tablet cuyo *service worker* no ha recogido el
 * build nuevo. No hay un segundo numero que configurar: una «version minima»
 * distinta de la desplegada seria otra opinion sobre que build es el bueno.
 *
 * ## La regla, completa
 *
 * **Lado del servidor** — decide si hay con que comparar:
 *
 * 1. No SemVer, o nucleo `0.0.0` (el «no se» de `DeployedVersion`) → `Unchecked`.
 * 2. Prerelease `dev` (`2.2.1-dev`, `0.0.0-dev`, el `ARG APP_VERSION` por
 *    defecto del Dockerfile) → `Unchecked`. Un servidor de desarrollo no tiene
 *    una version publicada que exigir, y avisar ahi marcaria como desfasadas
 *    todas las tablets de un entorno de pruebas: un aviso que siempre esta
 *    encendido enseña a ignorarlo.
 * 3. Cualquier otro prerelease (`-ci`, `-rc.1`) **si se compara**: es la
 *    version que la CI construye para probar la actualizacion, y es justo donde
 *    el aviso tiene que verse. Esto **parte de que la CI construye la PWA con
 *    el mismo `APP_VERSION` que el servidor** (`2.2.2-ci` los dos): asi un
 *    quiosco de la CI recien construido sale `Current` y solo la tablet que se
 *    quedo en el build anterior sale `Behind`. Si la PWA de la CI se
 *    construyera con otra version, la comparacion de la CI no probaria nada.
 *
 * **Lado del quiosco**, con un servidor comparable:
 *
 * 4. `null`, vacia, no SemVer o nucleo `0.0.0` → `Behind`. La `0.0.0` es lo
 *    que declaraba la 2.1.0 y la `null` una tablet que nunca dijo su version:
 *    las dos necesitan el build nuevo.
 * 5. Si no, se compara **solo el nucleo `X.Y.Z`**: menor → `Behind`, mayor →
 *    `Ahead` (vuelta atras del servidor, ADR-054), igual → `Current`.
 *
 * ## Por que solo el nucleo
 *
 * Con la precedencia SemVer completa, `2.2.2-ci` < `2.2.2` y `2.2.1-dev` <
 * `2.2.1`: un quiosco de desarrollo contra un servidor publicado saldria
 * desfasado sin estarlo, y un servidor `-rc` veria «por delante» a la tablet
 * publicada. Lo que este aviso detecta —una tablet que se quedo en el build
 * de la version anterior— siempre cambia el nucleo, porque una version
 * publicada es inmutable (ADR-053) y la siguiente sube al menos el parche. Lo
 * que se pierde (dos builds del mismo nucleo con distinto prerelease) no existe
 * en una instalacion de cliente.
 *
 * ## Pura
 *
 * Recibe la version del servidor ya resuelta: la entrega el puerto
 * `Shared\Application\Port\DeployedVersionProvider` al caso de uso, y este
 * construye la politica (regla dura 1). Sin estado y sin reloj.
 */
final readonly class AppVersionPolicy
{
    private function __construct(private ?AppVersion $minimum) {}

    /**
     * @param  string|null  $deployed  La version del servidor tal y como la entrega `DeployedVersionProvider`.
     */
    public static function forDeployed(?string $deployed): self
    {
        $version = AppVersion::tryParse($deployed);

        if ($version === null || $version->isUnknown() || $version->isDevelopmentBuild()) {
            return new self(null);
        }

        return new self($version);
    }

    /** Si hay version del servidor con la que comparar (reglas 1 a 3). */
    public function isEnforced(): bool
    {
        return $this->minimum !== null;
    }

    /**
     * La version minima que se anuncia a la tablet (`minimum_app_version` del
     * latido): el nucleo `X.Y.Z` del servidor, sin prerelease ni build, porque
     * es lo unico que la regla compara. `null` si no se aplica, y entonces el
     * campo se omite (regla dura 19: nunca tumba el latido).
     */
    public function minimumAppVersion(): ?string
    {
        return $this->minimum?->core();
    }

    /**
     * @param  string|null  $declared  La `app_version` del ultimo latido del quiosco.
     */
    public function standingOf(?string $declared): AppVersionStanding
    {
        if ($this->minimum === null) {
            return AppVersionStanding::Unchecked;
        }

        $version = AppVersion::tryParse($declared);

        if ($version === null || $version->isUnknown()) {
            return AppVersionStanding::Behind;
        }

        return match ($version->compareCore($this->minimum) <=> 0) {
            -1 => AppVersionStanding::Behind,
            1 => AppVersionStanding::Ahead,
            default => AppVersionStanding::Current,
        };
    }

    /** Atajo de lo unico que pide accion: `standingOf() === Behind`. */
    public function isBehind(?string $declared): bool
    {
        return $this->standingOf($declared) === AppVersionStanding::Behind;
    }
}

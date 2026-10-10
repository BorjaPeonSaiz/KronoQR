<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\Port\KioskAppVersions;
use Throwable;

/**
 * Sonda `kiosk.app_version` de `product:doctor` (**RF-PD-13**, RF-KI-07;
 * bloque 1 de la 2.2.1).
 *
 * ## Que responde
 *
 * «¿Hay alguna tablet que siga con la aplicacion de la version anterior?».
 * Quiosco y servidor salen del mismo `VERSION` (DC8): una tablet que declara
 * otra version mas antigua corre una PWA que su *service worker* no ha
 * renovado. `update.sh` llama a `doctor` justo despues de actualizar, y es ahi
 * donde el IT del cliente tiene que enterarse, con el `uuid` de cada tablet
 * para encontrarla en el panel.
 *
 * ## **Aviso, nunca fallo**
 *
 * Un `failure` devolveria `2` y `update.sh` lo traduce a su codigo `6`: **una
 * actualizacion se abortaria precisamente porque las tablets aun no se han
 * puesto al dia**, que es lo normal en los minutos siguientes. La tablet sigue
 * fichando y encolando con cualquier version (regla dura 19); la sonda avisa
 * y dice que hacer.
 *
 * ## Los tres casos que no piden nada
 *
 * - **Por delante** (una vuelta atras del servidor, ADR-054): `ok` con su
 *   frase. Se informa, pero la tablet se pondra en la version del servidor
 *   sola y no hay nada que hacer.
 * - **Servidor sin version comparable** (`0.0.0` o un build `-dev`): `ok` con
 *   su frase y ningun juicio. Un entorno de desarrollo marcaria como
 *   desfasadas todas sus tablets.
 * - **Sin quioscos latiendo**: `ok`. Una tablet callada ya sale en
 *   `kiosk:health` y en el panel; su version es lo de menos.
 *
 * ## `details` sin datos personales
 *
 * Este informe viaja en el paquete de diagnostico (ADR-020, regla dura 21): el
 * `uuid` de cada dispositivo y la version que declara, **nunca su nombre** —que
 * lo pone el cliente y puede ser «Tablet de Maria»—.
 *
 * ## Nunca lanza
 *
 * Si la lectura falla —la base de datos caida— sale un aviso con la clase de
 * la excepcion y las demas sondas siguen. `doctor` se ejecuta cuando algo esta
 * roto, y una sonda que revienta es inutil justo entonces.
 */
final readonly class KioskVersionProbe implements DoctorProbe
{
    private const string ID = 'kiosk.app_version';

    public function __construct(private KioskAppVersions $versions) {}

    public function family(): string
    {
        return 'kiosk';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        try {
            $survey = $this->versions->survey();
        } catch (Throwable $failure) {
            // La CLASE y nunca el mensaje: un error de PostgreSQL puede llevar
            // dentro el valor de una fila (regla dura 21).
            return [DoctorFinding::warning(
                self::ID,
                'unavailable',
                params: ['failure' => $failure::class],
                details: ['failure' => $failure::class],
            )];
        }

        if (! $survey->isEnforced()) {
            return [new DoctorFinding(self::ID, DoctorStatus::Ok, details: ['enforced' => false], variant: 'unchecked')];
        }

        $details = [
            'enforced' => true,
            'minimum_app_version' => $survey->minimumAppVersion,
            'examined' => $survey->examined,
            'behind' => $this->listed($survey->behind),
            'ahead' => $this->listed($survey->ahead),
        ];

        if ($survey->behind !== []) {
            return [DoctorFinding::warning(self::ID, params: [
                'count' => \count($survey->behind),
                'minimum' => (string) $survey->minimumAppVersion,
                'devices' => $this->phrase($survey->behind),
            ], details: $details)];
        }

        if ($survey->ahead !== []) {
            return [new DoctorFinding(self::ID, DoctorStatus::Ok, params: [
                'count' => \count($survey->ahead),
                'minimum' => (string) $survey->minimumAppVersion,
                'devices' => $this->phrase($survey->ahead),
            ], details: $details, variant: 'ahead')];
        }

        return [DoctorFinding::ok(self::ID, $details, [
            'count' => $survey->examined,
            'minimum' => (string) $survey->minimumAppVersion,
        ])];
    }

    /**
     * @param  array<string, string|null>  $devices
     * @return list<array{device: string, app_version: string|null}>
     */
    private function listed(array $devices): array
    {
        $listed = [];

        foreach ($devices as $uuid => $version) {
            $listed[] = ['device' => $uuid, 'app_version' => $version];
        }

        return $listed;
    }

    /**
     * `uuid (version)` separados por coma. Una version que la tablet no declaro
     * sale como `-`: es un dato, no una frase, y no hace falta traducirlo.
     *
     * @param  array<string, string|null>  $devices
     */
    private function phrase(array $devices): string
    {
        $parts = [];

        foreach ($devices as $uuid => $version) {
            $parts[] = \sprintf('%s (%s)', $uuid, $version ?? '-');
        }

        return implode(', ', $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\AccessHardeningFacts;
use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\Port\PinLengthProvider;
use App\Modules\Shared\Domain\ValueObject\PinLength;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use ValueError;

/**
 * Sondas `access.*` de `product:doctor`: como de cerrada esta la puerta del
 * portal y del panel (ADR-050, RF-ID-09, RS-12; RF-PD-13).
 *
 * ## La pregunta que responde
 *
 * «Con la red que he decidido, ¿la autenticacion esta a la altura?» El portal
 * entra con codigo de empleado y PIN. En la red del hotel, 6 cifras con el
 * bloqueo por empleado bastan (ADR-015); abierto a internet, quien rota
 * direcciones acierta un PIN de 6 cada pocos meses y uno de 8 en decenas de
 * años (ADR-050, residuo 1). Ninguna de las dos cosas rompe nada, y por eso
 * **todo lo de esta sonda es aviso como mucho**: abrir el portal y elegir la
 * longitud son decisiones del cliente, y un fallo (`2`) abortaria `update.sh`
 * por algo que no esta roto.
 *
 * ## Por que es una sonda aparte de {@see EdgeNetworksProbe}
 *
 * Esta lee la base de datos (el ajuste y los recuentos) y aquella no. Si fueran
 * una, una base de datos caida se llevaria por delante los avisos de red, que
 * son justo los que no dependen de ella. La clasificacion del portal si es
 * comun: {@see EdgeNetworksProbe::portalCategory()}.
 *
 * ## Solo cifras (regla dura 21, ADR-020)
 *
 * Los recuentos son numeros y nada mas: ni nombres, ni codigos de empleado, ni
 * UUID. El informe viaja al fabricante, y decir quien conserva el PIN corto es
 * decir a quien atacar.
 */
final readonly class AccessHardeningProbe implements DoctorProbe
{
    /**
     * Los roles que escriben o leen el registro de toda la plantilla o de un
     * departamento, y que por eso llevan segundo factor de serie (RS-06,
     * ADR-050 §5). El responsable de departamento entra por `attendance:correct`:
     * con su contraseña sola se rehace la nomina de un departamento.
     *
     * @var list<UserRole>
     */
    public const array SECOND_FACTOR_ROLES = [
        UserRole::ADMIN,
        UserRole::RRHH,
        UserRole::AUDITOR,
        UserRole::RESPONSABLE_DEPARTAMENTO,
    ];

    /**
     * @param  list<string>  $secondFactorRoles  `IDENTITY_2FA_REQUIRED_ROLES` tal como llega de
     *                                           la configuracion: un nombre que no es un rol se ignora, igual que
     *                                           hace `Identity` al aplicarla.
     */
    public function __construct(
        private PinLengthProvider $pinLengthProvider,
        private AccessHardeningFacts $facts,
        private string $portalInternal,
        private array $secondFactorRoles = [],
    ) {}

    public function family(): string
    {
        return 'access';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $length = $this->pinLength();

        $required = $this->requiredRoles();

        return [
            $this->pinLengthForExposure($length),
            $this->shortPins($length),
            $this->missingSecondFactorRoles($required),
            $this->accountsPendingSecondFactor($required),
        ];
    }

    /**
     * @return list<UserRole>
     */
    private function requiredRoles(): array
    {
        $roles = [];

        foreach ($this->secondFactorRoles as $name) {
            $role = UserRole::tryFrom(trim($name));

            if ($role instanceof UserRole && ! \in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Un aviso si falta alguno de los cuatro roles de gestion en
     * `IDENTITY_2FA_REQUIRED_ROLES` (ADR-050 §5). Acortar la lista es
     * configuracion legitima (regla dura 13), pero tiene que verse.
     *
     * @param  list<UserRole>  $required
     */
    private function missingSecondFactorRoles(array $required): DoctorFinding
    {
        $id = 'access.two_factor_roles';
        $missing = array_values(array_map(
            static fn (UserRole $role): string => $role->value,
            array_filter(
                self::SECOND_FACTOR_ROLES,
                static fn (UserRole $role): bool => ! \in_array($role, $required, true),
            ),
        ));

        if ($missing === []) {
            return DoctorFinding::ok($id, ['missing_roles' => []]);
        }

        return DoctorFinding::warning(
            $id,
            params: ['roles' => implode(', ', $missing)],
            details: ['missing_roles' => $missing],
        );
    }

    /**
     * Cuantas cuentas activas de los roles obligados no tienen aun segundo
     * factor confirmado (M1 de la revision de ADR-050).
     *
     * Tras actualizar, cada responsable sin TOTP lo da de alta en su primer
     * acceso, y hasta entonces **la ventana de auto-alta esta abierta**: quien
     * tenga solo su contraseña puede quedarse con el segundo factor (doc 07 §6).
     * Solo el numero: quien es lo ve el administrador en su panel.
     *
     * @param  list<UserRole>  $required
     */
    private function accountsPendingSecondFactor(array $required): DoctorFinding
    {
        $id = 'access.two_factor_pending';
        $count = $this->facts->activeAccountsWithoutSecondFactor($required);

        if ($count === 0) {
            return DoctorFinding::ok($id, ['pending_accounts' => 0]);
        }

        return DoctorFinding::warning($id, params: ['count' => $count], details: ['pending_accounts' => $count]);
    }

    /**
     * La longitud con la que se emiten los PIN, **del mismo puerto que usa el
     * generador** (`PinLengthProvider`): la sonda no puede contar una longitud
     * distinta de la que de verdad se emite.
     *
     * El generador deja subir un valor ilegible —es mejor un `500` en el panel
     * que emitir un PIN de una longitud que nadie eligio—; la sonda no: un
     * `doctor` que se cae no avisa de nada, y el valor ilegible ya lo denuncia
     * `settings.invalid_keys`. Aqui se toma 6, la longitud de serie, y se sigue.
     */
    private function pinLength(): PinLength
    {
        try {
            return $this->pinLengthProvider->current();
        } catch (ValueError) {
            return PinLength::SIX;
        }
    }

    /**
     * Portal alcanzable desde internet con PIN de 6 cifras: aviso que recomienda
     * 8 (ADR-050 §3). Con el portal en una red privada, 6 es lo correcto y no
     * se dice nada mas.
     */
    private function pinLengthForExposure(PinLength $length): DoctorFinding
    {
        $id = 'access.pin_length';
        $exposed = \in_array(
            EdgeNetworksProbe::portalCategory($this->portalInternal),
            EdgeNetworksProbe::EXPOSED_PORTAL_CATEGORIES,
            true,
        );
        $details = ['pin_length' => $length->value, 'portal_exposed' => $exposed];

        if (! $exposed) {
            return DoctorFinding::ok($id, $details, ['length' => $length->value]);
        }

        if ($length === PinLength::EIGHT) {
            return new DoctorFinding($id, DoctorStatus::Ok, ['length' => $length->value], $details, 'exposed');
        }

        return DoctorFinding::warning($id, params: ['length' => $length->value], details: $details);
    }

    /**
     * Con el ajuste en 8, cuantas personas en alta conservan un PIN de 6.
     *
     * Siguen valiendo hasta que se restablecen (ADR-050 §1, «Transicion»): no
     * esta roto, pero mientras queden el residuo de PP-09 sigue ahi, y RRHH
     * necesita saber cuantos restablecimientos le faltan.
     */
    private function shortPins(PinLength $length): DoctorFinding
    {
        $id = 'access.short_pins';

        if ($length === PinLength::SIX) {
            return new DoctorFinding($id, DoctorStatus::Ok, [], ['pin_length' => $length->value], 'six');
        }

        $count = $this->facts->activeEmployeesWithPinOf(PinLength::SIX);

        if ($count === 0) {
            return DoctorFinding::ok($id, ['short_pins' => 0]);
        }

        return DoctorFinding::warning($id, params: ['count' => $count], details: ['short_pins' => $count]);
    }
}

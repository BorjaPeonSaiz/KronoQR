<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `database.audit_chain` de `doctor` (R4-PL-03, RF-PD-13, RS-07). La sonda
 * solo mira el ultimo cierre anual de `audit_log`; sin ninguno —lo normal el
 * primer año— no ha comprobado nada y no puede decir «encadenado
 * correctamente». Sigue en verde: no es un aviso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
});

function cadenaDoctorComprobacion(string $locale): DoctorCheck
{
    $checks = array_values(array_filter(
        app(RunDoctorHandler::class)->handle($locale)->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'database.audit_chain',
    ));

    expect($checks)->toHaveCount(1);

    return $checks[0];
}

it('sin ningun cierre anual sigue en verde pero no afirma que la cadena este bien', function (string $locale, string $dice, string $noDice): void {
    expect(DB::table('audit_chain_anchors')->count())->toBe(0);

    $check = cadenaDoctorComprobacion($locale);

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->details)->toBe(['status' => 'no_anchor'])
        ->and($check->summary)->toContain($dice)
        ->and($check->summary)->toContain('compliance:verify-audit-chain')
        ->and($check->summary)->not->toContain($noDice)
        ->and($check->fix)->toBeNull();
})->with([
    'es' => ['es', 'Todavia no hay ningun cierre anual', 'encadenado correctamente'],
    'en' => ['en', 'There is no yearly seal of the audit log to check yet', 'correctly chained'],
])->group('RF-PD-13', 'RS-07');

it('con el ultimo cierre verificado dice que año comprobo y que no recorrio la cadena', function (string $locale, string $dice): void {
    $text = __('doctor.checks.database.audit_chain.ok', ['year' => 2025], $locale);

    expect($text)->toBeString()
        ->and($text)->toContain($dice)
        ->and($text)->toContain('compliance:verify-audit-chain')
        ->and($text)->not->toContain(':year');
})->with([
    'es' => ['es', 'El ultimo cierre anual del registro de auditoria (2025) cuadra'],
    'en' => ['en', 'The last yearly seal of the audit log (2025) adds up'],
])->group('RF-PD-13', 'RS-07');

<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **UNA FICHA MINIMIZADA NO SE READMITE NI SE CORRIGE** (RN-27, ADR-060,
 * ADR-062 §6 y §7).
 *
 * «Lucia» trabajo de 2010 a 2012, volvio en 2013 y se fue en junio de 2015. Su
 * plazo de conservacion vencio y la ficha se minimizo. Ni se le da de alta de
 * nuevo ni se corrige el cese de ninguno de sus dos periodos: `409`
 * `employee-record-minimised`, el mismo `type` que un contrato o una ausencia
 * nuevos, y nada escrito.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Una persona con dos periodos cerrados, ya minimizada por retencion.
 *
 * @return array{uuid: string, id: int, first: string}
 */
function fichaMinimizadaConDosPeriodos(): array
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site(), status: 'terminated', hiredAt: '2010-01-01', terminatedAt: '2012-12-31');
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');
    \assert(is_int($id));

    // La readmision de 2013 y la baja de 2015, como las escribe el repositorio.
    DB::update("UPDATE employees SET status = 'active', hired_at = '2013-03-01', terminated_at = NULL WHERE id = ?", [$id]);
    DB::insert("INSERT INTO employment_periods (uuid, employee_id, hired_on) VALUES (gen_random_uuid(), ?, '2013-03-01')", [$id]);
    DB::update("UPDATE employees SET status = 'terminated', terminated_at = '2015-06-30' WHERE id = ?", [$id]);
    DB::update("UPDATE employment_periods SET terminated_on = '2015-06-30', offboarded_at = '2015-07-01 00:00:00+00' WHERE employee_id = ? AND offboarded_at IS NULL", [$id]);

    /** @var object{today: string} $hoy */
    $hoy = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date - interval '4 years')::date::text AS today");

    DB::select(
        'SELECT * FROM public.personnel_record_anonymize_batch(?::date, 10, ARRAY(SELECT c.id FROM public.personnel_record_retention_candidates(?::date) c))',
        [$hoy->today, $hoy->today],
    );

    /** @var string $first */
    $first = DB::table('employment_periods')->where('employee_id', $id)->orderBy('hired_on')->value('uuid');

    return ['uuid' => $uuid, 'id' => $id, 'first' => $first];
}

it('responde 409 employee-record-minimised a la readmision de una ficha minimizada y no escribe nada', function (): void {
    $ficha = fichaMinimizadaConDosPeriodos();

    expect(DB::table('employees')->where('id', $ficha['id'])->value('anonymized_at'))->not->toBeNull();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees/'.$ficha['uuid'].'/rehire', ['hired_at' => '2026-10-01'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:employee-record-minimised');

    expect(DB::table('employment_periods')->where('employee_id', $ficha['id'])->count())->toBe(2)
        ->and(DB::table('employees')->where('id', $ficha['id'])->value('status'))->toBe('terminated')
        ->and(DB::table('audit_log')->where('action', 'employee.rehired')->count())->toBe(0);
})->group('RN-27', 'RL-11');

it('responde 409 employee-record-minimised a la correccion del cese de un periodo que no es el en curso', function (): void {
    $ficha = fichaMinimizadaConDosPeriodos();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees/'.$ficha['uuid'].'/employment-periods/'.$ficha['first'].'/termination-corrections', [
            'terminated_at' => '2012-12-20',
            'reason' => 'El cuadrante firmado dice el 20 de diciembre',
        ])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:employee-record-minimised');

    expect(DB::table('employment_periods')->where('uuid', $ficha['first'])->value('terminated_on'))->toBe('2012-12-31')
        ->and(DB::table('employment_period_corrections')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'employee.termination_date_corrected')->count())->toBe(0);
})->group('RN-27', 'RL-11');

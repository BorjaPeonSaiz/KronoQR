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
 * **EL AUTOR MINIMIZADO DE UNA CORRECCION DEL CESE** (RN-13, RN-27, ADR-062
 * §7, ADR-060 §2; esquema `TerminationCorrectionAuthor`).
 *
 * La correccion del cese no conserva la cuenta que la firmo. Una cuenta de RRHH
 * dada de baja en 2020, sin asientos vivos, se minimiza con la regla general.
 * La ficha de la persona cuya fecha de cese corrigio sigue enseñando la
 * correccion, con el `uuid` de la autora, sin nombre y marcada como
 * minimizada; nunca con un nombre vacio que parezca un dato.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.two_factor.required_roles', []);
});

it('enseña la correccion con la autora minimizada: uuid, sin nombre y minimised', function (): void {
    $autora = ManagementUsers::withRole(UserRole::RRHH);
    DB::table('users')->where('id', $autora->id)->update(['is_active' => false, 'deactivated_at' => '2020-01-15 10:00:00+00']);

    $persona = WorkforceFixtures::employee(WorkforceFixtures::site(), status: 'terminated', hiredAt: '2010-01-01', terminatedAt: '2012-12-31');
    $periodo = DB::table('employment_periods')
        ->join('employees', 'employees.id', '=', 'employment_periods.employee_id')
        ->where('employees.uuid', $persona)
        ->value('employment_periods.id');

    // La correccion, sin su asiento: la particion de su año ya se solto.
    DB::table('employment_period_corrections')->insert([
        'employment_period_id' => $periodo,
        'previous_terminated_on' => '2012-12-20',
        'terminated_on' => '2012-12-31',
        'reason' => 'Trabajo hasta el 31 segun el cuadrante',
        'corrected_by_user_id' => $autora->id,
        'corrected_at' => '2013-01-10 09:00:00+00',
    ]);

    /** @var object{today: string} $corte */
    $corte = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date - interval '4 years')::date::text AS today");
    DB::select(
        'SELECT * FROM public.management_account_anonymize_batch(?::date, 10, ARRAY(SELECT c.id FROM public.management_account_retention_candidates(?::date) c))',
        [$corte->today, $corte->today],
    );

    expect(DB::table('users')->where('id', $autora->id)->value('anonymized_at'))->not->toBeNull();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/employees/'.$persona)
        ->assertValidResponse(200)
        ->assertJsonPath('employment_periods.0.corrections.0.corrected_by', [
            'uuid' => $autora->uuid,
            'name' => null,
            'minimised' => true,
        ])
        ->assertJsonPath('employment_periods.0.corrections.0.previous_terminated_at', '2012-12-20')
        ->assertJsonPath('employment_periods.0.corrections.0.corrected_at', '2013-01-10T09:00:00.000000Z');
})->group('RN-27', 'RN-13', 'RL-11');

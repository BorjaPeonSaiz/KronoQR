<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Una tarjeta por empleado (RF-QR-01..03, RF-QR-06).
 *
 * Lo que se siembra es el **hash** del token, no el token: quien lea esta tabla
 * no puede fabricar una tarjeta valida (regla dura 10). El QR de desarrollo se
 * genera en la tarea 1.10, que es la que imprime.
 *
 * La credencial del empleado de baja nace **revocada**, que es lo que exige
 * RN-14: su historial se conserva y sus escaneos se rechazan. Es tambien lo que
 * hace que `one_active_credential_per_employee` tenga algo que demostrar.
 *
 * `key_id` es el de la clave activa del Anexo B del doc 02 (`a3`). Existe para
 * poder rotar la firma sin reimprimir 600 tarjetas.
 */
final class CredentialSeeder extends Seeder
{
    private const string SIGNING_KEY_ID = 'a3';

    public function run(): void
    {
        $now = (string) now();

        /** @var object{id: int}|null $issuer */
        $issuer = DB::table('users')->select('id')->where('email', 'rrhh@kronoqr.test')->first();

        DB::table('employees')
            ->select(['id', 'employee_code', 'status'])
            ->orderBy('id')
            ->chunk(500, function ($employees) use ($now, $issuer): void {
                $rows = [];

                foreach ($employees as $employee) {
                    /** @var object{id: int, employee_code: string, status: string} $employee */
                    $revoked = $employee->status === 'terminated';

                    $rows[] = [
                        // NOT NULL desde la migracion de la tarea 1.5
                        // (`add_public_uuid_to_credentials_table`): sin esto,
                        // `migrate:fresh --seed` rompe con 23502.
                        'uuid' => (string) Str::uuid(),
                        'employee_id' => $employee->id,
                        'key_id' => self::SIGNING_KEY_ID,
                        'secret_hash' => hash('sha256', 'kronoqr-seed-credential-'.$employee->employee_code),
                        'issued_at' => $now,
                        'printed_at' => $now,
                        'delivered_at' => $now,
                        'delivered_by_user_id' => $issuer?->id,
                        'revoked_at' => $revoked ? $now : null,
                        // RN-14: la baja revoca la credencial. El motivo es
                        // obligatorio (`credentials_chk_revocation_has_reason`).
                        'revoked_reason' => $revoked ? 'employee_terminated' : null,
                    ];
                }

                DB::table('credentials')->insertOrIgnore($rows);
            });
    }
}

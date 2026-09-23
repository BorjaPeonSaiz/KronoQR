<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\WeeklySummaryRecipient;
use App\Modules\Reporting\Application\Port\WeeklySummaryRecipients;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * Los destinatarios del resumen semanal, leidos de la base de datos
 * (**RF-PR-05**, **RF-ID-03**, decision 2 de la ficha 3.12).
 *
 * ## Quien entra, en una frase
 *
 * Las cuentas **activas, con direccion, con el rol `responsable_departamento` y
 * sin ningun rol de alcance global**.
 *
 * Las dos primeras condiciones son obvias; las dos ultimas no, y son las que
 * sostienen la decision 2:
 *
 * - **Con el rol, no «quien dirige un departamento».** Podria parecer lo mismo
 *   —el alcance sale de `departments.manager_user_id`— y no lo es: una cuenta de
 *   RRHH a la que ademas se le asigna un departamento seguiria siendo de alcance
 *   global, y derivar la lista de la tabla de departamentos le habria mandado un
 *   correo con un alcance que no es el suyo.
 * - **Sin rol global.** `admin`, `rrhh` y `auditor` tienen el panel entero, y un
 *   correo semanal con toda la plantilla seria una copia periodica del registro
 *   **fuera** del sistema, reenviable y sin control de acceso (minimizacion,
 *   RGPD art. 5.1.c). El orden es ademas el mismo que aplica `User::accessScope()`
 *   —el rol global gana sobre el de departamento—, asi que quien tenga los dos
 *   no recibe un correo acotado a un departamento que en el panel no lo acota.
 *
 * ## El alcance se lee de la misma tabla que el del panel
 *
 * `departments.manager_user_id`, igual que `User::accessScope()`. Si se leyera de
 * otro sitio, el correo y la pantalla podrian acabar diciendo cosas distintas de
 * la misma cuenta, y la que no se ve es la del correo.
 *
 * Una cuenta con el rol y **sin ningun departamento asignado** entra en la lista
 * con un alcance que no alcanza a nadie: es un estado legitimo —un responsable
 * existe antes de que se le asigne el primero— y quien decide que no se le manda
 * nada es el caso de uso, no esta consulta.
 *
 * ## Dos consultas y no un `JOIN`
 *
 * Con el `JOIN` habria una fila por cuenta y departamento, y componer el alcance
 * exigiria agrupar en PHP igualmente. Son dos consultas sobre tablas de decenas
 * de filas, una vez por semana.
 *
 * **No se trae la cuenta entera** (mismo criterio que
 * {@see DatabaseReportExportRecipients}): mandar un correo no justifica poner en
 * memoria el hash de la contraseña ni el secreto de 2FA de nadie. Y ningun
 * nombre sale de aqui: el correo lo compone la notificacion con los nombres de
 * la plantilla, no con el de quien lo recibe.
 */
final readonly class DatabaseWeeklySummaryRecipients implements WeeklySummaryRecipients
{
    public function __construct(private ConnectionInterface $connection) {}

    public function active(): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT u.id,
                   u.email,
                   u.locale
              FROM users u
             WHERE u.is_active = true
               AND u.email IS NOT NULL
               AND u.email <> ''
               AND EXISTS (
                     SELECT 1
                       FROM model_has_roles mhr
                       JOIN roles r ON r.id = mhr.role_id
                      WHERE mhr.model_id = u.id
                        AND r.name = ?
                   )
               AND NOT EXISTS (
                     SELECT 1
                       FROM model_has_roles mhr
                       JOIN roles r ON r.id = mhr.role_id
                      WHERE mhr.model_id = u.id
                        AND r.name IN (?, ?, ?)
                   )
             ORDER BY u.id
            SQL, [
            UserRole::RESPONSABLE_DEPARTAMENTO->value,
            UserRole::ADMIN->value,
            UserRole::RRHH->value,
            UserRole::AUDITOR->value,
        ]);

        if ($rows === []) {
            return [];
        }

        $accounts = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);
            $locale = $reader->string('locale');

            $accounts[] = [
                'id' => $reader->int('id'),
                'email' => $reader->string('email'),
                // Una cuenta sin idioma guardado —posible en una fila antigua—
                // recibe el correo en español, que es el idioma por omision del
                // producto (`users.locale` lo declara asi en su migracion).
                'locale' => $locale === '' ? 'es' : $locale,
            ];
        }

        $departments = $this->departmentsOf(array_column($accounts, 'id'));

        return array_map(
            static fn (array $account): WeeklySummaryRecipient => new WeeklySummaryRecipient(
                userId: $account['id'],
                email: $account['email'],
                locale: $account['locale'],
                scope: AccessScope::forDepartments(...($departments[$account['id']] ?? [])),
            ),
            $accounts,
        );
    }

    /**
     * Los departamentos que dirige cada cuenta, indexados por cuenta.
     *
     * @param  list<int>  $managerIds
     * @return array<int, list<int>>
     */
    private function departmentsOf(array $managerIds): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(
            'SELECT d.id, d.manager_user_id'
            .' FROM departments d'
            .' WHERE d.manager_user_id IN ('.implode(', ', array_fill(0, \count($managerIds), '?')).')'
            .' ORDER BY d.id',
            $managerIds,
        );

        /** @var array<int, list<int>> $byManager */
        $byManager = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);
            $byManager[$reader->int('manager_user_id')][] = $reader->int('id');
        }

        return $byManager;
    }
}

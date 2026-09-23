<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\ReportExportRecipient;
use App\Modules\Reporting\Application\Port\ReportExportRecipients;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see ReportExportRecipients} sobre PostgreSQL (**RF-IN-06**).
 *
 * **Tres columnas y una fila.** Mismo criterio y mismo precedente que
 * {@see DatabaseReportIssuerDirectory}: `Reporting` es un modelo de lectura y su
 * fuente es la base de datos, no el agregado de `Identity` (doc 02 §1.6). Un
 * `use` del modelo Eloquent de aquel modulo seria la frontera que Deptrac
 * rechaza.
 *
 * **No se trae la cuenta entera**: enviar un correo no justifica poner en memoria
 * el hash de la contraseña ni el secreto de 2FA de nadie.
 *
 * **Sirve tambien a la cuenta desactivada.** Quien pidio el informe el viernes
 * puede estar de baja el lunes, y el aviso de que su informe termino sigue siendo
 * correcto: lo que decide si esa persona puede descargarlo es el token y la
 * sesion, no este correo.
 */
final readonly class DatabaseReportExportRecipients implements ReportExportRecipients
{
    public function __construct(private ConnectionInterface $connection) {}

    public function find(int $userId): ?ReportExportRecipient
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT u.name,
                   u.email,
                   u.locale
              FROM users u
             WHERE u.id = ?
             LIMIT 1
            SQL, [$userId]);

        if ($rows === []) {
            return null;
        }

        $row = Row::of($rows[0]);
        $locale = $row->string('locale');

        return new ReportExportRecipient(
            name: $row->string('name'),
            email: $row->nullableString('email'),
            // Una cuenta sin idioma guardado —posible en una fila antigua— recibe
            // el correo en español, que es el idioma por omision del producto
            // (`users.locale` lo declara asi en su migracion).
            locale: $locale === '' ? 'es' : $locale,
        );
    }
}

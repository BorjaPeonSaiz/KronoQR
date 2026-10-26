<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Command\GrantSupportAccessCommand;
use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Application\UseCase\GrantSupportAccessHandler;
use App\Modules\Product\Domain\Exception\InvalidSupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportGrantAuthor;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * `php artisan support:grant --hours=24 --reason="Incidencia #123"` (Anexo C del
 * doc 01, **RF-PD-11**, RL-18, ADR-020).
 *
 * ## Es el camino de la incidencia grave
 *
 * Se ejecuta cuando el panel no esta disponible: si el cliente puede entrar al
 * panel, concede desde ahi. Por eso este comando **no depende de nada mas que de
 * la base de datos** — ni de la licencia (regla dura 15), ni de Redis, ni de que
 * el borde HTTP responda.
 *
 * ## El token se imprime UNA vez, con su aviso
 *
 * Y no se guarda: la fila solo tiene su hash. Si se pierde, se revoca la
 * concesion y se crea otra. La salida lo dice con todas las letras, porque quien
 * la lee esta en mitad de una incidencia y no va a suponerlo.
 *
 * ## `--as=` y por que hace falta
 *
 * `granted_by` no admite nulos: autorizar la entrada del fabricante es la firma
 * del encargo del art. 28 RGPD (RL-18), y una firma en blanco no acredita nada.
 * Quien ejecuta este comando tiene acceso al servidor y por tanto ya puede hacer
 * cualquier cosa; `--as=` no autoriza el acto, lo **atribuye**.
 *
 * Con una sola cuenta de gestion activa —la instalacion recien montada— se toma
 * esa y se dice cual. Con varias, **hay que elegir**: atribuir el acto a una
 * cuenta al azar seria poner el nombre de otra persona en el trail.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Acceso concedido. El token esta en la salida. |
 * | `1` | **No se concedio nada**: falta el motivo, las horas no valen, el alcance no existe o no se pudo atribuir a ninguna cuenta. |
 *
 * Dos y no tres: aqui no hay estado intermedio. O hay acceso o no lo hay.
 */
final class SupportGrantCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.support_grant';

    protected $signature = 'support:grant
        {--reason= : Obligatorio. El incidente que motiva el acceso (3 a 200 caracteres, sin nombres, correos, DNI ni telefonos; para una persona, su uuid)}
        {--hours= : Duracion en horas. Por defecto PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS}
        {--scope=diagnostics : diagnostics | read_only | configuration}
        {--as= : Correo de la cuenta de gestion a cuyo nombre se concede}';

    protected $description = 'Concede al fabricante un acceso temporal, limitado, revocable y auditado a esta instalacion';

    public function handle(GrantSupportAccessHandler $grant, SupportGrantRepository $grants): int
    {
        $scope = SupportScope::tryFrom($this->stringOption('scope'));

        if (! $scope instanceof SupportScope) {
            $this->error($this->text('unknown_scope', ['scope' => $this->stringOption('scope')]));
            $this->line($this->text('valid_scopes', ['scopes' => implode(', ', SupportScope::names())]));

            return self::FAILURE;
        }

        // Antes de conceder, el recordatorio de que el motivo va
        // tal cual a la auditoria y no puede llevar datos personales.
        $this->line($this->installationText('support.reason_hint'));

        $author = $this->resolveAuthor($grants);

        if (! $author instanceof SupportGrantAuthor) {
            return self::FAILURE;
        }

        try {
            $issued = $grant->handle(new GrantSupportAccessCommand(
                reason: $this->stringOption('reason'),
                scope: $scope,
                hours: $this->hours(),
                grantedByUserId: $author->id,
            ));
        } catch (InvalidSupportGrant $invalid) {
            $this->error($this->text('not_granted'));
            $this->line($invalid->getMessage());
            // El porque, en el idioma de la instalacion.
            $this->line($this->installationText($invalid->translationKey, $invalid->parameters));
            $this->line('');
            $this->line($this->text('usage'));

            return self::FAILURE;
        }

        $this->info($this->text('granted.title'));
        $this->line($this->text('granted.grant', ['uuid' => $issued->grant->uuid]));
        $this->line($this->text('granted.scope', ['scope' => $scope->value]));
        $this->line($this->text('granted.author', ['name' => $author->name]));
        $this->line($this->text('granted.expires', [
            'at' => $issued->grant->expiresAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
        ]));
        $this->line('');

        // El aviso ANTES del token, no despues: quien copia lo primero que ve
        // pega el token y cierra la terminal.
        foreach ($this->texts('granted.shown_once') as $line) {
            $this->warn($line);
        }

        $this->line('');
        $this->line($issued->token);
        $this->line('');
        $this->paragraph('granted.delivery', ['uuid' => $issued->grant->uuid]);

        return self::SUCCESS;
    }

    /**
     * A nombre de quien se concede. Ver el docblock de la clase.
     */
    private function resolveAuthor(SupportGrantRepository $grants): ?SupportGrantAuthor
    {
        $email = $this->stringOption('as');

        if ($email !== '') {
            $author = $grants->authorByEmail($email);

            if (! $author instanceof SupportGrantAuthor) {
                $this->error($this->text('author.not_found', ['email' => $email]));

                return null;
            }

            return $author;
        }

        $sole = $grants->soleAuthor();

        if (! $sole instanceof SupportGrantAuthor) {
            $this->error($this->text('author.ambiguous.title'));
            $this->paragraph('author.ambiguous.why');
            $this->line('');
            $this->line($this->text('author.ambiguous.usage'));

            return null;
        }

        $this->line($this->text('author.sole', ['name' => $sole->name]));

        return $sole;
    }

    private function hours(): int
    {
        $option = $this->option('hours');

        // `is_numeric` y no `is_string`: por la terminal siempre llega texto,
        // pero `Artisan::call()` —que es como lo invoca el instalador y como lo
        // invocan las pruebas— pasa el entero tal cual. Mirando solo el tipo
        // cadena, un `--hours=999` programatico se caia al valor de serie sin
        // decir nada, que es peor que rechazarlo.
        if ($option === null || $option === '') {
            return max(1, Config::integer('product.support_grant_default_hours', 24));
        }

        // Un `--hours=manana` acaba en 0 y el dominio lo rechaza con su mensaje,
        // que dice el rango valido. No se traduce aqui a un mensaje distinto: una
        // sola respuesta para «esa duracion no vale».
        return is_numeric($option) ? (int) $option : 0;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return \is_string($value) ? trim($value) : '';
    }
}

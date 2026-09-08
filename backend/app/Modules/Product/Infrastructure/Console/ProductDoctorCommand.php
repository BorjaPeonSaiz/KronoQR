<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorReport;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * `php artisan product:doctor` — el diagnostico de la instalacion (Anexo C del
 * doc 01, **RF-PD-13**).
 *
 * ## Para quien esta escrito
 *
 * Para la persona de informatica del hotel, que no conoce este sistema, que
 * probablemente tiene prisa y que probablemente tiene ya un problema. Por eso la
 * salida empieza por **lo que hay que mirar** y deja lo que esta bien para el
 * final: hacerle recorrer treinta lineas verdes hasta la roja es una forma de
 * esconderla.
 *
 * Y por eso cada linea roja lleva su **que hacer** debajo, con el comando entero
 * y copiable. El fabricante no tiene acceso a este servidor (ADR-016): si el
 * mensaje no dice que hacer, la unica salida es una llamada.
 *
 * ## Un solo idioma en todo el informe, y sale de la instalacion
 *
 * **Ni una linea del marco esta escrita a mano en este fichero.** Titulo,
 * encabezados, etiquetas y la frase que explica el codigo de salida viven en
 * `lang/{es,en}/doctor.php` junto a los textos de las comprobaciones, y se
 * resuelven con el **mismo** idioma.
 *
 * Tenerlas aqui producia el peor resultado posible y medido: un informe con el
 * marco en español y las comprobaciones en ingles, que es justo lo que ocurre en
 * cuanto `APP_LOCALE` y el idioma de la instalacion no coinciden — el caso
 * normal, porque el idioma del panel se configura desde el panel y `APP_LOCALE`
 * se queda como lo dejo el instalador.
 *
 * El orden de resolucion es: `--lang`, despues `LOCALE_DEFAULT` de la
 * instalacion —lo que el cliente eligio (ADR-017, RF-PD-01)— y solo entonces
 * `APP_LOCALE`.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Todo correcto. |
 * | `1` | **Solo avisos.** Hay algo que conviene mirar; nada esta roto. |
 * | `2` | **Al menos un fallo.** Hay algo que corregir. |
 *
 * Los consumen `install.sh` (`phase_verify`) y `update.sh`
 * (`phase_start_and_verify`), y **solo el `2` se traduce al `6`** de su tabla
 * comun: un aviso se enseña y no bloquea una instalacion que por lo demas esta
 * bien. Un `1` que abortara una instalacion por un certificado autofirmado —que
 * es lo normal en una red interna— haria el comando inutilizable.
 *
 * ## Nunca lanza, y con la base de datos caida tampoco
 *
 * Es el requisito que da sentido al comando: se ejecuta cuando algo esta roto.
 * Cada sonda esta envuelta en el caso de uso, asi que un Postgres que no
 * responde produce una linea roja con su `fix` y las otras siete familias siguen
 * comprobandose. **Tambien la eleccion de idioma**: leer `LOCALE_DEFAULT` es una
 * consulta a la base de datos, y si falla se cae a `APP_LOCALE` en lugar de
 * dejar a nadie sin diagnostico.
 *
 * ## Funciona con la licencia caducada o ausente (regla dura 15)
 *
 * Y ninguna comprobacion de licencia puede pasar de `warning` (ADR-019).
 */
final class ProductDoctorCommand extends Command
{
    protected $signature = 'product:doctor
        {--json : Devuelve el informe como JSON, para install.sh, update.sh y el paquete de diagnostico}
        {--lang= : Idioma del informe (es|en). Por defecto, el de la instalacion}';

    protected $description = 'Comprueba base de datos, colas, correo, certificado, permisos y disco, y dice que hacer con lo que falle';

    /** El idioma de TODO el informe, resuelto una sola vez. */
    private string $locale = 'es';

    public function handle(
        RunDoctorHandler $doctor,
        DoctorTranslator $translator,
        GetSettingsHandler $settings,
        Config $config,
    ): int {
        $this->locale = $this->resolveLocale($settings, $config);

        $report = $doctor->handle($this->locale);

        if ($this->option('json') === true) {
            $this->output->writeln((string) json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return $report->exitCode();
        }

        $this->render($report, $translator);

        return $report->exitCode();
    }

    /**
     * El idioma del informe: `--lang`, el de la instalacion, o `APP_LOCALE`.
     *
     * **`LOCALE_DEFAULT` antes que `APP_LOCALE`** porque es el que el cliente
     * eligio desde el panel y el que ven sus tres aplicaciones (ADR-017);
     * `APP_LOCALE` es lo que quedo en el `.env` el dia de la instalacion y casi
     * nunca se vuelve a tocar.
     *
     * **Sin validar contra una lista cerrada** a proposito: si alguien pide un
     * idioma que no existe, el traductor no encuentra las claves y el informe
     * sale con las claves a la vista, lo que delata el problema en la primera
     * ejecucion. Rechazar la ejecucion entera por eso dejaria a alguien sin
     * diagnostico por una errata.
     */
    private function resolveLocale(GetSettingsHandler $settings, Config $config): string
    {
        $requested = $this->option('lang');

        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        try {
            $configured = $settings->handle()->text(SettingKey::LOCALE_DEFAULT);

            if ($configured !== '') {
                return $configured;
            }
        } catch (Throwable) {
            // La base de datos puede no responder: es la mitad de los casos en
            // los que se ejecuta esto. Se sigue con el idioma del `.env`.
        }

        $fallback = $config->get('app.locale');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'es';
    }

    private function render(DoctorReport $report, DoctorTranslator $translator): void
    {
        $problems = $report->problems();

        $this->line('');
        $this->line($this->say($translator, 'title', ['version' => $report->productVersion]));
        $this->line('==============================');
        $this->line('');

        if ($problems === []) {
            $this->line($this->say($translator, 'all_ok', ['total' => \count($report->checks)]));
        } else {
            $this->line($this->say($translator, 'problems', [
                'count' => \count($problems),
                'total' => \count($report->checks),
            ]));
            $this->line('');

            foreach ($problems as $check) {
                $this->problem($check, $translator);
            }
        }

        $this->line('');
        $this->line($this->say($translator, 'ok_header'));
        $this->line('------------------------');

        foreach ($report->checks as $check) {
            if ($check->status === DoctorStatus::Ok) {
                $this->line('  ['.$this->say($translator, 'tag_ok').']      '.$check->id.' — '.$check->summary);
            }
        }

        $this->line('');
        $this->line($this->say($translator, 'result', [
            'label' => $this->say($translator, 'status_'.$report->status->value),
            'code' => $report->exitCode(),
        ]));
        $this->line($this->say($translator, 'meaning_'.$report->status->value));
        $this->line('');
    }

    private function problem(DoctorCheck $check, DoctorTranslator $translator): void
    {
        $tag = $check->status === DoctorStatus::Failure
            ? '['.$this->say($translator, 'tag_failure').']  '
            : '['.$this->say($translator, 'tag_warning').']  ';

        $this->line('  '.$tag.$check->id);
        $this->line('      '.$check->summary);

        if ($check->fix !== null) {
            $this->line('      '.$this->say($translator, 'fix_label'));

            foreach (explode("\n", $check->fix) as $line) {
                $this->line('        '.$line);
            }
        }

        $this->line('');
    }

    /**
     * Un texto del marco del informe, en el idioma ya resuelto.
     *
     * Devuelve la clave si falta la traduccion, por el mismo criterio que las
     * comprobaciones: una clave a la vista es fea y delata el descuido en la
     * primera ejecucion; una linea en blanco lo esconde.
     *
     * @param  array<string, string|int|float|bool|null>  $params
     */
    private function say(DoctorTranslator $translator, string $key, array $params = []): string
    {
        return $translator->translate('doctor.report.'.$key, $params, $this->locale) ?? 'doctor.report.'.$key;
    }
}

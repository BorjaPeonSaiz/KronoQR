<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\SendTelemetryHandler;
use App\Modules\Product\Application\UseCase\TelemetryDraft;
use App\Modules\Product\Application\UseCase\TelemetryOutcome;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\TelemetryBlockReason;
use App\Modules\Product\Domain\ValueObject\TelemetryDelivery;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * `php artisan product:telemetry` - ensena el documento que se enviaria y, con
 * `--send`, lo envia (**RF-PD-12**, ADR-020, ADR-023, ficha 5.10 punto 8).
 *
 * ## Sin banderas NO envia nada, y ese es el uso principal
 *
 * La ficha lo pide con estas palabras: *imprime el documento que se enviaria,
 * para que el cliente decida con el delante*. Una lista de campos en un manual
 * se lee mal y envejece; el documento real, con los valores reales de esta
 * instalacion, se lee en diez segundos y no puede mentir. De ahi que se pueda
 * ver **con la telemetria apagada**: obligar a activarla para ver lo que se
 * activaria seria pedir la firma antes del contrato.
 *
 * ## `--send` respeta las tres condiciones
 *
 * `TELEMETRY_ENABLED`, `TELEMETRY_ENDPOINT` y `telemetry` en la licencia. Si
 * falta alguna, no construye ni envia nada y **dice cual falta**.
 *
 * ## Sale `0` SIEMPRE
 *
 * Tambien cuando el envio falla, y esto es una decision, no un descuido. El
 * escenario normal de este producto es un hotel sin salida a internet (doc 02
 * seccion 11.6.2): un codigo distinto de cero convertiria a la telemetria en la
 * unica tarea del planificador que «falla» todas las semanas en una instalacion
 * sana, y cualquier supervision razonable acabaria avisando por ello. Que fallo
 * hubo se ve en la salida y en `state.json`, no en el codigo de salida.
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | Siempre. Lo que haya pasado se cuenta en la salida. |
 *
 * ## El idioma sale de la instalacion
 *
 * Mismo orden que `product:doctor`: `--lang`, despues `LOCALE_DEFAULT` y solo
 * entonces `APP_LOCALE`. Reutiliza {@see DoctorTranslator}, que a pesar del
 * nombre no sabe nada de `doctor`: recibe una clave, unos parametros y un
 * idioma. Un puerto nuevo identico habria sido una copia.
 *
 * ## Funciona con la licencia caducada o ausente (regla dura 15)
 *
 * Sin licencia no hay envio -es una de las tres condiciones- pero el documento
 * se ensena igual, y el estado de la licencia es uno de sus campos.
 */
final class ProductTelemetryCommand extends Command
{
    protected $signature = 'product:telemetry
        {--send : Envia el documento, si se cumplen las tres condiciones. Sin esta bandera no sale nada de aqui}
        {--json : Salida legible por otro programa, en lugar del informe para personas}
        {--lang= : Idioma del informe. Por defecto, el de la instalacion}';

    protected $description = 'Ensena el documento de telemetria que se enviaria al fabricante y, con --send, lo envia';

    private string $locale = 'es';

    public function handle(
        SendTelemetryHandler $telemetry,
        DoctorTranslator $translator,
        GetSettingsHandler $settings,
        Config $config,
    ): int {
        $this->locale = $this->resolveLocale($settings, $config);

        $outcome = $this->option('send') === true ? $telemetry->handle() : $telemetry->preview();

        if ($this->option('json') === true) {
            $this->line($this->machineReadable($outcome));

            return self::SUCCESS;
        }

        $this->render($outcome, $translator, $config);

        return self::SUCCESS;
    }

    private function render(TelemetryOutcome $outcome, DoctorTranslator $translator, Config $config): void
    {
        $this->line('');
        $this->line($this->say($translator, 'title'));
        $this->line(str_repeat('=', 60));
        $this->line('');
        $this->line($this->say($translator, $outcome->isEnabled() ? 'state.enabled' : 'state.disabled'));

        if ($outcome->blockedBy instanceof TelemetryBlockReason) {
            $this->line('  '.$this->say($translator, 'blocked.'.$outcome->blockedBy->value));
        }

        $this->line('');

        if ($outcome->draft instanceof TelemetryDraft) {
            $this->document($outcome->draft, $translator, $config, $outcome->identityStored);
        }

        if ($outcome->delivery instanceof TelemetryDelivery) {
            $this->deliveryLine($outcome->delivery, $translator);
        } elseif ($this->option('send') === true) {
            $this->line($this->say($translator, 'send.skipped'));
        }

        $this->line('');
        $this->line($this->say($translator, $outcome->isEnabled() ? 'how_to.disable' : 'how_to.enable'));

        if ($this->option('send') !== true) {
            $this->line($this->say($translator, 'how_to.send'));
        }

        $this->line('');
    }

    private function document(
        TelemetryDraft $draft,
        DoctorTranslator $translator,
        Config $config,
        bool $identityStored,
    ): void {
        $report = $draft->report;
        $path = self::text($config->get('product.telemetry_state_path')) ?? 'storage/app/telemetry/state.json';

        $this->line($this->say($translator, 'identity.label').': '.$report->installationId);
        $this->line('  '.$this->say($translator, 'identity.explanation', ['path' => $path]));

        if (! $identityStored) {
            // Mirar no deja rastro: este identificador no se ha guardado y no se
            // guardara hasta el primer envio. Decirlo evita que alguien anote el
            // de hoy y luego vea otro distinto.
            $this->line('  '.$this->say($translator, 'identity.provisional', ['path' => $path]));
        }

        $this->line('');
        $this->line($this->say($translator, 'document.header'));
        $this->line('');
        // Legible y con las claves ordenadas: lo va a leer una persona que esta
        // decidiendo. La forma canonica -sin espacios- es la que se envia.
        $this->line((string) json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $this->line('');
        $this->line($this->say($translator, 'document.never'));
        $this->line($this->say($translator, 'document.footer'));
        $this->line('');
    }

    private function deliveryLine(TelemetryDelivery $delivery, DoctorTranslator $translator): void
    {
        $this->line($delivery->delivered
            ? $this->say($translator, 'send.delivered', [
                'status' => $delivery->statusCode ?? 0,
                'attempts' => $delivery->attempts,
            ])
            : $this->say($translator, 'send.failed', [
                'failure' => $delivery->failure ?? 'unknown',
                'attempts' => $delivery->attempts,
            ]));
    }

    /**
     * La misma informacion para otro programa.
     *
     * `report` es `null` cuando la telemetria esta apagada y se pidio `--send`:
     * con las condiciones sin cumplir **no se construye nada**, y esta salida
     * tiene que decir eso mismo en lugar de fingir un documento.
     */
    private function machineReadable(TelemetryOutcome $outcome): string
    {
        return (string) json_encode([
            'enabled' => $outcome->isEnabled(),
            'blocked_by' => $outcome->blockedBy?->value,
            'sent' => $outcome->delivery instanceof TelemetryDelivery && $outcome->delivery->delivered,
            'delivery' => $outcome->delivery === null ? null : [
                'delivered' => $outcome->delivery->delivered,
                'status_code' => $outcome->delivery->statusCode,
                'failure' => $outcome->delivery->failure,
                'attempts' => $outcome->delivery->attempts,
            ],
            'report' => $outcome->draft?->report->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Ver `ProductDoctorCommand::resolveLocale()`: `--lang`, el idioma de la
     * instalacion y solo entonces el del `.env`.
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
            // La base de datos puede no responder. Se sigue con el `.env`.
        }

        $fallback = $config->get('app.locale');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'es';
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $params
     */
    private function say(DoctorTranslator $translator, string $key, array $params = []): string
    {
        return $translator->translate('telemetry.'.$key, $params, $this->locale) ?? 'telemetry.'.$key;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}

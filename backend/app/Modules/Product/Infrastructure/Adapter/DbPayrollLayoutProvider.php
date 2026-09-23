<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\PayrollLayoutProvider;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;

/**
 * La plantilla de la salida a nomina, resuelta desde `installation_settings`
 * (**RF-IN-07**, RF-PD-01, regla dura 13, ADR-025).
 *
 * ## Que hace, y que no
 *
 * Lee las **seis** claves `PAYROLL_EXPORT_*` del conjunto ya resuelto y se las
 * entrega a {@see PayrollLayout::fromSettings()} como escalares. Nada mas: ni
 * decide columnas, ni formatea nada, ni sabe escribir un CSV. Esa separacion es
 * la que permite que la plantilla sea dominio puro y se pruebe sin base de datos,
 * y es el mismo reparto que {@see DbOperationalSettingsProvider} hace con los
 * umbrales del fichaje.
 *
 * **Y solo se toman las seis que se consumen.** La marca, los idiomas y los
 * umbrales de fichaje no entran aqui ni pueden influir en el fichero que sale.
 *
 * ## La cascada y la tolerancia
 *
 *     fila de installation_settings  ->  valor por defecto del catalogo
 *
 * La resuelve `ResolvedSettings` —dominio puro— a traves de
 * {@see GetSettingsHandler}, que es el unico punto de lectura del modulo. Una
 * fila corrupta ya llega descartada con su valor de serie, asi que aqui no hay
 * nada que atrapar; lo que este adaptador añade es la tolerancia del ultimo
 * escalon, que vive en `PayrollLayout`: un identificador de columna que este
 * binario no conozca se descarta en vez de reventar la descarga.
 *
 * Que sea tolerante importa menos que en el fichaje —de esto no depende que nadie
 * pueda fichar— pero importa igual por la fecha en que se usa: la salida a nomina
 * se pide el dia de cierre, y un fallo ahi no admite «lo miramos mañana».
 *
 * ## `scoped()` y memoria por peticion
 *
 * Como los otros proveedores del modulo. Una descarga pide la plantilla una vez,
 * pero el endpoint sincrono y el trabajo en cola comparten adaptador y no tiene
 * sentido resolver la cascada dos veces en la misma peticion. Muere con ella, asi
 * que un cambio guardado en el panel surte efecto en la siguiente.
 */
final class DbPayrollLayoutProvider implements PayrollLayoutProvider
{
    /** @var array<int, PayrollLayout> */
    private array $resolved = [];

    public function __construct(private readonly GetSettingsHandler $settings) {}

    public function forSite(int $siteId): PayrollLayout
    {
        if (isset($this->resolved[$siteId])) {
            return $this->resolved[$siteId];
        }

        $settings = $this->settings->handle();

        return $this->resolved[$siteId] = PayrollLayout::fromSettings(
            columns: $settings->textList(SettingKey::PAYROLL_EXPORT_COLUMNS),
            delimiter: $settings->text(SettingKey::PAYROLL_EXPORT_DELIMITER),
            hoursFormat: $settings->text(SettingKey::PAYROLL_EXPORT_HOURS_FORMAT),
            dateFormat: $settings->text(SettingKey::PAYROLL_EXPORT_DATE_FORMAT),
            encoding: $settings->text(SettingKey::PAYROLL_EXPORT_ENCODING),
            headerRow: $settings->text(SettingKey::PAYROLL_EXPORT_HEADER_ROW),
        );
    }
}

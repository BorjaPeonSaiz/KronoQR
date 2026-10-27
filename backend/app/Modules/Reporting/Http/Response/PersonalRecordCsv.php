<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordFormat;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordLayout;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El historico propio como fichero CSV (`GET /api/v1/me/export`, RF-ID-05,
 * RL-05).
 *
 * ## Las columnas y los valores son los del PDF
 *
 * Los decide {@see PersonalRecordLayout}, una sola definicion para los dos
 * formatos del mismo registro: que columnas hay, que dice cada celda
 * y en que desfase esta cada hora. Aqui solo queda lo que es del
 * fichero: la cabecera de criterios, el dialecto y la respuesta en streaming.
 * El CSV lleva todas las columnas; el PDF, todas menos las que solo sirven a
 * una maquina (la zona IANA y las marcas en UTC).
 *
 * ## Por que no reutiliza el escritor de la exportacion legal
 *
 * `Compliance\Infrastructure\Export\CsvLegalExportWriter` —nombrado en prosa
 * porque `Reporting` no puede importarlo (doc 02 §1.6)— escribe otro documento:
 * veintiocho columnas, alcance de plantilla, manifiesto con base legal, asiento
 * en `audit_log`, escritura a fichero temporal para que la traza se escriba antes
 * de entregar nada, y un origen propio que recorre un cursor de servidor.
 * Reutilizarlo habria significado abrir una frontera entre modulos y arrastrar
 * toda esa maquinaria para servir el mes de una sola persona.
 *
 * Lo que **si** se comparte, y ahora de verdad y no de palabra, son las
 * decisiones de codificacion: BOM, punto y coma, entrecomillado del RFC 4180,
 * sin escapado propietario y `\r\n` como fin de linea. Viven en
 * {@see CsvDialect} y las escriben sus dos metodos, de modo que los dos ficheros
 * no puedan volver a divergir. Durante un tiempo lo hicieron —este salia con
 * `\r\n` y el de la Inspeccion con `\n`— porque este docblock decia que
 * reutilizaba esas decisiones cuando lo unico que reutilizaba era el
 * razonamiento.
 *
 * La decision de formato que **no** esta alli, porque es de contenido y no de
 * bytes, es **`HH:MM` como texto, nunca decimal**: «7,5» no es una hora y media,
 * es una cifra que hay que interpretar. `07:30` no se interpreta. Es el mismo
 * listón que la exportacion legal, porque el numero tiene las mismas
 * consecuencias.
 *
 * ## Y una que es propia de este fichero: no hay nadie mas dentro
 *
 * El alcance es exactamente el del token que lo pidio. No hay columna de
 * empleado porque solo hay uno, y el nombre del fichero tampoco lo lleva.
 *
 * ## Streaming, y aqui si se puede
 *
 * A diferencia de la exportacion legal, esto no se escribe a un temporal para
 * poder auditar antes de entregar: no hay asiento que escribir (RS-05 habla de
 * terceros) y el volumen esta acotado por el techo de 366 dias de `DateRange`.
 * Se emite directamente, sin dejar en disco un fichero con el registro horario
 * de alguien.
 *
 * ## Los rotulos salen de `lang/` y no de aqui
 *
 * `lang/{es,en}/personal-record.php`. El idioma del documento es configuracion
 * de la instalacion (regla dura 13, ADR-017): un escritor con los rotulos
 * incrustados obligaria a tocar el repositorio para vender a un cliente que
 * trabaja en ingles. Un rotulo que falta rompe la descarga en voz alta, como en
 * el PDF, en lugar de imprimir la clave.
 */
final readonly class PersonalRecordCsv
{
    public static function respond(WorkDayJournal $journal): StreamedResponse
    {
        // Las filas se componen ANTES de responder, porque su numero viaja en
        // `X-Kronoqr-Report-Rows` y las cabeceras salen antes que el cuerpo. El
        // volumen esta acotado por el techo de 366 dias de `DateRange`.
        $rows = PersonalRecordLayout::rows($journal, PersonalRecordFormat::Csv);

        return new StreamedResponse(
            static function () use ($journal, $rows): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                // La marca de orden de bytes va antes que nada: es lo que le dice
                // a la hoja de calculo que esto es UTF-8.
                CsvDialect::writeByteOrderMark($handle);

                self::writeHeader($handle, $journal);

                foreach ($rows as $row) {
                    self::row($handle, $row);
                }

                fclose($handle);
            },
            200,
            [
                'Content-Type' => CsvDialect::CONTENT_TYPE,
                'Content-Disposition' => 'attachment; filename='.PersonalRecordLayout::filename($journal, 'csv'),
                // El cuerpo es el registro horario de una persona: ni un proxy
                // ni un navegador compartido pueden guardarlo.
                'Cache-Control' => 'no-store, private',
                // Filas de datos, sin la cabecera de criterios ni los rotulos:
                // tramos, jornadas sin tramos y correcciones, como el PDF. Con
                // `0` el portal dice que el periodo no tiene jornadas.
                'X-Kronoqr-Report-Rows' => (string) \count($rows),
            ],
        );
    }

    /**
     * Los criterios de inclusion y, tras una linea en blanco, los rotulos.
     *
     * La linea en blanco es lo que permite que una hoja de calculo reconozca la
     * tabla al seleccionarla, y los criterios son lo que hace que el fichero se
     * explique solo cuando alguien lo abra dos años despues.
     *
     * @param  resource  $handle
     */
    private static function writeHeader($handle, WorkDayJournal $journal): void
    {
        self::row($handle, [PersonalRecordLayout::csvText('title')]);
        self::row($handle, [self::label('period'), PersonalRecordLayout::csvText('period_value', [
            'from' => $journal->range->isoFrom(),
            'to' => $journal->range->isoTo(),
        ])]);
        self::row($handle, [self::label('time_zone'), $journal->timeZone]);
        self::row($handle, [self::label('legal_basis'), PersonalRecordLayout::csvText('legal_basis_value')]);

        foreach (PersonalRecordLayout::criteria(PersonalRecordFormat::Csv) as $index => $criterion) {
            self::row($handle, [$index === 0 ? self::label('criteria') : '', $criterion]);
        }

        self::row($handle, []);
        self::row($handle, PersonalRecordLayout::header(PersonalRecordFormat::Csv));
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $values
     */
    private static function row($handle, array $values): void
    {
        // Delimitador, entrecomillado, escapado y fin de linea los pone el
        // dialecto: aqui no queda ninguna decision de codificacion que pueda
        // separarse de la del fichero que se entrega a la Inspeccion.
        CsvDialect::writeRow($handle, $values);
    }

    private static function label(string $key): string
    {
        return PersonalRecordLayout::csvText('header.'.$key);
    }
}

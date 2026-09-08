<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

/**
 * Que lineas del informe de actualizacion viajan en el paquete de diagnostico
 * (RF-PD-09, ADR-020, regla dura 21).
 *
 * ## Por que hace falta filtrar un fichero que dice que no lleva datos personales
 *
 * `update.sh` escribe DOS ficheros a proposito: el informe (`0640`) y el detalle
 * (`0600` de root), y manda los volcados largos al segundo justamente porque
 * pueden llevar datos personales —el `DETAIL: Failing row contains (...)` de
 * PostgreSQL es el ejemplo que su propio comentario cita—. El informe se declara
 * limpio, y lo es **mientras nadie escriba en el sitio equivocado**.
 *
 * Esa es exactamente la clase de promesa que la ficha 5.9 prohibe confiar: era
 * la unica seccion del paquete que se volcaba literal, sin lista de permitidos.
 * Un `say`/`err` mal puesto en una version futura de `update.sh` —o un mensaje
 * de error del motor que se cuele por `err`— acabaria en un fichero que el
 * cliente envia por correo al fabricante, y no fallaria nada.
 *
 * ## Como esta escrito el filtro
 *
 * **Por comienzo de linea reconocido**, que es la forma que tiene el informe:
 * cabecera, separadores y una lista de `Etiqueta: valor` compuestas con
 * `kq_format` a partir del catalogo de `lib/messages-update.sh`. Los comienzos
 * de los dos idiomas estan aqui porque el catalogo es del producto, no del
 * cliente: no es una copia de datos suyos, es la forma de un fichero que
 * escribimos nosotros.
 *
 * **Lo que no encaja no se recorta a medias: se cuenta.** El paquete lleva
 * `omitted_lines` con el numero, para que soporte sepa que habia mas y pueda
 * pedir el fichero completo al cliente en lugar de creer que el informe era ese.
 *
 * **La direccion del fallo es la correcta.** Si `update.sh` gana una linea nueva
 * y nadie la añade aqui, esa linea se omite y se cuenta —molesto—; con una lista
 * de exclusiones se publicaria sin que nadie se enterara —una fuga—.
 */
final class UpdateReportAllowlist
{
    /**
     * Comienzos de linea que el informe puede tener, en los dos idiomas del
     * producto. Salen de `KQ_MSG_ES[u_report_*]` y `KQ_MSG_EN[u_report_*]`.
     *
     * @var list<string>
     */
    private const array OPENERS = [
        // Espanol.
        'Inicio (UTC):',
        'Fin (UTC):',
        'Version de origen:',
        'Instalacion actual:',
        'Cadena de versiones:',
        'Copia previa:',
        'Ventana de mantenimiento:',
        'Punto de control ',
        'Comprobacion ·',
        'Vuelta atras:',
        'Estado final:',
        'Salida ',
        'Este informe no contiene',
        // Ingles.
        'Start (UTC):',
        'End (UTC):',
        'Source version:',
        'Current installation:',
        'Version chain:',
        'Pre-update backup:',
        'Maintenance window:',
        'Checkpoint ',
        'Check ·',
        'Rollback:',
        'Final state:',
        'Exit ',
        'This report holds no',
    ];

    /**
     * Lineas completas admitidas tal cual: el titulo del informe en los dos
     * idiomas.
     *
     * @var list<string>
     */
    private const array TITLES = [
        'INFORME DE ACTUALIZACION DE KRONOQR',
        'KRONOQR UPDATE REPORT',
    ];

    /**
     * Tope por linea. Ninguna linea legitima del informe se acerca; un volcado
     * las supera de largo. Es la segunda red: una linea larguisima que ademas
     * empezara por un comienzo conocido seguiria sin salir.
     */
    private const int MAX_LINE_LENGTH = 300;

    public static function allows(string $line): bool
    {
        $line = rtrim($line, "\r");

        if ($line === '') {
            return true;
        }

        if (\strlen($line) > self::MAX_LINE_LENGTH) {
            return false;
        }

        // Los separadores que `close_report` dibuja entre bloques.
        if (preg_match('/^[=\-]{3,}$/', $line) === 1) {
            return true;
        }

        if (in_array($line, self::TITLES, true)) {
            return true;
        }

        foreach (self::OPENERS as $opener) {
            if (str_starts_with($line, $opener)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filtra el informe entero.
     *
     * @return array{content: string, omitted_lines: int}
     */
    public static function apply(string $report): array
    {
        $kept = [];
        $omitted = 0;

        foreach (explode("\n", $report) as $line) {
            if (self::allows($line)) {
                $kept[] = rtrim($line, "\r");

                continue;
            }

            $omitted++;
        }

        return ['content' => implode("\n", $kept), 'omitted_lines' => $omitted];
    }
}

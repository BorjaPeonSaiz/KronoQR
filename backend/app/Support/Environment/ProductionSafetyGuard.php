<?php

declare(strict_types=1);

namespace App\Support\Environment;

/**
 * Se niega a arrancar una instalacion de produccion con las trazas encendidas.
 *
 * ## Por que existe
 *
 * Con `APP_DEBUG=true`, cualquier error no capturado devuelve la traza de la
 * excepcion **y la configuracion resuelta**, que incluye credenciales de base
 * de datos y claves de firma. En un producto que se instala en el servidor de
 * un tercero, ese es el fallo de configuracion mas facil de cometer y el mas
 * caro: basta con copiar el `.env` de ejemplo sin leerlo. Estuvo anotado como
 * hueco en `docs/07-seguridad-madurez-y-amenazas.md` §6 hasta esta tarea.
 *
 * El aviso escrito no basta —`.env.example` llevaba «false SIEMPRE en
 * produccion» desde la Fase 0 y aun asi el hueco seguia abierto—, asi que la
 * comprobacion es una guarda y no una recomendacion.
 *
 * ## Por que se para el arranque en lugar de avisar
 *
 * Un aviso en el log de una instalacion que nadie mira no cierra el hueco:
 * cuando alguien lea ese log, la traza ya se habra servido. Pararse es la
 * unica respuesta que garantiza que la configuracion insegura no llega a
 * atender una peticion.
 *
 * **Y por eso la condicion es estrecha a proposito.** Solo se dispara con
 * `APP_ENV=production` **y** `APP_DEBUG=true`, que es una combinacion que nadie
 * elige queriendo. Ampliarla —por ejemplo, exigir tambien que `APP_KEY` tenga
 * cierta forma— convertiria esta guarda en algo capaz de dejar sin fichar a un
 * hotel por un motivo discutible, y eso pesa mas que el hueco que cerraria.
 *
 * El instalador comprueba lo mismo en su **fase 1**, antes de escribir nada
 * (`infra/scripts/install.sh`), de modo que el camino normal es que este error
 * no llegue a verse nunca: quien lo vea es quien edito el `.env` despues de
 * instalar.
 *
 * ## El mensaje va en los dos idiomas
 *
 * No hay negociacion de idioma en el arranque —esto ocurre antes de que exista
 * una peticion— y el destinatario es el IT del hotel leyendo el log de un
 * contenedor que no levanta. Se escriben las dos versiones seguidas en vez de
 * elegir una: son cuatro lineas y ahorran una llamada de telefono.
 */
final class ProductionSafetyGuard
{
    /**
     * @throws UnsafeProductionConfiguration si la combinacion es insegura
     */
    public static function assert(string $environment, bool $debug): void
    {
        if ($environment !== 'production') {
            return;
        }

        if ($debug === false) {
            return;
        }

        throw new UnsafeProductionConfiguration(self::explanation());
    }

    private static function explanation(): string
    {
        return implode("\n", [
            'KronoQR se niega a arrancar: APP_ENV=production con APP_DEBUG=true.',
            '',
            'Por que: con APP_DEBUG=true, cualquier error muestra la traza y la',
            'configuracion resuelta -incluidas las contrasenas de la base de datos y',
            'las claves de firma del QR- a quien provoque ese error.',
            '',
            'Que hacer: pon APP_DEBUG=false en el .env de la instalacion y reinicia',
            'los servicios:',
            '',
            '    docker compose -f docker-compose.yml up -d',
            '',
            'El registro horario NO se ha perdido: esto es una comprobacion de',
            'arranque, no toca ni la base de datos ni los ficheros.',
            '',
            '--',
            '',
            'KronoQR refuses to start: APP_ENV=production with APP_DEBUG=true.',
            '',
            'Why: with APP_DEBUG=true any error shows the stack trace and the',
            'resolved configuration -database passwords and QR signing keys',
            'included- to whoever triggers that error.',
            '',
            'What to do: set APP_DEBUG=false in the installation .env and restart',
            'the services:',
            '',
            '    docker compose -f docker-compose.yml up -d',
            '',
            'No time record has been lost: this is a start-up check and it touches',
            'neither the database nor any file.',
        ]);
    }
}

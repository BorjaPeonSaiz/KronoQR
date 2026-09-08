<?php

declare(strict_types=1);

/*
 * Marca de la instalacion — LO QUE ES DEL DESPLIEGUE (RF-PD-08, ADR-017).
 *
 * OJO A LA DISTINCION, QUE ES LA RAZON DE SER DE ESTE FICHERO. El nombre de la
 * aplicacion, el color de acento y la ruta del logotipo NO estan aqui: son del
 * cliente, se editan desde el panel sin reiniciar nada y cada cambio queda
 * auditado (`installation_settings`, claves `BRANDING_*`). Su catalogo, con los
 * valores de serie, es `App\Modules\Product\Domain\ValueObject\SettingKey`.
 *
 * LAS VARIABLES `BRANDING_NAME` Y `BRANDING_ACCENT_COLOR` SE RETIRARON EN LA
 * TAREA 5.8. Convivieron con las claves de la tabla mientras la marca se
 * guardaba pero todavia no se pintaba; ahora que se pinta, dos fuentes para el
 * mismo dato solo sirven para que alguien cambie el color en el panel, no vea
 * ningun efecto y no tenga forma de saber por que. Manda la base de datos
 * (decision de la tarea 5.1), asi que la otra cara sobra.
 *
 * LO QUE SI QUEDA AQUI es donde puede vivir el fichero del logotipo y cuanto
 * puede ocupar: eso es del servidor, no del hotel. Cambiarlo exige mover un
 * montaje de Docker y reiniciar, que es exactamente el criterio de la seccion 1
 * de `docs/cliente/configuracion.md`.
 */

return [

    /*
     * Directorio de marca: la UNICA carpeta del servidor de la que se puede
     * servir un logotipo.
     *
     * NO ES UNA COMODIDAD, ES LA GUARDA. `GET /api/v1/branding/logo` es publico
     * —el quiosco y el portal enseñan la marca antes de identificar a nadie—, y
     * sin este confinamiento seria una lectura de cualquier fichero del servidor
     * a la que le basta con guardar una ruta desde el panel. La ruta configurada
     * se resuelve con `realpath` y tiene que caer aqui dentro; un enlace
     * simbolico que apunte fuera tampoco pasa.
     *
     * En produccion es `/var/kronoqr/branding`, montado de SOLO LECTURA desde
     * `${BRANDING_PATH}` del disco del cliente (`infra/compose.prod.yaml`). En
     * desarrollo y en la suite, `storage/app/branding`, que ya viaja en el bind
     * mount del backend.
     */
    'logo_root' => env('BRANDING_LOGO_ROOT', storage_path('app/branding')),

    /*
     * Tope de tamano del fichero: 512 KiB.
     *
     * Es un limite de RENDIMIENTO, no de disco. El logotipo lo descarga la
     * tablet del quiosco en cada arranque frio y viaja incrustado en base64
     * dentro de cada PDF de tarjetas —donde base64 lo engorda un tercio—. Con un
     * logotipo de 8 MB, una hoja A4 de diez tarjetas serian 100 MB de HTML que
     * Chromium tiene que digerir, y el presupuesto del Anexo A se lo lleva por
     * delante.
     *
     * 512 KiB es holgado para lo que es: un logotipo de hotel en PNG con
     * transparencia rara vez pasa de 60 KB, y en SVG, de 20.
     *
     * No es variable de entorno. Un cliente no necesita otro numero: si el suyo
     * no cabe, lo que hay que hacer es exportarlo mejor, y subir el limite solo
     * traslada el problema a la tablet.
     */
    'logo_max_bytes' => 524288,

    /*
     * Tope de pixeles de lado de un PNG.
     *
     * Se lee del IHDR sin descodificar la imagen. Existe porque el tamano en
     * bytes no acota el coste de dibujarla: un PNG de 12000 x 12000 en negro
     * pesa poco y hace que el navegador de la tablet reserve varios cientos de
     * megabytes al pintarlo. Un SVG no tiene pixeles y no se mide.
     *
     * 2048 sobra para cualquier cabecera y para la tarjeta impresa a 300 ppp.
     */
    'logo_max_dimension' => 2048,

];

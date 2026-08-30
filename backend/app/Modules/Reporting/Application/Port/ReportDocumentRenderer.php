<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * Compone un documento PDF a partir de un HTML ya montado (**RF-IN-04**).
 *
 * ## Por que hay un puerto para algo que parece una llamada a una libreria
 *
 * Porque no lo es. Detras de esto hay un **Chromium** arrancado como proceso
 * externo, y eso no es una dependencia de PHP: es un binario que puede no estar
 * instalado en el servidor de un cliente, puede no arrancar por falta de memoria
 * o puede quedarse colgado. Sin el puerto, ese fallo llega al borde como una
 * excepcion de infraestructura y se convierte en un `500` opaco — y quien lo
 * recibe no tiene forma de saber que su informe **si** existe y que basta con
 * pedirlo en CSV o en XLSX.
 *
 * Con el puerto, el adaptador traduce cualquier fallo a
 * `ReportRenderingUnavailable`, que el borde sirve como `503`
 * `problem+json` con la salida escrita dentro. Los otros dos formatos no pasan
 * por aqui y siguen funcionando: **la ausencia de Chromium degrada un formato,
 * no la exportacion**.
 *
 * ## Habla en cadenas, no en objetos de una libreria
 *
 * HTML entra, bytes de PDF salen. Es la restriccion 2 de ADR-025 —los puertos
 * hablan en tipos propios o escalares—, y ademas es lo que permite que una
 * prueba sustituya el motor por un doble que devuelve cuatro bytes sin levantar
 * un navegador.
 *
 * ## No guarda nada en disco
 *
 * Devuelve los bytes, como el renderizador de tarjetas de `Identity` —nombrado
 * en prosa porque `Reporting` no puede importarlo (doc 02 §1.6)—. Un PDF con las
 * horas nominales de la plantilla en `storage/` es una copia del registro
 * horario esperando a que alguien se olvide de borrarla.
 */
interface ReportDocumentRenderer
{
    /**
     * @param  string  $html  Documento completo, sin ninguna referencia a la red: el
     *                        producto se instala en servidores sin salida a internet
     *                        (ADR-016).
     * @param  string  $footerHtml  Fragmento que el motor repite **en cada pagina**. Va
     *                              aparte y no dentro de `$html` porque un pie escrito en
     *                              el cuerpo solo aparece una vez, al final del flujo: el
     *                              sello de RF-IN-04 tiene que estar en todas las hojas,
     *                              tambien en la que alguien fotocopie suelta.
     * @return string Bytes del PDF.
     *
     * Lanza `ReportRenderingUnavailable` —de `Application\Exception`, nombrada en
     * prosa porque un puerto no puede depender de la capa que lo consume— cuando
     * el motor no esta disponible o falla.
     */
    public function renderPdf(string $html, string $footerHtml): string;
}

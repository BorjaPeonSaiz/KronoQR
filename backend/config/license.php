<?php

declare(strict_types=1);

/*
 * Licencia del producto (RF-PD-04, ADR-018, tarea 5.3).
 *
 * CUATRO PARAMETROS Y NINGUNO MAS, y de los cuatro solo uno lo toca un cliente. La
 * licencia se configura sola: lo que ha contratado viaja DENTRO de la clave
 * firmada —cliente, plan, limites, funcionalidades y vigencia— y no en este
 * fichero (regla dura 13, ADR-017). Aqui esta lo que la clave no puede traer:
 * con que clave publica se verifica (la fija el fabricante al construir la
 * imagen), con cuanta antelacion avisa esta instalacion (lo unico que un cliente
 * cambia alguna vez), cuanto vive la copia que lee la sonda de salud (detalle de
 * despliegue) y con que clave arranca el instalador (que deja de importar en
 * cuanto hay fila).
 *
 * LO QUE NO ESTA AQUI, Y NO PUEDE ESTAR:
 *
 *   - Ninguna clave privada. La emision es un proceso del FABRICANTE y su
 *     herramienta vive en `tools/license-issuer/`, en la raiz del repositorio,
 *     que no se copia a ninguna imagen (§7.7, RS-08).
 *   - Ninguna forma de desactivar el fichaje, la consulta del registro, la
 *     exportacion para la Inspeccion, el portal, la auditoria, las correcciones,
 *     las copias ni las sondas. No hay interruptor porque no existe el concepto
 *     (regla dura 15, ADR-019, ADR-023).
 *   - Ningun `max_sites`. Una licencia es un centro (ADR-040).
 */

return [

    /*
     * Clave publica ed25519 del fabricante, en hexadecimal de 64 caracteres.
     *
     * ES LA UNICA MITAD DEL PAR QUE VIAJA EN EL PRODUCTO. Con ella se verifica;
     * para emitir hace falta la privada, que el fabricante custodia fuera de
     * este repositorio y que no aparece aqui en ninguna forma.
     *
     * COMO SE RELLENA, EXACTAMENTE. No hay ninguna constante que tocar: lo que
     * se edita es **el segundo argumento de `env()` de la linea de abajo**, que
     * hoy es la cadena vacia. El fabricante genera el par UNA VEZ con
     * `php tools/license-issuer/generate-keypair.php`, guarda la privada en su
     * gestor de secretos y sustituye ese `''` por los 64 caracteres
     * hexadecimales de la publica, antes de construir la primera imagen de
     * release. A partir de ese momento va compilada en el producto y es la misma
     * en las veinte instalaciones.
     *
     * `make release-gate` falla si esa sustitucion no se ha hecho, para que una
     * imagen de entrega no pueda salir rechazando la licencia del cliente.
     *
     * VACIA SIGNIFICA «ESTA COMPILACION NO PUEDE VERIFICAR NINGUNA CLAVE», que
     * es el estado de un arbol de desarrollo. No es una averia: el sistema
     * arranca, se ficha, se consulta el registro y se exporta para la Inspeccion
     * exactamente igual; lo unico que ocurre es que la licencia queda en
     * `unverifiable` con motivo `no_public_key` y las funcionalidades accesorias
     * no estan disponibles. `license:show` lo dice con esas palabras y `doctor`
     * (5.9) lo avisara.
     *
     * `LICENSE_PUBLIC_KEY` la sobrescribe. Existe para dos usos y solo dos: la
     * suite de pruebas, que genera un par al vuelo en cada ejecucion, y la
     * rotacion de urgencia del par del fabricante sin esperar a una version
     * nueva. NO es un parametro que un cliente deba tocar.
     */
    'public_key' => (string) env('LICENSE_PUBLIC_KEY', ''),

    /*
     * Con cuantos dias de antelacion empieza a avisar el panel de que la
     * licencia caduca.
     *
     * 30 de serie (decision del responsable de producto, 01-09-2026): es el
     * plazo con el que una renovacion se tramita sin prisa —presupuesto, pedido
     * y emision— y es corto como para que el aviso siga significando algo cuando
     * aparece. Con 90 se convierte en parte del decorado y nadie lo lee el dia
     * que importa.
     *
     * Durante esos dias NO SE DEGRADA NADA: la licencia esta vigente y todo lo
     * contratado sigue disponible. Lo unico que cambia es que aparece un banner
     * que dice cuando caduca, que se degradara y como renovar.
     *
     * `0` deja el aviso para el dia de la caducidad. Se admite porque es una
     * eleccion legitima de una instalacion que renueva por otra via, pero no es
     * lo recomendable y la guia de cliente lo dice.
     */
    'expiry_warning_days' => (int) env('LICENSE_EXPIRY_WARNING_DAYS', 30),

    /*
     * Cuantos segundos vive en cache el estado que lee la sonda `GET /health`.
     *
     * NO ES UNA CACHE DE LA LICENCIA. El estado que gobierna el producto se
     * recalcula siempre desde la clave firmada, sin cache: una clave recien
     * activada surte efecto en el acto. Esto es solo la copia que la sonda de
     * vida puede leer SIN TOCAR LA BASE DE DATOS, porque una sonda de vida que
     * consulta PostgreSQL hace que Docker reinicie el contenedor de PHP cuando
     * lo caido es PostgreSQL.
     *
     * 600 s: lo bastante largo para que la sonda tenga respuesta aunque no haya
     * entrado nadie al panel en un rato, y lo bastante corto para que un cambio
     * se vea en la sonda dentro de la misma mañana. Si expira, la sonda
     * responde `unknown`, que es la verdad; el dato autoritativo esta en
     * `GET /api/v1/license` y en `license:show`.
     */
    'health_probe_ttl_seconds' => (int) env('LICENSE_HEALTH_PROBE_TTL_SECONDS', 600),

    /*
     * La clave con la que el INSTALADOR activa la licencia la primera vez
     * (`LICENSE_KEY` del Anexo B).
     *
     * NO SE LEE EN EJECUCION, Y NO PUEDE LEERSE. La unica linea de todo el
     * producto que la mira es `license:activate` cuando se le invoca sin
     * argumento, que es como la llamara el instalador de la tarea 5.4. A partir
     * de ahi manda la fila de la tabla `license`, igual que manda la fila de
     * `installation_settings` sobre `BRANDING_*` (decision de la tarea 5.1).
     *
     * Si se leyera en ejecucion, una clave activada desde el panel no surtiria
     * efecto mientras el `.env` dijera otra cosa: el cliente veria la clave
     * nueva guardada y la vieja aplicandose, que es el peor de los dos mundos.
     */
    'bootstrap_key' => (string) env('LICENSE_KEY', ''),

];

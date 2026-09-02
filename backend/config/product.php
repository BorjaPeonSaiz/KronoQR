<?php

declare(strict_types=1);

/*
 * Modulo Product — lo que es del DESPLIEGUE, no de la instalacion.
 *
 * OJO A LA DISTINCION, QUE ES LA RAZON DE SER DEL MODULO. Marca, idiomas y
 * umbrales operativos NO estan aqui: viven en `installation_settings`, se editan
 * desde el panel sin reiniciar nada y cada cambio queda auditado (RF-PD-01,
 * ADR-017). Su catalogo, con sus valores de serie, es
 * `App\Modules\Product\Domain\ValueObject\SettingKey`.
 *
 * Lo que sI cabe en este fichero es lo que no tiene sentido editar sin
 * reiniciar y no se audita: hoy, cada cuanto se repite un aviso tecnico.
 */

return [

    /*
     * Cada cuantos segundos se repite el aviso de que la configuracion guardada
     * tiene una fila que no se puede aplicar.
     *
     * Quien lee la configuracion es el camino de fichaje: `RegisterScanHandler`
     * la pide en CADA escaneo, asi que un `warning` por lectura serian cincuenta
     * por segundo en un cambio de turno (RNF-P-06). Se agrupa por ventana, con la
     * misma palanca que ADR-037 aplica a las lecturas de datos personales:
     * agrupar por frecuencia sin quitar el aviso.
     *
     * Alineado con el TTL de la cache de configuracion (300 s), que es el otro
     * plazo en el que una corrupcion introducida a mano se hace visible. Una
     * anomalia NUEVA se anuncia de inmediato aunque la anterior siga dentro de su
     * ventana: la firma entra en la clave.
     *
     * `0` desactiva la agrupacion y deja un aviso por lectura. Solo tiene sentido
     * mientras se depura algo, nunca en produccion.
     */
    'settings_anomaly_window_seconds' => (int) env('PRODUCT_SETTINGS_ANOMALY_WINDOW_SECONDS', 300),

    /*
     * Peticiones por minuto y por origen de las DOS rutas publicas del asistente
     * de puesta en marcha (RF-PD-03): `GET /api/v1/setup/status` y
     * `POST /api/v1/setup/administrator`.
     *
     * ZONA PROPIA Y NO `throttle:auth`, porque aquella compone su clave por
     * cuenta con el `email` del cuerpo y aqui no hay ninguna cuenta a la que
     * contar: la que se va a crear todavia no existe. Con la zona de acceso,
     * todo el trafico del asistente compartiria el cubo de la cadena vacia —el
     * mismo fallo que la tarea 2.1 corrigio en `/auth/2fa/*`— y ademas gastaria
     * el cupo de acceso de la persona que esta a punto de entrar.
     *
     * 10 NO ES UNA MEDICION: la puesta en marcha la hace una persona, una vez, y
     * consulta el estado entre paso y paso. Deja margen de sobra para eso y
     * corta un bucle en el primer segundo. Se puede subir si una instalacion con
     * NAT delante deja al panel compartiendo IP con media oficina.
     */
    'setup_rate_limit_per_minute' => (int) env('PRODUCT_SETUP_RATE_LIMIT', 10),

];

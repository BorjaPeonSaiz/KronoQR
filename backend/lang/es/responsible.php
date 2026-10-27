<?php

declare(strict_types=1);

/*
 * La firma de las ordenes de consola con valor legal (doc 01
 * §7.2 «Firmas por consola»). Lo lee quien teclea la orden en el servidor.
 */

return [
    'missing' => 'Falta --responsible: el uuid o el correo de la cuenta de gestión que firma. No se ha escrito nada.',
    'numeric_id' => '--responsible ya no admite el id interno de la cuenta («:value»): desde la 2.3.0 se escribe su uuid o su correo, por ejemplo --responsible=persona@hotel.es. No se ha escrito nada.',
    'malformed' => '--responsible tiene que ser el uuid o el correo de la cuenta de gestión que firma; «:value» no es ninguno de los dos. No se ha escrito nada.',
    'not_found' => 'No hay ninguna cuenta de gestión con «:value». No se ha escrito nada.',
    'inactive' => 'La cuenta :email está dada de baja y no puede firmar. No se ha escrito nada.',
    'wrong_role' => 'La cuenta :email no tiene ninguno de los roles que firman esta acción (:roles): la firma quien puede hacer lo mismo desde el panel. No se ha escrito nada.',
    'signature' => 'Firma: :name <:email> · :roles · :uuid',
    'confirm_missing' => 'Sin terminal o con --force, confirma quién firma con --responsible-confirm=<correo de la cuenta>. No se ha escrito nada.',
    'confirm_mismatch' => '--responsible-confirm no coincide con el correo de la cuenta que firma (:email). No se ha escrito nada.',
    'cancelled' => 'Cancelado. No se ha escrito nada.',
    'no_longer_allowed' => 'La cuenta de --responsible ya no es una cuenta de gestión activa con el rol que firma esta acción: se ha dado de baja o ha cambiado de rol mientras se confirmaba. No se ha escrito nada.',
];

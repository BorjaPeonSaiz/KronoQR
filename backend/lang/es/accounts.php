<?php

declare(strict_types=1);

/*
 * Textos de las cuentas de gestion (RF-ID-10): el alta, la baja, los
 * restablecimientos y el cambio de la contrasena propia.
 *
 * Dicen QUE HACER, no solo que ha fallado: quien los lee es un administrador
 * del hotel delante del panel, sin la consola del servidor.
 */

return [

    'email_taken' => 'Ya hay una cuenta de gestión con ese correo, activa o dada de baja. '
        .'Usa otro correo: el de una baja no se reasigna.',

    'own_account_deactivation' => 'No puedes dar de baja tu propia cuenta. Pide a otra persona con rol admin que lo haga.',

    'last_active_admin' => 'Es la última cuenta admin activa. Crea otra antes de dar de baja esta.',

    'own_account_password_reset' => 'No puedes restablecer tu propia contraseña. Cámbiala tú en «Cambiar contraseña», '
        .'que pide la actual.',

    'own_account_two_factor_reset' => 'No puedes retirar tu propio segundo factor. Pide a otra persona con rol admin que lo haga.',

    'two_factor_not_enrolled' => 'Esa cuenta no tiene segundo factor activo: no hay nada que retirar.',

    'reauthentication_failed' => [
        'actor_totp_code' => 'El código no es correcto o ya se ha usado. Espera al siguiente.',
        'actor_current_password' => 'Tu contraseña no es correcta.',
    ],

    'password_too_long' => 'La contraseña no puede ocupar más de :max bytes. Usa una más corta o con menos caracteres especiales.',

    'current_password_incorrect' => 'La contraseña actual no es correcta.',

    'new_password_same' => 'La contraseña nueva tiene que ser distinta de la actual.',

    'password_changed_meanwhile' => 'Tu contraseña ha cambiado mientras la editabas. Vuelve a entrar.',

    'password_change_required' => 'Tu contraseña es temporal. Cámbiala para continuar.',

];

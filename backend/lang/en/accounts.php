<?php

declare(strict_types=1);

/*
 * Management account texts (RF-ID-10): creation, deactivation, resets and
 * changing one's own password.
 */

return [

    'email_taken' => 'There is already a management account with that email, active or deactivated. '
        .'Use another email: a deactivated account keeps its address.',

    'own_account_deactivation' => 'You cannot deactivate your own account. Ask another admin to do it.',

    'last_active_admin' => 'This is the last active admin account. Create another one before deactivating it.',

    'own_account_password_reset' => 'You cannot reset your own password. Change it yourself under «Change password», '
        .'which asks for the current one.',

    'own_account_two_factor_reset' => 'You cannot remove your own second factor. Ask another admin to do it.',

    'two_factor_not_enrolled' => 'That account has no active second factor: there is nothing to remove.',

    'reauthentication_failed' => [
        'actor_totp_code' => 'The code is not correct or has already been used. Wait for the next one.',
        'actor_current_password' => 'Your password is not correct.',
    ],

    'password_too_long' => 'The password cannot be longer than :max bytes. Use a shorter one or fewer special characters.',

    'current_password_incorrect' => 'The current password is not correct.',

    'new_password_same' => 'The new password must be different from the current one.',

    'password_changed_meanwhile' => 'Your password changed while you were editing it. Sign in again.',

    'password_change_required' => 'Your password is temporary. Change it to continue.',

];

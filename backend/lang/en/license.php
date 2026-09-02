<?php

declare(strict_types=1);

/*
 * License texts (RF-PD-04, RF-PD-05, task 5.3). See `lang/es/license.php` for
 * the reasoning: every message states what happened, what still works and what
 * to do, because ADR-019 requires the degradation to be honest.
 */

return [

    /*
     * Date format for this language: 31 Dec 2026.
     *
     * La fecha del aviso de degradacion la formatea el BORDE en el idioma
     * negociado, no el dominio: si la formateara el dominio, el mensaje ingles
     * saldria con la fecha en formato español.
     */
    'since_format' => 'j M Y',

    'attributes' => [
        'signed_key' => 'license key',
    ],

    'unavailable' => [
        'license_expired' => 'This feature is unavailable because the licence expired on :since. '
            .'Clocking in and out, work day lookups, the employee portal and the labour inspection '
            .'export keep working normally. Renew the licence with your provider and activate it '
            .'under Settings › Licence to get this back.',
        'license_absent' => 'This feature is unavailable because no licence has been activated yet. '
            .'Clocking in and out, work day lookups, the employee portal and the labour inspection '
            .'export work normally. Activate the key your provider gave you under Settings › Licence.',
        'license_unverifiable' => 'This feature is unavailable because the stored licence cannot be '
            .'verified. Clocking in and out, work day lookups, the employee portal and the labour '
            .'inspection export work normally. Check Settings › Licence, which explains what to do.',
        'license_not_yet_valid' => 'This feature becomes available on :since, when the activated '
            .'licence starts. Nothing to do until then: the rest of the system works normally.',
        'not_in_plan' => 'This feature is not part of your plan. Talk to your provider if you want to '
            .'extend it.',
        'unknown' => 'This feature is unavailable with the current licence. Check its status under '
            .'Settings › Licence.',
    ],

    'errors' => [
        'rejected' => [
            'malformed' => 'The key is incomplete or truncated, which usually happens when copying it '
                .'from an email. Copy the whole key — it starts with "KQL1." and contains no spaces or '
                .'line breaks — and try again. Your previous licence is untouched.',
            'bad_signature' => 'This key was not issued by the vendor of this version, or it was '
                .'modified in transit. Ask your provider for a new key. Your previous licence is '
                .'untouched.',
            'invalid_payload' => 'The key is signed but incomplete. This is an issuing fault, not a '
                .'copy-paste problem: tell your provider and ask for a new key. Your previous licence '
                .'is untouched.',
            'no_public_key' => 'This installation cannot verify any licence because it does not carry '
                .'the vendor public key. This is not a problem with your key, it is a deployment '
                .'problem: tell your provider, quoting the version returned by GET /api/v1/health.',
        ],

        'missing_field' => 'The key is missing the ":field" field. This is an issuing fault: ask your provider for a new key.',
        'field_not_text' => 'The ":field" field of the key has no valid value. This is an issuing fault: ask your provider for a new key.',
        'field_not_integer' => 'The ":field" field of the key should be a number. This is an issuing fault: ask your provider for a new key.',
        'limit_not_positive' => 'The ":field" field of the key is :value and should be greater than zero. This is an issuing fault: ask your provider for a new key.',
        'field_not_a_date' => 'The ":field" field of the key is not a valid date. This is an issuing fault: ask your provider for a new key.',
        'validity_inverted' => 'The key validity ends (:until) before it starts (:from). This is an issuing fault: ask your provider for a new key.',
        'features_not_a_list' => 'The feature list in the key does not have the expected format. This is an issuing fault: ask your provider for a new key.',
    ],
];

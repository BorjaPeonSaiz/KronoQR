{{--
    La plantilla de TODOS los correos del producto (R6-PL-07, V6-PL-05).

    Sustituye a `notifications::email` de Laravel por dos motivos que el hotel ve
    en cada correo:

    1. LA MARCA. La de Laravel pone `config('app.name')` en la cabecera, en la
       despedida y en el pie, y `APP_NAME` no es la marca: es el prefijo de la
       cache y de las colas, y la guia prohibe cambiarlo. Aqui los tres huecos
       llevan `$brandName`, el nombre de la instalacion que se configura en el
       panel (`BRANDING_APP_NAME`, regla dura 13). Nada de un cliente esta escrito
       en este fichero.
    2. EL IDIOMA. La de Laravel escribe «Regards,» y «All rights reserved.» con
       el idioma del proceso, que en una orden programada es el de serie. Aqui no
       hay ningun texto: la despedida llega ya traducida al idioma del
       destinatario desde `lang/<idioma>/mail.php`.

    Variables: las de `MailMessage` (`greeting`, `introLines`, `outroLines`,
    `actionText`, `actionUrl`, `salutation`) mas `brandName`. La compone
    `BrandedMailMessage`; ninguna notificacion la nombra a mano.

    Es Markdown de correo: sin sangria, o CommonMark lo tomaria por codigo.
--}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ $brandName }}
</x-mail::header>
</x-slot:header>

@if (! empty($greeting))
# {{ $greeting }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}<br>
{{ $brandName }}
@endif

<x-slot:footer>
<x-mail::footer>
{{ $brandName }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

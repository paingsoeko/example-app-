<x-mail::message>
# Email delivery works

This is a test email sent from the **{{ config('app.name') }}** feature test page.

- Environment: {{ app()->environment() }}
- Mailer: {{ config('mail.default') }}
- Sent at: {{ $sentAt }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

<?php

namespace App\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends mail via Brevo's transactional HTTP API (https://api.brevo.com)
 * instead of SMTP. Render blocks outbound traffic on ports 25/465/587 for
 * anything but paid instances, which is what was causing the
 * "Connection timed out" TransportException when using
 * smtp-relay.brevo.com:587 directly. The API runs over standard HTTPS
 * (port 443), which is never blocked, so this sidesteps the problem
 * entirely regardless of Render plan.
 *
 * Usage stays close to Mail::to()->send($mailable) — pass any Mailable
 * and this renders its Blade view to HTML and ships it through the API.
 * The Mailable's own envelope() subject is used automatically.
 */
class BrevoMailer
{
    public static function send(string $toEmail, string $toName, Mailable $mailable): void
    {
        $apiKey = config('services.brevo.key');

        if (empty($apiKey)) {
            throw new RuntimeException('BREVO_API_KEY is not set in .env / config/services.php.');
        }

        $html = $mailable->render();
        $subject = $mailable->envelope()->subject
            ?? $mailable->subject
            ?? config('app.name');

        $response = Http::withHeaders([
            'api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', [
            'sender' => [
                'name' => config('mail.from.name'),
                'email' => config('mail.from.address'),
            ],
            'to' => [
                ['email' => $toEmail, 'name' => $toName ?: $toEmail],
            ],
            'subject' => $subject,
            'htmlContent' => $html,
        ]);

        if ($response->failed()) {
            // Surface Brevo's actual error body (e.g. unverified sender,
            // bad API key) so it shows up in the job's failed() log
            // instead of a generic HTTP exception message.
            throw new RuntimeException('Brevo API error ('.$response->status().'): '.$response->body());
        }
    }
}

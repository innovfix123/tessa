<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Cloud API sender — the freelance recruiters (Yashasvi, Rohit) are
 * external, so they're notified over WhatsApp rather than Slack. Mirrors
 * SlackService's shape (config in constructor, bool return, full logging).
 *
 * DRY-RUN: when the Meta credentials aren't configured yet (token +
 * phone_number_id), sendTemplate() logs the intended message and returns false
 * instead of erroring — so the Hiring flow is fully usable before the Meta
 * Business verification + template approval lands. Swap nothing once the env is
 * filled in; it starts sending for real on the next request.
 */
class WhatsAppService
{
    private string $token;
    private string $phoneNumberId;
    private string $graphVersion;
    private string $language;
    private string $defaultCc;

    public function __construct()
    {
        $this->token = (string) config('whatsapp.token', '');
        $this->phoneNumberId = (string) config('whatsapp.phone_number_id', '');
        $this->graphVersion = (string) config('whatsapp.graph_version', 'v21.0');
        $this->language = (string) config('whatsapp.language', 'en');
        $this->defaultCc = (string) config('whatsapp.default_country_code', '91');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->phoneNumberId !== '';
    }

    /**
     * Send a pre-approved template message.
     *
     * @param  string    $toPhone     recipient phone (any format; normalised here)
     * @param  string    $template    approved template name
     * @param  string[]  $bodyParams  ordered {{1}},{{2}}… body substitutions
     */
    public function sendTemplate(string $toPhone, string $template, array $bodyParams = [], ?string $lang = null): bool
    {
        $to = $this->normalise($toPhone);
        if ($to === '') {
            Log::warning('WhatsAppService::sendTemplate empty/invalid number', ['raw' => $toPhone]);
            return false;
        }

        // DRY-RUN until Meta creds exist — log and bail without erroring.
        if (! $this->isConfigured()) {
            Log::info('WhatsAppService DRY-RUN (not configured)', [
                'to' => $to,
                'template' => $template,
                'params' => $bodyParams,
            ]);
            return false;
        }

        try {
            $resp = Http::withToken($this->token)->post(
                "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/messages",
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $lang ?: $this->language],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(
                                fn ($p) => ['type' => 'text', 'text' => (string) $p],
                                array_values($bodyParams)
                            ),
                        ]],
                    ],
                ]
            );

            if (! $resp->successful()) {
                Log::error('WhatsAppService::sendTemplate HTTP error', [
                    'to' => $to,
                    'template' => $template,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                return false;
            }

            Log::debug('WhatsAppService::sendTemplate success', ['to' => $to, 'template' => $template]);
            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsAppService::sendTemplate exception', [
                'to' => $to,
                'template' => $template,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Reduce a free-form number to WhatsApp's digits-only international form.
     * Bare 10-digit numbers get the default country code (India 91) prepended.
     */
    private function normalise(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10) {
            $digits = $this->defaultCc . $digits;
        }
        return $digits;
    }
}

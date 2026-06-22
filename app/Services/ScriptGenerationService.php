<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ScriptGenerationService
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    // Moved to gemini-2.5-flash on 2026-06-04 (two-tier policy: only
    // OCR/insights/extraction stay premium; everything else → Flash). Was
    // anthropic/claude-3.5-sonnet.
    private const MODEL = 'google/gemini-2.5-flash';

    /**
     * Winning reference scripts (tone, length, CTA style). Grouped by category.
     *
     * @var array<string, list<string>>
     */
    private const REFERENCES_BY_CATEGORY = [
        'loneliness' => [
            'There are many friends who say \'Let\'s hang out\' during fun times. But when life gets tough, only a true friend says, \'I\'m here for you.\' You can find such friends only on Hi-Ma. Ignore fake friends. Connect with real friends on Hi-Ma. Download it now and share your thoughts. Everything you share stays 100% private.',
            'Do you feel more pressure as you get older? Work, family, and responsibilities keep adding up. As a man, you\'re expected to handle everything alone. But you don\'t have to struggle quietly. Hi-Ma gives you a safe and private space to talk to people who understand you. All calls are 100% private and secure. Download the Hi-Ma app now and start your first conversation.',
            'Are you waiting for your crush to notice you? Bro, don\'t stress too much! On Hi-Ma, if not that person, there\'ll be someone else who understands you. Spend a few minutes — maybe you\'ll meet someone special. Don\'t overthink it; download the app and start talking.',
            'Fake friends only show up for the good times. True friends stay for the hard ones. You can find those real, caring people on Hi-Ma. Safe, private, and understanding. Download the Hi-Ma app now.',
        ],
        'relationship' => [
            'In a relationship, just as Love is common, arguments are equally common. Don\'t let a small miscommunication lead to breaking the relationship. Share your problem, and get a third-person perspective. If you talk to the experienced friends on Hima, you will find a solution. Reduce the arguments, increase the love. Download Hima app now.',
            'Love grows, but so do arguments. What matters is how you handle them. Share what\'s on your mind. Sometimes a fresh perspective makes everything clearer. On Hi-Ma, you get support, guidance, and understanding. Choose love over conflict. Download Hi-Ma now.',
            'Love is real, but fights feel real too. One calm conversation can change everything. Hi-Ma gives you a safe space to talk it out and understand each other better. Download Hi-Ma. Reduce arguments, increase love.',
        ],
        'breakup' => [
            'A breakup doesn\'t mean the end of you. It\'s the end of one chapter — and the start of finding yourself again. On Hi-Ma, real people listen without judgment. Talk to someone who gets it. Download the Hi-Ma app now.',
            'The hardest part of a breakup isn\'t the fight. It\'s the silence after. When your mind replays everything, don\'t face it alone. Hi-Ma connects you with real people who understand heartbreak. Talk freely. Heal slowly. Download Hi-Ma now.',
            'You deleted the photos. You changed the playlist. But some memories stick. That\'s okay. On Hi-Ma, you can talk to someone real — no advice you didn\'t ask for, just someone who listens. Download the Hi-Ma app and let it out.',
        ],
        'talk_to_someone' => [
            'Do you feel shy when you want to talk to girls? Don\'t worry, on Hi-ma you can talk to girls you like, with full privacy. So, download the Hi-ma app now and start talking.',
            'Want to talk to a girl but feel nervous? Starting a conversation doesn\'t have to be hard. Hi-Ma helps you talk freely, with full privacy. Open Hi-Ma and start talking today.',
            'Want to talk to someone but don\'t know how to start? You\'re not alone. Hi-Ma helps you begin simple, private conversations. Someone is ready to listen. Open Hi-Ma and start talking.',
            'Sometimes you just need one real conversation. Not advice — just someone who listens. On Hi-Ma, those people exist. Download the app. Say hi. That\'s all it takes.',
        ],
        'sleepless_night' => [
            'Is it already late, but still can\'t fall asleep? You don\'t have to handle everything in your mind all by yourself. On Hi-Ma, there are people ready to listen to you even now. Open the Hi-Ma app and talk for free; feel lighter in your heart before going to sleep.',
            'Late nights can feel very heavy when emotions build up. Hi-Ma connects you with people who understand you and are ready to listen. Open the app and talk for free, and give your mind the rest it needs.',
            'Even though it\'s 3 AM, are you unable to sleep? Are your thoughts running non-stop in your head? Don\'t worry, many people are waiting to talk with you on the Hi-ma app. Just open the app, and speak your heart out. Download the Hi-ma app now, and sleep peacefully.',
            'It\'s midnight. Everyone\'s asleep. But your mind won\'t stop. That feeling is real — and it\'s heavier in the dark. On Hi-Ma, someone is awake and ready to listen. Open the app. Talk. Breathe. Sleep. Download Hi-Ma now.',
        ],
        'gender_specific' => [
            'As a man, you\'re expected to handle everything alone. But carrying it all quietly isn\'t strength — it\'s exhaustion. Hi-Ma gives you a safe, private space to talk to someone who actually listens. No judgment. Just real conversation. Download the Hi-Ma app now.',
            'Nobody tells men it\'s okay to feel overwhelmed. But it is. And there\'s a space for that. On Hi-Ma, real people listen — without telling you to \'man up\'. All calls are 100% private. Download the app and just talk.',
            'Society says men don\'t need to talk. But your mind doesn\'t follow those rules, does it? On Hi-Ma, you can finally say what\'s been building up — privately, safely, with someone who cares. Download Hi-Ma now.',
        ],
        'moving_on' => [
            'Letting go doesn\'t mean forgetting. It means opening the door to a new chapter. Take a simple step and get started. Real people, real understanding — no judgment, only support. Download the Hi-Ma app now and lighten the burden in your heart. Talk for free. Heal quietly. Move forward in life happily.',
            'Moving on is not about forgetting, it\'s about starting a new life. Begin that journey with a single call. Real people who understand you are here. No judging. Only healing. Download the Hi-ma app and reduce the sorrow in your heart. Talk freely, Move on happily.',
            'The past exists, but it shouldn\'t hold you back. Take a small step and reach out. On Hi-Ma, real people listen to you, care for you, and support you. No judgment — just understanding. Download the app and turn sorrow into strength.',
            'You\'ve cried enough. You\'ve replayed it enough. Now it\'s time to move. Not because it didn\'t matter — but because you do. Talk to someone on Hi-Ma who understands. Private. Real. Supportive. Download the Hi-Ma app now.',
        ],
    ];

    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'loneliness' => 'Loneliness',
        'relationship' => 'Relationship',
        'breakup' => 'Breakup',
        'talk_to_someone' => 'Talk to Someone',
        'sleepless_night' => 'Sleepless Night',
        'gender_specific' => 'Gender Specific',
        'moving_on' => 'Moving On',
    ];

    private const TOPIC_LABELS = [
        'general' => 'General',
    ];

    private const LANGUAGE_LABELS = [
        'telugu' => 'Telugu',
        'tenglish' => 'Tenglish (Telugu + English mix)',
        'kannada' => 'Kannada',
        'kanglish' => 'Kanglish (Kannada + English mix)',
        'tamil' => 'Tamil',
        'tanglish' => 'Tanglish (Tamil + English mix)',
        'malayalam' => 'Malayalam',
        'manglish' => 'Manglish (Malayalam + English mix)',
        'bengali' => 'Bengali',
        'benglish' => 'Benglish (Bengali + English mix)',
        'hindi' => 'Hindi',
        'hinglish' => 'Hinglish (Hindi + English mix)',
        'english' => 'English',
    ];

    /**
     * Each script must use a different creative angle (cycled by index).
     *
     * @var list<string>
     */
    private const CREATIVE_ANGLES = [
        'Mini-story: Open with a tiny concrete scenario; narrate 2–3 beats before weaving in Hi-Ma.',
        'Bold challenge: Provocative or direct challenge to the viewer — avoid a generic question hook.',
        'Humor / playful: Light, witty tone; still land a sincere CTA at the end.',
        'Emotional gut-punch: Raw, vulnerable opener about a feeling everyone recognizes.',
        'Slice of life: Everyday micro-moment (commute, tea stall, phone screen) as the frame.',
        'Contrast / comparison: Two opposites (before vs after, fake vs real, noise vs relief).',
        'Direct address: Talk straight to the viewer — intimate monologue pacing.',
        'Poetic / lyrical: Short rhythmic lines with emotional weight; avoid samey prose blocks.',
    ];

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = (string) config('services.openrouter.api_key', '');
    }

    public static function validLanguages(): array
    {
        return array_keys(self::LANGUAGE_LABELS);
    }

    public static function validCategories(): array
    {
        return array_keys(self::CATEGORY_LABELS);
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? ucfirst(str_replace('_', ' ', $category));
    }

    /** @return list<array{value: string, label: string}> */
    public static function categoriesForFrontend(): array
    {
        return array_map(
            fn (string $k, string $v) => ['value' => $k, 'label' => $v],
            array_keys(self::CATEGORY_LABELS),
            array_values(self::CATEGORY_LABELS)
        );
    }

    public static function validTopics(): array
    {
        return array_keys(self::TOPIC_LABELS);
    }

    public static function topicLabel(string $topic): string
    {
        return self::TOPIC_LABELS[$topic] ?? $topic;
    }

    public static function languageLabel(string $lang): string
    {
        return self::LANGUAGE_LABELS[$lang] ?? $lang;
    }

    /**
     * Build per-script mandatory angle lines for the user message.
     */
    private static function buildAngleAssignmentBlock(int $count): string
    {
        $angles = self::CREATIVE_ANGLES;
        $nAngles = count($angles);
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $num = $i + 1;
            $lines[] = '- Script '.$num.': MUST use this creative angle → '.$angles[$i % $nAngles];
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public function generateScripts(
        string $language,
        string $category,
        ?string $creativeBrief,
        int $count,
    ): array {
        if ($this->apiKey === '') {
            Log::warning('ScriptGenerationService: OPENROUTER_API_KEY not configured');

            return [];
        }

        $refs = self::REFERENCES_BY_CATEGORY[$category] ?? array_merge(
            self::REFERENCES_BY_CATEGORY['loneliness'],
            self::REFERENCES_BY_CATEGORY['relationship']
        );

        $refsText = implode("\n\n---\n\n", array_slice($refs, 0, 8));
        $langHuman = self::languageLabel($language);
        $brief = trim((string) $creativeBrief);
        $angleBlock = self::buildAngleAssignmentBlock($count);

        $briefRules = '';
        if ($brief !== '') {
            $minBriefScripts = max(1, (int) ceil($count * 0.8));
            $briefRules = <<<BRIEF

CREATIVE IDEA (THIS IS THE MAIN DIRECTION — MANDATORY):
The team's idea: "{$brief}"
- At least {$minBriefScripts} of the {$count} scripts MUST be built entirely around this idea, scenario, or setting.
- Don't just mention it in passing — weave it through the opening hook, middle, and CTA.
- The remaining scripts can explore the category more broadly, but must still feel connected to the vibe.
BRIEF;
        }

        $systemPrompt = <<<SYS
You write short UGC-style ad scripts for Hi-Ma (Hima), a social app where users can have private voice/video conversations with real people — empathetic, no judgment, South India audience, many male users seeking emotional support or connection.

CRITICAL — VARIETY (read carefully):
- Each script MUST follow its assigned CREATIVE ANGLE from the user message. Do not default every script to "question → Hi-Ma exists → download."
- No two scripts may open with the same sentence type (e.g. not all questions, not all imperatives). Rotate: question, statement, scenario, direct address, contrast, etc.
- No two scripts may use the same closing CTA phrasing — vary how you invite download/open app (different verbs, rhythm, length).
- Vary length visibly: some scripts ~3–4 short lines; others ~5–7 lines. Do not make every card the same shape.
- References below are for TONE and BRAND VOICE only — do not copy structure or repeat the same narrative arc across scripts.

BASE STYLE (still required):
- 2–6 short paragraphs or line breaks per script; roughly 35–130 words unless the angle needs tighter poetry.
- Warm, direct, conversational; "bro" tone only when it fits crush/dating angles.
- Mention Hi-Ma / Hi-ma naturally; include a clear CTA to download or open the app.
- Emphasize privacy, real people who listen, talk for free where it fits.
- Do NOT invent fake statistics. Do NOT mention prices/coins unless the brief asks.

OUTPUT: Return ONLY valid JSON with no markdown fences:
{"scripts":["script 1 text","script 2 text",...]}

The "scripts" array must have exactly {$count} distinct strings. Write every script entirely in {$langHuman}.
SYS;

        $userMsg = "Category: {$category}\n";
        if ($brief !== '') {
            $userMsg .= "Creative idea from the team: {$brief}\n";
        } else {
            $userMsg .= "No specific idea given — create diverse scripts within the {$category} category. Cover different emotions, scenarios, and moments.\n";
        }
        $userMsg .= $briefRules;
        $userMsg .= "\nMANDATORY ANGLE PER SCRIPT (each script must match its assigned row):\n{$angleBlock}\n";
        $userMsg .= "\nREFERENCE WINNING SCRIPTS (English — tone, pacing, CTA energy only; translate into {$langHuman}; do NOT mirror the same hook or story in every script):\n\n{$refsText}";

        $payload = [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMsg],
            ],
            'temperature' => 0.92,
            'max_tokens' => 4096,
        ];

        try {
            $client = new Client([
                'timeout' => 120,
                'connect_timeout' => 15,
            ]);

            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa Script Generation',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim((string) ($body['choices'][0]['message']['content'] ?? ''));

            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);

            $decoded = json_decode($content, true);
            if (! is_array($decoded) || ! isset($decoded['scripts']) || ! is_array($decoded['scripts'])) {
                Log::warning('ScriptGenerationService: invalid JSON from model', ['raw' => mb_substr($content, 0, 500)]);

                return [];
            }

            $scripts = [];
            foreach ($decoded['scripts'] as $s) {
                $t = trim((string) $s);
                if ($t !== '') {
                    $scripts[] = $t;
                }
            }

            return array_slice($scripts, 0, $count);
        } catch (GuzzleException $e) {
            Log::error('ScriptGenerationService OpenRouter failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        } catch (\Throwable $e) {
            Log::error('ScriptGenerationService error', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}

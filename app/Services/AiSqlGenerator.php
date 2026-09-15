<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Optional text-to-SQL via OpenAI-compatible or Anthropic APIs.
 * Keys stay encrypted in the local SQLite settings table; nothing is sent
 * until the user explicitly asks for a translation.
 */
class AiSqlGenerator
{
    public const PROVIDERS = ['openai', 'anthropic', 'openai_compatible'];

    /**
     * @return array{ok: bool, sql?: string, error?: string, provider?: string}
     */
    public function generate(Connection $connection, ?string $database, string $prompt, array $schemaSummary): array
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            return ['ok' => false, 'error' => 'Describe what you want to query first.'];
        }

        if ($database === null || $database === '') {
            return ['ok' => false, 'error' => 'Expand a database in the sidebar first so AI can read its tables and columns.'];
        }

        if ($schemaSummary === []) {
            return ['ok' => false, 'error' => 'No tables found in the active database. AI needs a real schema.'];
        }

        $config = $this->config();

        if ($config['api_key'] === '') {
            return ['ok' => false, 'error' => 'Add an AI API key under Settings (sidebar gear / AI settings).'];
        }

        $dialect = match ($connection->driverName()) {
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            default => 'MySQL/MariaDB',
        };

        $system = $this->buildSystemPrompt($dialect, $database, $schemaSummary);

        try {
            return match ($config['provider']) {
                'anthropic' => $this->callAnthropic($config, $system, $prompt),
                default => $this->callOpenAiCompatible($config, $system, $prompt),
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<int, array{table: string, columns: string[]}>  $schemaSummary
     */
    private function buildSystemPrompt(string $dialect, string $database, array $schemaSummary): string
    {
        $now = now();
        $today = $now->toDateString();
        $year = $now->year;
        $month = $now->month;
        $monthName = $now->format('F');

        return <<<PROMPT
You are a senior {$dialect} DBA writing SQL for database `{$database}` inside a desktop SQL client.
Today's date is {$today} (current year {$year}, current month {$month} = {$monthName}). Use this for any relative time ("this year", "augustus", "last month", "recently") unless the user names another year.

OUTPUT
- Return ONE executable SQL statement (or a short script of related statements if clearly needed).
- Output ONLY SQL. No markdown fences. No essays. No "please clarify" questions.
- At most one short trailing `--` comment if you made a non-obvious assumption (e.g. `-- filtered on <chosen_date_col> for August {$year}`). Prefer no comment when the choice is obvious.
- Prefer SELECT. Never invent DROP/TRUNCATE/DELETE/UPDATE/INSERT/ALTER unless the user explicitly asks to modify data.

SCHEMA DISCIPLINE
- ONLY use tables and columns from the schema below. Never invent or rename tables (do not turn "blogs" into a guessed name unless that exact table exists).
- Match user wording (including Dutch/other languages) to the closest real identifiers: blogs→blog_posts/posts, gebruikers→users, bestellingen→orders, etc., but only if those tables exist in the schema.
- If multiple tables could match, pick the most specific content table (e.g. blog_posts over blogs categories). Prefer tables whose columns fit the asked filters.

BE DECISIVE (cheap models: do NOT stall)
- Always produce a best-effort query when the schema has a plausible match. Do not refuse just because the user omitted a detail you can reasonably default.
- Only if NO table/column in the schema fits at all: return a single `--` line listing the closest table names you considered. Never invent tables.

TIME & DATE DEFAULTS
- Relative periods without a year ("augustus", "in August", "vorige maand", "dit jaar", "last week") use the current calendar year/month relative to today ({$today}).
  Example: "blogs uit augustus" → August {$year}, not a random past year and not "ask which year".
- "Recent" / "laatste" without a window → last 30 days.
- "Today" / "vandaag" → current date; "this week" → current ISO week; "this month" → current month {$year}-{$month}.
- Prefer range predicates when natural for the dialect, e.g. `col >= '{$year}-08-01' AND col < '{$year}-09-01'` (often clearer/index-friendlier than YEAR()/MONTH()). For SQLite use comparable date('now') style functions when needed.
- Never invent a month/year column; always derive periods from a real date/datetime/timestamp column that exists in the schema.

PICKING THE RIGHT DATE/TIME COLUMN
When the user filters by time ("uit augustus", "from last week", "nieuwste", "when it happened"), choose among the table's *actual* date/datetime columns by meaning — names vary per project.
Infer relevance from column name + user intent + table purpose:
1) Semantic fit first: a column whose name suggests the event the user means (publishing, ordering, booking, shipping, logging, scheduling, etc.). Match language loosely (publish/publicatie/release/post, order/bestel, created/aangemaakt, updated/gewijzigd, …) to whatever the schema actually calls it.
2) Prefer the domain event time over bookkeeping times: for content/articles/posts, a "went live / was published / was posted" style column beats a generic "row inserted" column; for orders, an order/purchase date beats updated_at; for appointments, the appointment/start time beats created_at.
3) If several columns look equally plausible, pick the one that best matches the user's words; if still tied, prefer the more specific business timestamp over generic created/updated.
4) Use created/inserted-style columns when that is the only date, or when the user clearly means "when the row was added".
5) Use updated/modified-style columns only when the user asks about changes/edits, or when no better date exists.
6) If filtering by a "went public" period and that chosen column can be NULL (drafts), exclude NULLs unless the user wants drafts/unpublished.
Do not hard-require any specific English name — decide from the schema you were given.

OTHER SMART DEFAULTS
- Soft deletes: if a column clearly means soft-delete/trash (often *deleted*, *trashed*, *removed* + date or flag), exclude those rows unless the user asks for deleted/trash.
- Status/visibility: if filtering public content and columns suggest status/visibility/publish state, prefer the active/published/public-looking values when that matches intent.
- Boolean flags: treat 1/true and common active labels as on when schema suggests it.
- LIMIT: if the user does not ask for "all" explicitly and the result could be huge, you may add a reasonable LIMIT (e.g. 1000). If they say "alle"/"all", do not add LIMIT.
- ORDER BY: for "latest"/"recent"/"nieuwste" order by the chosen date column DESC; for names use alphabetical.
- JOINs: only join tables that exist in the schema and are needed; prefer FK-looking columns (*_id) when joining.
- Aggregations: for "hoeveel"/"count"/"per maand" use COUNT/GROUP BY on real columns.
- String search: use LIKE '%term%' (or ILIKE on PostgreSQL) for fuzzy "zoek"/"met in de titel" requests.
- Case: match identifier casing exactly as in the schema.

Schema (authoritative — these are the only legal tables/columns):
{$this->formatSchema($schemaSummary)}
PROMPT;
    }

    /**
     * @return array{provider: string, api_key: string, model: string, base_url: string}
     */
    public function config(): array
    {
        $raw = Setting::get('ai', []);

        if (! is_array($raw)) {
            $raw = [];
        }

        $key = '';
        if (! empty($raw['api_key_encrypted'])) {
            try {
                $key = Crypt::decryptString($raw['api_key_encrypted']);
            } catch (Throwable) {
                $key = '';
            }
        }

        $provider = in_array($raw['provider'] ?? '', self::PROVIDERS, true) ? $raw['provider'] : 'openai';

        return [
            'provider' => $provider,
            'api_key' => $key,
            'model' => $this->normalizeModel($provider, (string) ($raw['model'] ?? '')),
            'base_url' => rtrim((string) ($raw['base_url'] ?? ''), '/'),
        ];
    }

    public function normalizeModel(string $provider, string $model): string
    {
        $model = trim($model);
        $obsolete = [
            'claude-sonnet-4-20250514',
            'claude-3-5-sonnet-20241022',
            'claude-3-opus-20240229',
        ];

        if ($model === '' || in_array($model, $obsolete, true)) {
            return $this->defaultModel($provider);
        }

        return $model;
    }

    public function saveConfig(string $provider, string $apiKey, string $model = '', string $baseUrl = ''): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            $provider = 'openai';
        }

        $existing = $this->config();
        $encrypted = $existing['api_key'] !== '' && $apiKey === ''
            ? Crypt::encryptString($existing['api_key'])
            : ($apiKey === '' ? null : Crypt::encryptString($apiKey));

        Setting::set('ai', [
            'provider' => $provider,
            'api_key_encrypted' => $encrypted,
            'model' => $model !== '' ? $model : $this->defaultModel($provider),
            'base_url' => $baseUrl,
        ]);
    }

    public function clearKey(): void
    {
        $config = $this->config();
        Setting::set('ai', [
            'provider' => $config['provider'],
            'api_key_encrypted' => null,
            'model' => $config['model'],
            'base_url' => $config['base_url'],
        ]);
    }

    public function defaultModel(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'claude-haiku-4-5-20251001',
            'openai_compatible' => '',
            default => 'gpt-4o-mini',
        };
    }

    /**
     * Fetch models from the provider API when a key is available; otherwise curated fallbacks.
     *
     * @return array{
     *     groups: list<array{label: string, models: list<array{id: string, label: string}}>>,
     *     source: 'api'|'fallback',
     *     error: ?string
     * }
     */
    public function listModels(string $provider, string $apiKey = '', string $baseUrl = ''): array
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            $provider = 'openai';
        }

        if ($apiKey === '') {
            $apiKey = $this->config()['api_key'];
        }

        if ($baseUrl === '') {
            $baseUrl = $this->config()['base_url'];
        }

        $fetched = [];
        $error = null;
        $source = 'fallback';

        if ($apiKey !== '' || $provider === 'openai_compatible') {
            try {
                $fetched = match ($provider) {
                    'anthropic' => $this->fetchAnthropicModels($apiKey, $baseUrl),
                    default => $this->fetchOpenAiCompatibleModels($apiKey, $baseUrl, $provider),
                };
                if ($fetched !== []) {
                    $source = 'api';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $recommendedIds = $this->recommendedModelIds($provider);
        $allIds = $fetched !== [] ? $fetched : $this->fallbackModelIds($provider);

        // Keep recommended that exist (or always for fallback curated list).
        $allSet = array_fill_keys($allIds, true);
        $recommended = [];
        foreach ($recommendedIds as $id) {
            if ($fetched === [] || isset($allSet[$id])) {
                $recommended[] = $id;
            }
        }

        $other = array_values(array_filter($allIds, fn (string $id) => ! in_array($id, $recommended, true)));
        sort($other, SORT_NATURAL | SORT_FLAG_CASE);

        $groups = [];
        if ($recommended !== []) {
            $groups[] = [
                'label' => 'Recommended',
                'models' => array_map(fn (string $id) => ['id' => $id, 'label' => $id], $recommended),
            ];
        }
        if ($other !== []) {
            $groups[] = [
                'label' => $recommended !== [] ? 'All models' : 'Models',
                'models' => array_map(fn (string $id) => ['id' => $id, 'label' => $id], $other),
            ];
        }

        if ($groups === []) {
            $error ??= 'No models available. Check API key / base URL, or type a model id.';
        }

        return ['groups' => $groups, 'source' => $source, 'error' => $error];
    }

    /**
     * @return list<string>
     */
    private function recommendedModelIds(string $provider): array
    {
        return match ($provider) {
            'anthropic' => [
                'claude-haiku-4-5-20251001',
                'claude-sonnet-5',
                'claude-opus-5',
                'claude-sonnet-4-6',
            ],
            'openai' => [
                'gpt-4o-mini',
                'gpt-4o',
                'gpt-4.1-mini',
                'gpt-4.1',
                'o4-mini',
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function fallbackModelIds(string $provider): array
    {
        return match ($provider) {
            'anthropic' => [
                'claude-haiku-4-5-20251001',
                'claude-haiku-4-5',
                'claude-sonnet-5',
                'claude-opus-5',
                'claude-sonnet-4-6',
                'claude-opus-4-6',
                'claude-sonnet-4-5-20250929',
            ],
            'openai' => [
                'gpt-4o-mini',
                'gpt-4o',
                'gpt-4.1-mini',
                'gpt-4.1',
                'o4-mini',
                'o3-mini',
                'gpt-4-turbo',
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function fetchAnthropicModels(string $apiKey, string $baseUrl): array
    {
        if ($apiKey === '') {
            return [];
        }

        $base = $baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://api.anthropic.com';
        $ids = [];
        $after = null;

        do {
            $query = ['limit' => 100];
            if ($after) {
                $query['after_id'] = $after;
            }

            $response = Http::timeout(20)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->acceptJson()
                ->get($base.'/v1/models', $query);

            if (! $response->successful()) {
                throw new \RuntimeException($this->formatApiError($response->status(), $response->body()));
            }

            $data = $response->json('data') ?? [];
            foreach ($data as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            $after = ($response->json('has_more') ?? false) ? ($response->json('last_id') ?: null) : null;
        } while ($after);

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function fetchOpenAiCompatibleModels(string $apiKey, string $baseUrl, string $provider): array
    {
        $base = $baseUrl !== ''
            ? rtrim($baseUrl, '/')
            : 'https://api.openai.com/v1';

        $request = Http::timeout(20)->acceptJson();
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $response = $request->get($base.'/models');

        if (! $response->successful()) {
            throw new \RuntimeException($this->formatApiError($response->status(), $response->body()));
        }

        $ids = [];
        foreach ($response->json('data') ?? [] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($provider === 'openai' && ! $this->isLikelyOpenAiChatModel($id)) {
                continue;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private function isLikelyOpenAiChatModel(string $id): bool
    {
        $id = strtolower($id);

        if (str_contains($id, 'embed')
            || str_contains($id, 'whisper')
            || str_contains($id, 'tts')
            || str_contains($id, 'dall-e')
            || str_contains($id, 'realtime')
            || str_contains($id, 'transcribe')
            || str_contains($id, 'moderation')
            || str_contains($id, 'image')
            || str_contains($id, 'sora')
            || str_contains($id, 'audio')
        ) {
            return false;
        }

        return str_starts_with($id, 'gpt-')
            || str_starts_with($id, 'o1')
            || str_starts_with($id, 'o3')
            || str_starts_with($id, 'o4')
            || str_starts_with($id, 'chatgpt-')
            || str_starts_with($id, 'ft:');
    }

    private function formatApiError(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = data_get($decoded, 'error.message')
                ?? data_get($decoded, 'error.error.message')
                ?? data_get($decoded, 'message');
            if (is_string($message) && $message !== '') {
                return "AI API error {$status}: {$message}";
            }
        }

        $snippet = mb_substr(trim($body), 0, 280);

        return "AI API error {$status}: {$snippet}";
    }

    /**
     * @param  array<int, array{table: string, columns: string[]}>  $schema
     */
    private function formatSchema(array $schema): string
    {
        if ($schema === []) {
            return '(empty schema — no tables available)';
        }

        $lines = [];
        foreach (array_slice($schema, 0, 80) as $table) {
            $cols = implode(', ', array_slice($table['columns'], 0, 40));
            $lines[] = "- {$table['table']}({$cols})";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{provider: string, api_key: string, model: string, base_url: string}  $config
     * @return array{ok: bool, sql?: string, error?: string, provider?: string}
     */
    private function callOpenAiCompatible(array $config, string $system, string $prompt): array
    {
        $base = $config['base_url'] !== ''
            ? $config['base_url']
            : 'https://api.openai.com/v1';
        $model = $config['model'] !== '' ? $config['model'] : $this->defaultModel('openai');

        $response = Http::timeout(60)
            ->withToken($config['api_key'])
            ->acceptJson()
            ->post($base.'/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            return ['ok' => false, 'error' => $this->formatApiError($response->status(), $response->body())];
        }

        $text = (string) data_get($response->json(), 'choices.0.message.content', '');

        return $this->extractSql($text, $config['provider']);
    }

    /**
     * @param  array{provider: string, api_key: string, model: string, base_url: string}  $config
     * @return array{ok: bool, sql?: string, error?: string, provider?: string}
     */
    private function callAnthropic(array $config, string $system, string $prompt): array
    {
        $base = $config['base_url'] !== ''
            ? $config['base_url']
            : 'https://api.anthropic.com';
        $model = $config['model'] !== '' ? $config['model'] : $this->defaultModel('anthropic');

        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key' => $config['api_key'],
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->post($base.'/v1/messages', [
                'model' => $model,
                'max_tokens' => 2048,
                'temperature' => 0,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            return ['ok' => false, 'error' => $this->formatApiError($response->status(), $response->body())];
        }

        $parts = data_get($response->json(), 'content', []);
        $text = '';
        foreach ($parts as $part) {
            if (($part['type'] ?? '') === 'text') {
                $text .= $part['text'] ?? '';
            }
        }

        return $this->extractSql($text, 'anthropic');
    }

    /**
     * @return array{ok: bool, sql?: string, error?: string, provider?: string}
     */
    private function extractSql(string $text, string $provider): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['ok' => false, 'error' => 'AI returned an empty response.'];
        }

        if (preg_match('/```(?:sql)?\s*([\s\S]*?)```/i', $text, $matches)) {
            $text = trim($matches[1]);
        }

        return ['ok' => true, 'sql' => $text, 'provider' => $provider];
    }
}

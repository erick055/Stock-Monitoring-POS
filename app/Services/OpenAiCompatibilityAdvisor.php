<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAiCompatibilityAdvisor
{
    public function advise(array $vehicle, Collection $products, int $userId): array
    {
        if (blank(config('openai.api_key'))) {
            return $this->unavailable('AI recommendations are not configured. Add OPENAI_API_KEY.');
        }

        $context = [
            'motorcycle' => $vehicle,
            'inventory_products' => $products->map(fn ($product) => [
                'product_id' => $product->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'manufacturer' => $product->manufacturer,
                'manufacturer_part_number' => $product->manufacturer_part_number,
                'category' => $product->category,
                'description' => mb_substr((string) $product->description, 0, 300),
                'stock' => $product->current_stock,
            ])->values()->all(),
        ];
        $webSearch = (bool) config('openai.web_search', false);
        $maxRecommendations = max(1, (int) config('openai.max_recommendations', 5));
        $cacheKey = 'openai-compatibility-v3:'.hash('sha256', json_encode([
            'model' => config('openai.model'),
            'web_search' => $webSearch,
            'context' => $context,
        ]));

        try {
            return Cache::remember($cacheKey, now()->addHours(max(1, (int) config('openai.cache_hours', 24))), function () use ($context, $userId, $webSearch, $maxRecommendations) {
                $payload = [
                    'model' => config('openai.model'),
                    'store' => false,
                    'safety_identifier' => 'motosync_'.substr(hash('sha256', (string) $userId), 0, 55),
                    'reasoning' => ['effort' => config('openai.reasoning_effort', 'none')],
                    'max_output_tokens' => max(300, (int) config('openai.max_output_tokens', 1200)),
                    'instructions' => implode(' ', [
                        'You identify likely motorcycle-part compatibility using only the supplied inventory products.',
                        $webSearch
                            ? 'Use web search only when necessary to verify an exact manufacturer part number.'
                            : 'Use built-in knowledge only; web search is disabled to control cost.',
                        'Never invent a product ID or claim certainty without reliable exact-model evidence.',
                        'Return at most '.$maxRecommendations.' best candidates, ordered by usefulness.',
                        'Keep each reason to one short sentence and include no more than two checks and two sources.',
                        'If web search is disabled, leave sources empty unless a reliable catalog reference is already known.',
                        'Use possible when fitment needs confirmation and incompatible only for a clear mismatch.',
                        'Safety-critical parts require final manufacturer-catalog or qualified-mechanic confirmation.',
                    ]),
                    'input' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'text' => [
                        'verbosity' => 'low',
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'compatibility_recommendations',
                            'strict' => true,
                            'schema' => $this->schema(),
                        ],
                    ],
                ];

                if ($webSearch) {
                    $payload['tools'] = [[
                        'type' => 'web_search',
                        'search_context_size' => 'low',
                    ]];
                }

                $caBundle = config('openai.ca_bundle');
                $http = Http::withToken(config('openai.api_key'))
                    ->acceptJson()
                    ->timeout(config('openai.timeout', 30))
                    ->retry(2, 500, throw: false);

                if (is_string($caBundle) && $caBundle !== '') {
                    if (! is_readable($caBundle)) {
                        throw new RuntimeException("The configured OpenAI CA bundle is not readable: {$caBundle}");
                    }

                    $http = $http->withOptions(['verify' => $caBundle]);
                }

                $response = $http->post(rtrim(config('openai.base_url'), '/').'/responses', $payload);

                if (! $response->successful()) {
                    $errorCode = (string) $response->json('error.code', 'unknown_error');
                    $errorMessage = (string) $response->json('error.message', 'No error details returned.');

                    throw new RuntimeException("OpenAI returned HTTP {$response->status()} [{$errorCode}]: {$errorMessage}");
                }

                $body = $response->json();
                $outputText = data_get($body, 'output_text');
                if (! is_string($outputText)) {
                    $outputText = collect(data_get($body, 'output', []))
                        ->flatMap(fn ($item) => $item['content'] ?? [])
                        ->first(fn ($content) => isset($content['text']))['text'] ?? null;
                }

                $advice = is_string($outputText) ? json_decode($outputText, true) : null;
                if (! is_array($advice) || ! isset($advice['summary'], $advice['recommendations'])) {
                    throw new RuntimeException('OpenAI returned an invalid structured response.');
                }

                $allowedIds = collect($context['inventory_products'])->pluck('product_id')->all();
                $recommendations = collect($advice['recommendations'])
                    ->filter(fn ($item) => is_array($item) && in_array($item['product_id'] ?? null, $allowedIds, true))
                    ->map(function (array $item) {
                        $item['confidence'] = max(0, min(100, (int) $item['confidence']));

                        return $item;
                    })
                    ->keyBy('product_id')
                    ->all();

                return [
                    'available' => true,
                    'summary' => (string) $advice['summary'],
                    'recommendations' => $recommendations,
                    'model' => (string) data_get($body, 'model', config('openai.model')),
                    'response_id' => data_get($body, 'id'),
                ];
            });
        } catch (Throwable $exception) {
            Log::warning('OpenAI compatibility recommendation failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->unavailable('AI recommendations are temporarily unavailable. Please try again.');
        }
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'recommendations'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['product_id', 'rank', 'status', 'label', 'confidence', 'reason', 'checks', 'sources'],
                        'properties' => [
                            'product_id' => ['type' => 'integer'],
                            'rank' => ['type' => 'integer'],
                            'status' => ['type' => 'string', 'enum' => ['compatible', 'possible', 'incompatible']],
                            'label' => ['type' => 'string'],
                            'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'reason' => ['type' => 'string'],
                            'checks' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function unavailable(string $message): array
    {
        return ['available' => false, 'message' => $message, 'recommendations' => []];
    }
}

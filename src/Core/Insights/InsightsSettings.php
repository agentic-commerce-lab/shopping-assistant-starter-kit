<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Llm\LlmSettings;

/**
 * Everything the nightly run needs from the administration.
 *
 * `samplePercent` is clamped rather than trusted: a negative sample selects nothing and would read
 * in the dashboard as "the judge found no problems", and a sample above 100 would claim a corpus
 * larger than the night had. Both are lies a chart would carry for weeks.
 */
final readonly class InsightsSettings
{
    public int $samplePercent;

    public function __construct(
        public bool $enabled = false,
        int $samplePercent = 100,
        public InsightsDataScope $dataScope = InsightsDataScope::Aggregates,
        public LlmSettings $llm = new LlmSettings('', '', 'none'),
    ) {
        $this->samplePercent = max(0, min(100, $samplePercent));
    }

    /**
     * The judge's provider settings, falling back to the chat model's.
     *
     * **Field by field, never as a whole.** A merchant who sets only the model wants the chat
     * provider with a bigger context window — the judge reads whole conversations at once, which the
     * chat model never has to do. Falling back as a whole would drop the one field they filled in
     * and then bill them for the wrong model.
     *
     * `model` ends as `'none'` rather than `''` when nothing is configured anywhere, because
     * {@see LlmSettings::$model} is declared `non-empty-string` and reading these settings must
     * never throw: the aggregation has to run on a shop with no model at all, leaving only the judge
     * to fail with a reason recorded on the run.
     */
    public static function resolveLlm(
        LlmSettings $chat,
        string $baseUrl,
        #[\SensitiveParameter]
        string $apiKey,
        string $model,
    ): LlmSettings {
        $resolvedModel = $model !== '' ? $model : $chat->model;

        return new LlmSettings(
            baseUrl: $baseUrl !== '' ? $baseUrl : $chat->baseUrl,
            apiKey: $apiKey !== '' ? $apiKey : $chat->apiKey,
            model: $resolvedModel !== '' ? $resolvedModel : 'none',
            allowInsecureEgress: $chat->allowInsecureEgress,
        );
    }
}

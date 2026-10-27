<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Non-secret, immutable evidence passed from deterministic authorization to the request builder. */
final readonly class ProviderExecutionDecision
{
    public function __construct(
        public string $actionType,
        public string $provider,
        public string $model,
        public string $catalogVersion,
        public string $catalogHash,
        public string $quoteReference,
        public string $sourceUrl,
        public int $quoteEpoch,
        public string $taskType,
        public string $language,
        public string $dataClass,
        public int $inputTokenUpperBound,
        public int $maxOutputTokens,
        public int $estimatedCostCents,
        public int $maxCostCents,
        public int $reservationAmountCents,
        public int $credentialId,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $actionType)
            || !preg_match('/^[a-z0-9][a-z0-9.-]{1,63}$/D', $provider)
            || !preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $model)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/D', $catalogVersion)
            || !preg_match('/^[a-f0-9]{64}$/D', $catalogHash)
            || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $quoteReference)
            || !preg_match('/^https:\/\//i', $sourceUrl)
            || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $taskType)
            || !preg_match('/^[a-z]{2,3}$/D', $language)
            || !in_array($dataClass, ['public', 'internal', 'sensitive'], true)
            || $quoteEpoch < 0 || $inputTokenUpperBound < 0 || $maxOutputTokens < 1
            || $estimatedCostCents < 0 || $maxCostCents < 0 || $reservationAmountCents < 1 || $credentialId < 1
            || $estimatedCostCents > $maxCostCents || $maxCostCents > $reservationAmountCents) {
            throw new \InvalidArgumentException('Provider execution decision is invalid');
        }
    }

    /** @return array<string,int|string> */
    public function toArray(): array
    {
        return [
            'action_type' => $this->actionType,
            'provider' => $this->provider,
            'model' => $this->model,
            'catalog_version' => $this->catalogVersion,
            'catalog_hash' => $this->catalogHash,
            'quote_reference' => $this->quoteReference,
            'source_url' => $this->sourceUrl,
            'quote_epoch' => $this->quoteEpoch,
            'task_type' => $this->taskType,
            'language' => $this->language,
            'data_class' => $this->dataClass,
            'input_token_upper_bound' => $this->inputTokenUpperBound,
            'max_output_tokens' => $this->maxOutputTokens,
            'estimated_cost_cents' => $this->estimatedCostCents,
            'max_cost_cents' => $this->maxCostCents,
            'reservation_amount_cents' => $this->reservationAmountCents,
            'credential_id' => $this->credentialId,
        ];
    }
}

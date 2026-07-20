<?php

namespace App\Services;

use App\Models\Motorcycle;
use App\Models\PartCompatibility;
use App\Models\Product;

class PartCompatibilityChecker
{
    public function check(Product $product, Motorcycle $motorcycle, ?PartCompatibility $record = null): array
    {
        if ($record) {
            return $this->fromFitmentRecord($record);
        }

        $technicalResult = $this->fromTechnicalRules($product, $motorcycle);

        if ($technicalResult) {
            return $technicalResult;
        }

        return $this->fromDescription($product, $motorcycle);
    }

    private function fromFitmentRecord(PartCompatibility $record): array
    {
        $verified = $record->verified_at !== null && filled($record->source_reference);
        $reasons = array_values(array_filter($record->reasons ?? []));

        if ($record->fitment_notes) {
            $reasons[] = $record->fitment_notes;
        }

        if (! $verified || $record->compatibility_status === 'unverified') {
            return $this->result(
                'possible',
                'Possible fit—verify',
                $reasons ?: ['A fitment record exists, but it has not been verified against a reliable source.'],
                $record->conditions ?? [],
                'Unverified fitment record',
                40,
                $record->source_reference,
            );
        }

        return match ($record->compatibility_status) {
            'exact_fit' => $this->result(
                'confirmed',
                'Confirmed fit',
                $reasons ?: ['Verified fitment record matches this exact motorcycle.'],
                [],
                'Verified fitment record',
                100,
                $record->source_reference,
            ),
            'conditional_fit' => $this->result(
                'possible',
                'Possible fit—verify',
                $reasons ?: ['The verified record lists a conditional fit.'],
                $record->conditions ?? [],
                'Verified conditional record',
                75,
                $record->source_reference,
            ),
            'incompatible' => $this->result(
                'incompatible',
                'Not compatible',
                $reasons ?: ['A verified fitment record marks this part as incompatible.'],
                [],
                'Verified incompatibility record',
                100,
                $record->source_reference,
            ),
            default => $this->result(
                'possible',
                'Possible fit—verify',
                ['The fitment status is unknown and needs manual verification.'],
                [],
                'Unverified fitment record',
                30,
            ),
        };
    }

    private function fromTechnicalRules(Product $product, Motorcycle $motorcycle): ?array
    {
        $partSpecifications = $this->normalizeMap($product->specifications ?? []);
        $motorcycleSpecifications = $this->normalizeMap($motorcycle->specifications ?? []);
        $requiredFeatures = $this->normalizeList($product->required_features ?? []);
        $motorcycleFeatures = $this->normalizeList($motorcycle->features ?? []);

        $mismatches = [];
        $matches = [];

        foreach ($partSpecifications as $key => $requiredValue) {
            if (! array_key_exists($key, $motorcycleSpecifications)) {
                continue;
            }

            if ($this->normalizeValue($requiredValue) !== $this->normalizeValue($motorcycleSpecifications[$key])) {
                $mismatches[] = "{$this->label($key)} requires {$requiredValue}; motorcycle record has {$motorcycleSpecifications[$key]}.";
            } else {
                $matches[] = "{$this->label($key)} matches ({$requiredValue}).";
            }
        }

        $missingFeatures = array_values(array_diff($requiredFeatures, $motorcycleFeatures));
        foreach ($missingFeatures as $feature) {
            $mismatches[] = "Required feature is missing: {$this->label($feature)}.";
        }

        if ($mismatches !== []) {
            return $this->result(
                'incompatible',
                'Not compatible',
                $mismatches,
                [],
                'Structured technical rules',
                90,
            );
        }

        if ($matches !== [] || ($requiredFeatures !== [] && $missingFeatures === [])) {
            $reasons = $matches;
            if ($requiredFeatures !== []) {
                $reasons[] = 'All recorded required features are present.';
            }
            $reasons[] = 'Technical rules passed, but no verified exact-fit record exists.';

            return $this->result(
                'possible',
                'Possible fit—verify',
                $reasons,
                ['Confirm mounting, dimensions, and manufacturer fitment before installation.'],
                'Structured technical rules',
                65,
            );
        }

        return null;
    }

    private function fromDescription(Product $product, Motorcycle $motorcycle): array
    {
        $description = mb_strtolower(trim($product->description ?? ''));
        $brand = mb_strtolower($motorcycle->brand);
        $model = mb_strtolower($motorcycle->model);

        if ($description !== '' && str_contains($description, $brand) && str_contains($description, $model)) {
            return $this->result(
                'possible',
                'Possible fit—verify',
                ['The unstructured product description mentions the selected brand and model.'],
                ['Description matching is only an AI-assisted hint; verify the exact year, engine, variant, and dimensions.'],
                'AI-assisted description interpretation',
                45,
            );
        }

        return $this->result(
            'unverified',
            'Unverified',
            ['There is not enough structured or verified fitment data for this part.'],
            ['Do not install until a verified fitment source or technical specification is added.'],
            'Insufficient evidence',
            0,
        );
    }

    private function result(
        string $code,
        string $label,
        array $reasons,
        array $conditions,
        string $source,
        int $confidence,
        ?string $reference = null,
    ): array {
        return compact('code', 'label', 'reasons', 'conditions', 'source', 'confidence', 'reference');
    }

    private function normalizeMap(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[$this->normalizeValue($key)] = trim((string) $value);
        }

        return $normalized;
    }

    private function normalizeList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($value) => $this->normalizeValue($value), $values))));
    }

    private function normalizeValue(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function label(string $value): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $value));
    }
}

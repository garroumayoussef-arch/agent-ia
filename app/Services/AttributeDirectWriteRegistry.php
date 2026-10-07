<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Validation\ValidationException;

class AttributeDirectWriteRegistry
{
    public function __construct(private Repository $config) {}

    /** Configuration is read afresh, including during the stale-context check. */
    public function snapshot(): array
    {
        $configuration = $this->validatedConfiguration('direct');
        // Preserve the Product fingerprint: Variant changes are independent.
        unset($configuration['variant_direct']);

        return $configuration;
    }

    public function variantSnapshot(): array
    {
        $configuration = $this->validatedConfiguration('variant_direct');

        return [
            'historical' => $configuration['historical'],
            'variant_direct' => $configuration['variant_direct'],
        ];
    }

    private function validatedConfiguration(string $admission): array
    {
        $configuration = $this->config->get('attribute_writing');
        $baseline = require dirname(__DIR__, 2).'/config/attribute_writing.php';
        if (! is_array($configuration) || ! is_array($configuration['historical'] ?? null)
            || ! is_array($configuration[$admission] ?? null) || ! array_is_list($configuration[$admission])) {
            $this->invalid();
        }
        foreach ($baseline['historical'] as $level => $codes) {
            if (($configuration['historical'][$level] ?? null) !== $codes) {
                $this->invalid();
            }
        }
        $reserved = array_merge(...array_values($baseline['historical']));
        foreach ($configuration[$admission] as $code) {
            if (! is_string($code) || $code === '' || in_array($code, $reserved, true)) {
                $this->invalid();
            }
        }
        if (count(array_unique($configuration[$admission], SORT_STRING)) !== count($configuration[$admission])) {
            $this->invalid();
        }

        return $configuration;
    }

    public function productCodes(): array
    {
        return $this->snapshot()['direct'];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['attributes' => 'Régime d’écriture invalide ou en conflit avec les miroirs historiques.']);
    }
}

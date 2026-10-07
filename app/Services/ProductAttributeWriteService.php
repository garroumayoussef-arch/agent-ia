<?php

namespace App\Services;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\CategoryAttributeDefinition;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Explicit opt-in API; callers must authorize the Product before invoking it. */
class ProductAttributeWriteService
{
    // Unicode White_Space, explicitly excluding zero-width space and BOM.
    private const SPACE = '[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

    public function __construct(
        private AttributeApplicabilityResolver $resolver,
        private AttributeDirectWriteRegistry $registry,
    ) {}

    /** Server-held preparation token. Never accept a replacement token from a client. */
    public function prepare(Product $product): string
    {
        return $this->context($product)['fingerprint'];
    }

    /** Persist the supplied Product and direct values on its own connection. */
    public function save(Product $product, array $values, string $prepared): Product
    {
        $this->connection($product);
        $attributes = $product->getAttributes();
        $original = $product->getRawOriginal();
        $existed = $product->exists;
        $recentlyCreated = $product->wasRecentlyCreated;
        try {
            return $product->getConnection()->transaction(function () use ($product, $values, $prepared): Product {
                // Serializes competing writes to the same existing owner, not configuration.
                if ($product->exists) {
                    $product->newQuery()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                }
                $context = $this->context($product);
                if (! hash_equals($context['fingerprint'], $prepared)) {
                    $this->fail('attributes', 'Contexte ou configuration modifié : rafraîchissement requis.');
                }
                $definitions = $context['definitions'];
                foreach ($values as $code => $_) {
                    if (! is_string($code) || ! isset($definitions[$code])) {
                        $this->fail('attributes', 'Attribut inconnu, historique, non admis, de mauvais niveau ou non applicable.');
                    }
                }
                $existing = $this->stored($product);
                $writes = [];
                foreach ($definitions as $code => $definition) {
                    $submitted = array_key_exists($code, $values);
                    $raw = $submitted ? $values[$code] : ($existing[$definition->id]->value ?? null);
                    $normalized = $this->validateValue($definition, $raw);
                    if ($submitted) {
                        $writes[$definition->id] = $normalized;
                    }
                }
                if (! $product->save()) {
                    $this->fail('attributes', 'Sauvegarde du produit refusée.');
                }
                foreach ($writes as $definitionId => $value) {
                    $query = ProductAttributeValue::query()->where('product_id', $product->getKey())
                        ->where('attribute_definition_id', $definitionId);
                    if ($value === null) {
                        $query->delete();
                    } elseif (! isset($existing[$definitionId]) || $existing[$definitionId]->value !== $value) {
                        ProductAttributeValue::updateOrCreate(
                            ['product_id' => $product->getKey(), 'attribute_definition_id' => $definitionId],
                            ['value' => $value],
                        );
                    }
                }

                return $product;
            });
        } catch (Throwable $exception) {
            // A DB rollback must not leave a failed creation marked persisted in memory.
            $product->setRawAttributes($original, true);
            $product->setRawAttributes($attributes);
            $product->exists = $existed;
            $product->wasRecentlyCreated = $recentlyCreated;
            $product->unsetRelations();
            throw $exception;
        }
    }

    /** Reads never normalize or write old values. Invalid values are not active. */
    public function activeValues(Product $product): array
    {
        $context = $this->context($product);
        $stored = $this->stored($product);
        $active = [];
        foreach ($context['definitions'] as $code => $definition) {
            if (! isset($stored[$definition->id])) {
                continue;
            }
            try {
                $value = $this->validateValue($definition, $stored[$definition->id]->value);
                if ($value !== null) {
                    $active[$code] = $stored[$definition->id]->value;
                }
            } catch (ValidationException) {
                // Configuration was validated by context(); this is an invalid stored value.
            }
        }

        return $active;
    }

    private function connection(Product $product): void
    {
        // Resolver/models use the default connection: fail closed instead of crossing DBs.
        if ($product->getConnection() !== (new AttributeDefinition)->getConnection()) {
            $this->fail('attributes', 'Connexion du produit incompatible avec le moteur d’attributs.');
        }
    }

    private function context(Product $product): array
    {
        $this->connection($product);
        $activity = $product->activity;
        if (! is_string($activity) || trim($activity) === '') {
            $this->fail('activity', 'Activité explicite requise.');
        }
        $persisted = $product->exists ? $product->newQuery()->find($product->getKey()) : null;
        if ($product->exists && ($persisted === null || $persisted->activity !== $activity)) {
            $this->fail('activity', 'L’activité existante ne peut pas être modifiée directement.');
        }
        $categoryId = $product->category_id;
        if (! is_int($categoryId) && ! (is_string($categoryId) && ctype_digit($categoryId))) {
            $this->fail('category_id', 'Catégorie obligatoire.');
        }
        $path = [];
        $next = (int) $categoryId;
        while ($next !== null) {
            if (isset($path[$next])) {
                $this->fail('category_id', 'Hiérarchie de catégories cyclique.');
            }
            $category = Category::query()->find($next);
            if ($category === null || ($category->activity !== null && $category->activity !== $activity)) {
                $this->fail('category_id', 'Catégorie ou ancêtre incompatible.');
            }
            $path[$next] = [$category->id, $category->activity, $category->parent_id];
            $next = $category->parent_id === null ? null : (int) $category->parent_id;
        }
        $registry = $this->registry->snapshot();
        $codes = $registry['direct'];
        $all = AttributeDefinition::query()->whereIn('code', $codes)->orderBy('id')->get();
        foreach ($all as $definition) {
            if ($definition->level !== 'product') {
                $this->fail('attributes', 'Le registre de cette tranche accepte uniquement le niveau Product.');
            }
        }
        $resolved = $this->resolver->resolve($activity, (int) $categoryId, 'product');
        $definitions = [];
        foreach ($resolved as $definition) {
            if (in_array($definition->code, $codes, true)) {
                $this->validateDefinition($definition);
                $definitions[$definition->code] = $definition;
            }
        }
        $associations = CategoryAttributeDefinition::query()->whereIn('category_id', array_keys($path))
            ->whereIn('attribute_definition_id', $all->modelKeys())->orderBy('id')->get();
        $snapshot = [
            'owner' => [$product->exists, $product->getKey(), $persisted?->getAttributes()],
            'context' => [$activity, (int) $categoryId, array_values($path)],
            'registry' => $registry,
            'definitions' => $all->map(fn ($d) => $d->getAttributes())->all(),
            'associations' => $associations->map(fn ($a) => $a->getAttributes())->all(),
            // Avoid overwriting another writer's values from an old preparation.
            'values' => $this->stored($product)->map(fn ($v) => $v->getAttributes())->all(),
        ];

        return ['definitions' => $definitions, 'fingerprint' => hash('sha256', serialize($snapshot))];
    }

    private function stored(Product $product): \Illuminate\Database\Eloquent\Collection
    {
        return ProductAttributeValue::query()->where('product_id', $product->exists ? $product->getKey() : null)
            ->orderBy('attribute_definition_id')->get()->keyBy('attribute_definition_id');
    }

    private function validateDefinition(AttributeDefinition $definition): void
    {
        if ($definition->input_type === 'text' && $definition->options === null) {
            return;
        }
        $options = $definition->options;
        if ($definition->input_type !== 'select' || ! is_array($options) || ! array_is_list($options) || $options === []) {
            $this->fail('attributes.'.$definition->code, 'Configuration de champ non supportée.');
        }
        foreach ($options as $option) {
            if (! is_string($option) || ! mb_check_encoding($option, 'UTF-8')
                || $this->trimSpace($option) === '' || mb_strlen($option, 'UTF-8') > 255) {
                $this->fail('attributes.'.$definition->code, 'Options invalides.');
            }
        }
        if (count(array_unique($options, SORT_STRING)) !== count($options)) {
            $this->fail('attributes.'.$definition->code, 'Options dupliquées.');
        }
    }

    private function validateValue(AttributeDefinition $definition, mixed $value): ?string
    {
        $key = 'attributes.'.$definition->code;
        if ($value !== null && (! is_string($value) || ! mb_check_encoding($value, 'UTF-8'))) {
            $this->fail($key, 'Une chaîne UTF-8 est requise.');
        }
        if ($value === null || $this->trimSpace($value) === '') {
            if ($definition->is_required) {
                $this->fail($key, 'Valeur obligatoire.');
            }

            return null;
        }
        $normalized = $definition->input_type === 'text' ? $this->trimSpace($value) : $value;
        if (mb_strlen($normalized, 'UTF-8') > 255) {
            $this->fail($key, 'Maximum 255 caractères, sans troncature.');
        }
        if ($definition->input_type === 'select' && ! in_array($normalized, $definition->options, true)) {
            $this->fail($key, 'Option exacte autorisée requise.');
        }

        return $normalized;
    }

    private function trimSpace(string $value): string
    {
        return preg_replace('/\A'.self::SPACE.'+|'.self::SPACE.'+\z/u', '', $value);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}

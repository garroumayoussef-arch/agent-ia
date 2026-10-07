<?php

namespace App\Services;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\CategoryAttributeDefinition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Opt-in values-only API. The caller must authorize the persisted Variant. */
class ProductVariantAttributeWriteService
{
    public function __construct(
        private AttributeApplicabilityResolver $resolver,
        private AttributeDirectWriteRegistry $registry,
        private ProductAttributeWriteService $validation,
    ) {}

    /** Server-held token; never accept a replacement preparation token from a client. */
    public function prepare(ProductVariant $variant): string
    {
        return $this->context($variant)['fingerprint'];
    }

    /** Does not save, refresh or mutate the supplied owner, its parent or their relations. */
    public function save(ProductVariant $variant, array $values, string $prepared): void
    {
        $this->connection($variant);
        $variant->getConnection()->transaction(function () use ($variant, $values, $prepared): void {
            // Constant order for this API: Variant first, then its persisted Product.
            $context = $this->context($variant, true);
            if (! hash_equals($context['fingerprint'], $prepared)) {
                $this->fail('attributes', 'Contexte ou configuration modifié : rafraîchissement requis.');
            }
            $definitions = $context['definitions'];
            foreach ($values as $code => $_) {
                if (! is_string($code) || ! isset($definitions[$code])) {
                    $this->fail('attributes', 'Attribut inconnu, historique, non admis, de mauvais niveau ou non applicable.');
                }
            }
            $existing = $context['stored'];
            $writes = [];
            foreach ($definitions as $code => $definition) {
                $submitted = array_key_exists($code, $values);
                $raw = $submitted ? $values[$code] : ($existing[$definition->id]->value ?? null);
                $normalized = $this->validation->validateDirectValue($definition, $raw);
                if ($submitted) {
                    $writes[$definition->id] = $normalized;
                }
            }

            // All validation precedes DML. No Product/Variant save() or historical hooks.
            foreach ($writes as $definitionId => $value) {
                $query = ProductVariantAttributeValue::query()
                    ->where('product_variant_id', $context['variant']->getKey())
                    ->where('attribute_definition_id', $definitionId);
                if ($value === null) {
                    $query->delete();
                } elseif (! isset($existing[$definitionId]) || $existing[$definitionId]->value !== $value) {
                    ProductVariantAttributeValue::updateOrCreate(
                        ['product_variant_id' => $context['variant']->getKey(),
                            'attribute_definition_id' => $definitionId],
                        ['value' => $value],
                    );
                }
            }
        });
    }

    /** Raw valid stored values, without normalization writes or owner hooks. */
    public function activeValues(ProductVariant $variant): array
    {
        $context = $this->context($variant);
        $active = [];
        foreach ($context['definitions'] as $code => $definition) {
            if (! isset($context['stored'][$definition->id])) {
                continue;
            }
            $raw = $context['stored'][$definition->id]->value;
            try {
                if ($this->validation->validateDirectValue($definition, $raw) !== null) {
                    $active[$code] = $raw;
                }
            } catch (ValidationException) {
                // Definition/configuration errors were already checked by context().
            }
        }

        return $active;
    }

    private function connection(ProductVariant $variant): void
    {
        $connection = $variant->getConnection();
        foreach ([new Product, new AttributeDefinition, new Category,
            new CategoryAttributeDefinition, new ProductVariantAttributeValue] as $model) {
            if ($connection !== $model->getConnection()) {
                $this->fail('attributes', 'Connexion de la variante incompatible avec le moteur d’attributs.');
            }
        }
    }

    private function context(ProductVariant $variant, bool $lock = false): array
    {
        $this->connection($variant);
        if (! $variant->exists || ! $this->validId($variant->getKey())
            || (string) $variant->getKey() !== (string) $variant->getRawOriginal($variant->getKeyName())) {
            $this->fail('attributes', 'Variante persistée et identité inchangée requises.');
        }
        if (! $this->validId($variant->product_id)
            || (string) $variant->product_id !== (string) $variant->getRawOriginal('product_id')) {
            $this->fail('product_id', 'Le parent de la variante ne peut pas être modifié.');
        }
        $query = $variant->newQuery()->whereKey($variant->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $persisted = $query->first();
        if ($persisted === null || (string) $persisted->product_id !== (string) $variant->getRawOriginal('product_id')) {
            $this->fail('product_id', 'Variante disparue ou parent modifié : rafraîchissement requis.');
        }
        $parentQuery = Product::query()->whereKey($persisted->product_id);
        if ($lock) {
            $parentQuery->lockForUpdate();
        }
        $parent = $parentQuery->first();
        if ($parent === null) {
            $this->fail('product_id', 'Parent persisté requis.');
        }
        // Always use the freshly queried parent, never $variant->product.
        $activity = $parent->activity;
        if (! is_string($activity) || trim($activity) === '') {
            $this->fail('activity', 'Activité explicite requise.');
        }
        if (! $this->validId($parent->category_id)) {
            $this->fail('category_id', 'Catégorie obligatoire.');
        }
        $path = [];
        $next = (int) $parent->category_id;
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
        $registry = $this->registry->variantSnapshot();
        $codes = $registry['variant_direct'];
        $all = AttributeDefinition::query()->whereIn('code', $codes)->orderBy('id')->get();
        foreach ($all as $definition) {
            if ($definition->level !== 'variant') {
                $this->fail('attributes', 'Le registre Variant accepte uniquement le niveau variant.');
            }
        }
        $definitions = [];
        foreach ($this->resolver->resolve($activity, (int) $parent->category_id, 'variant') as $definition) {
            if (in_array($definition->code, $codes, true)) {
                $this->validation->validateDirectDefinition($definition);
                $definitions[$definition->code] = $definition;
            }
        }
        $associations = CategoryAttributeDefinition::query()->whereIn('category_id', array_keys($path))
            ->whereIn('attribute_definition_id', $all->modelKeys())->orderBy('id')->get();
        $stored = $this->stored($persisted, $all->modelKeys());
        $snapshot = [
            'variant' => $persisted->getAttributes(),
            'parent' => $parent->getAttributes(),
            'context' => [$activity, (int) $parent->category_id, array_values($path)],
            'registry' => $registry,
            'definitions' => $all->map(fn ($d) => $d->getAttributes())->all(),
            'associations' => $associations->map(fn ($a) => $a->getAttributes())->all(),
            'values' => $stored->map(fn ($v) => $v->getAttributes())->all(),
        ];

        return ['variant' => $persisted, 'definitions' => $definitions, 'stored' => $stored,
            'fingerprint' => hash('sha256', serialize($snapshot))];
    }

    private function stored(ProductVariant $variant, array $definitionIds): Collection
    {
        return ProductVariantAttributeValue::query()->where('product_variant_id', $variant->getKey())
            ->whereIn('attribute_definition_id', $definitionIds)
            ->orderBy('attribute_definition_id')->get()->keyBy('attribute_definition_id');
    }

    private function validId(mixed $id): bool
    {
        return (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}

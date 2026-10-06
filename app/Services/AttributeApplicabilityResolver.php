<?php

namespace App\Services;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\CategoryAttributeDefinition;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/** Résolution uniquement : aucun hook historique, cache persistant ou écriture. */
class AttributeApplicabilityResolver
{
    public function resolve(string $activity, ?int $categoryId, string $level): Collection
    {
        if (trim($activity) === '' || ! in_array($level, ['product', 'variant'], true)) {
            throw new RuntimeException('Contexte d’applicabilité invalide.');
        }

        $definitions = AttributeDefinition::query()
            ->where('level', $level)
            ->where(fn ($query) => $query->whereNull('activity')->orWhere('activity', $activity))
            ->orderBy('code')->orderBy('id')->get();

        foreach ($definitions as $definition) {
            if (! in_array($definition->applicability_mode, ['activity', 'category'], true)) {
                throw new RuntimeException('Mode d’applicabilité invalide.');
            }
        }

        $categorical = $definitions->where('applicability_mode', 'category');
        $matched = [];

        if ($categoryId !== null && $categorical->isNotEmpty()) {
            $ancestors = [];
            $next = $categoryId;

            while ($next !== null) {
                if (isset($ancestors[$next])) {
                    throw new RuntimeException('Hiérarchie de catégories invalide.');
                }

                $category = Category::query()->find($next);
                if ($category === null || ($category->activity !== null && $category->activity !== $activity)) {
                    throw new RuntimeException('Contexte catégoriel incompatible.');
                }

                $ancestors[$next] = $category;
                $next = $category->parent_id === null ? null : (int) $category->parent_id;
            }

            // Une lecture par contexte, pas une requête par définition ou variante.
            $associations = CategoryAttributeDefinition::query()
                ->whereIn('category_id', array_keys($ancestors))
                ->whereIn('attribute_definition_id', $categorical->modelKeys())->get();
            $byId = $categorical->keyBy('id');

            foreach ($associations as $association) {
                $category = $ancestors[$association->category_id];
                $definition = $byId[$association->attribute_definition_id];

                if ($category->activity !== null && $definition->activity !== null
                    && $category->activity !== $definition->activity) {
                    throw new RuntimeException('Association de catégorie et de définition incompatible.');
                }

                if ((int) $association->category_id === $categoryId || $association->include_descendants) {
                    $matched[$definition->id] = true;
                }
            }
        }

        return $definitions->filter(fn (AttributeDefinition $definition): bool =>
            $definition->applicability_mode === 'activity' || isset($matched[$definition->id]))->values();
    }
}

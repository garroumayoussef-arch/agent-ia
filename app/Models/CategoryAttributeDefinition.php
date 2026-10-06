<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CategoryAttributeDefinition extends Model
{
    protected $table = 'category_attribute_definition';

    protected $fillable = ['category_id', 'attribute_definition_id', 'include_descendants'];

    protected $casts = ['include_descendants' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $association): void {
            // Lecture fraîche : une relation chargée ne prouve pas la compatibilité.
            $category = $association->category()->first();
            $definition = $association->attributeDefinition()->first();

            if ($category === null || $definition === null
                || ($category->activity !== null && $definition->activity !== null
                    && $category->activity !== $definition->activity)) {
                throw ValidationException::withMessages([
                    'attribute_definition_id' => 'Association de catégorie et de définition incompatible.',
                ]);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'route',
        'sort',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'menu_permissions');
    }

    public function visibleTo(User $user): bool
    {
        $required = $this->permissions()->pluck('name');

        if ($required->isEmpty()) {
            return true;
        }

        return $user->permissionNames()->intersect($required)->isNotEmpty();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Menu — DB-driven navigation menu (mirrors legacy menus table).
 *
 * Hierarchical: parent_id = 0 for top-level; children reference parent.
 * Per-user visibility controlled via user_menu_permissions.
 *
 * @property int $id
 * @property string $menu_name
 * @property string|null $menu_link
 * @property string|null $controller
 * @property string|null $action
 * @property string|null $icon
 * @property int $parent_id
 * @property int $sort_order
 * @property string|null $section
 * @property bool $is_active
 */
class Menu extends Model
{
    protected $table = 'menus';

    public $timestamps = false;

    protected $fillable = [
        'menu_name', 'menu_link', 'controller', 'action', 'icon',
        'parent_id', 'sort_order', 'section', 'is_active',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort_order');
    }

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function permissions()
    {
        return $this->hasMany(UserMenuPermission::class, 'menu_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeTopLevel($query)
    {
        return $query->where('parent_id', 0)->orderBy('sort_order');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Menu — DB-driven navigation menu.
 *
 * Maps to the PostgreSQL `menus` table (01_auth_and_master.sql):
 *   id, parent_id, menu_label, controller, action, icon, sort_order,
 *   is_active, created_at, updated_at
 *
 * Hierarchical: parent_id = 0 for top-level; children reference parent.
 * Per-user visibility controlled via user_menu_permissions.
 *
 * NOTE: The legacy MySQL table had extra columns (menu_name, menu_link,
 * section) that were NOT carried over to the PG schema. The PG schema
 * uses `menu_label` (not `menu_name`). The MenuService resolves routes
 * from controller + action (no menu_link needed).
 *
 * @property int $id
 * @property int $parent_id
 * @property string $menu_label
 * @property string|null $controller
 * @property string|null $action
 * @property string|null $icon
 * @property int $sort_order
 * @property bool $is_active
 */
class Menu extends Model
{
    protected $table = 'menus';

    public $timestamps = false; // created_at/updated_at exist but are DB-managed via trigger

    protected $fillable = [
        'menu_label', 'controller', 'action', 'icon',
        'parent_id', 'sort_order', 'is_active',
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

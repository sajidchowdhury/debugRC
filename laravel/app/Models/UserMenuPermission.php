<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * User Menu Permission — per-user menu visibility (mirrors legacy).
 *
 * @property int $id
 * @property int $user_id
 * @property int $menu_id
 * @property bool $can_view
 * @property bool $can_edit
 */
class UserMenuPermission extends Model
{
    protected $table = 'user_menu_permissions';

    public $timestamps = false;

    protected $fillable = ['user_id', 'menu_id', 'can_view', 'can_edit'];

    protected $casts = [
        'user_id' => 'integer',
        'menu_id' => 'integer',
        'can_view' => 'boolean',
        'can_edit' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}

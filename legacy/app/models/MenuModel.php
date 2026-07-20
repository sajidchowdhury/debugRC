<?php
// app/models/MenuModel.php

require_once '../core/Database.php';

class MenuModel {

    protected $db;

    public function __construct() {
        $this->db = new Database();
    }

public function getUserMenus($user_id) {
    $this->db->query("
        SELECT m.*, ump.can_view, ump.can_edit
        FROM menus m
        INNER JOIN user_menu_permissions ump 
            ON m.id = ump.menu_id 
           AND ump.user_id = :user_id
        WHERE m.is_active = 1 
          AND ump.can_view = 1
        ORDER BY m.sort_order ASC
    ");
    $this->db->bind(':user_id', $user_id);
    $allMenus = $this->db->resultSet();

    return $this->buildMenuTree($allMenus);
}
    /**
     * Build 3-level hierarchical menu tree
     */
    private function buildMenuTree($menus) {
        $tree = [];
        $children = [];

        // First: Separate top-level and children
        foreach ($menus as $menu) {
            if ($menu['parent_id'] == 0) {
                $tree[$menu['id']] = $menu;
                $tree[$menu['id']]['children'] = [];
            } else {
                $children[$menu['parent_id']][] = $menu;
            }
        }

        // Second: Attach Level 2 children to Level 1
        foreach ($tree as &$parent) {
            if (isset($children[$parent['id']])) {
                foreach ($children[$parent['id']] as $child) {
                    $child['children'] = []; // Prepare for Level 3

                    // Attach Level 3 (Sub-sub menus)
                    if (isset($children[$child['id']])) {
                        $child['children'] = $children[$child['id']];
                    }

                    $parent['children'][] = $child;
                }
            }
        }

        return array_values($tree); // Return as indexed array
    }

    /**
     * All active menus as a hierarchical tree for permission editor.
     */
    public function getAllMenusHierarchical(): array
    {
        return $this->buildMenuTree($this->getAllMenus());
    }

        public function getAllMenus() {
        $this->db->query("
            SELECT 
                id,
                menu_name,
                menu_link,
                controller,
                action,
                icon,
                parent_id,
                sort_order,
                section,
                is_active
            FROM menus 
            WHERE is_active = 1
            ORDER BY section ASC, sort_order ASC, menu_name ASC
        ");
        return $this->db->resultSet();
    }

    
}
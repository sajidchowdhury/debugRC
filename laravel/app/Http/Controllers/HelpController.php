<?php

namespace App\Http\Controllers;

use App\Services\Help\HelpService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Help System Controller — serves HTML partials for the offcanvas/sheet components.
 *
 * Phase 2 scaffold: two endpoints returning rendered Blade partials.
 *   - GET /help/menu/{key}   → components.help.menu-content
 *   - GET /help/module/{key} → components.help.module-content
 *
 * Both return HTTP 200 with an empty-state view when content does not exist yet
 * (Phase 7 authors the content files). This means the UI degrades gracefully
 * instead of 404-ing.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §4.2, §4.3
 */
class HelpController extends Controller
{
    public function __construct(private readonly HelpService $help)
    {
    }

    /**
     * GET /help/menu/{key} — render a single menu's Bangla help card.
     *
     * @param  Request  $request
     * @param  string   $key  e.g. 'sales.invoice'
     * @return View
     */
    public function menu(Request $request, string $key): View
    {
        $content = $this->help->loadMenuContent($key);
        $module = $content ? $this->help->loadModuleContent($content['module'] ?? '') : null;

        return view('components.help.menu-content', [
            'key' => $key,
            'content' => $content,
            'module' => $module,
            'isAjax' => $request->ajax(),
        ]);
    }

    /**
     * GET /help/module/{key} — render a module's intro + menu list.
     *
     * @param  Request  $request
     * @param  string   $key  e.g. 'sales'
     * @return View
     */
    public function module(Request $request, string $key): View
    {
        $module = $this->help->loadModuleContent($key);

        return view('components.help.module-content', [
            'key' => $key,
            'module' => $module,
            'isAjax' => $request->ajax(),
        ]);
    }
}

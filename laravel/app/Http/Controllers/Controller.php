<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base application controller.
 *
 * Laravel 11+ no longer ships this file by default — it must exist so that
 * all controllers in app/Http/Controllers/Admin can `extends Controller`.
 *
 * The AuthorizesRequests + ValidatesRequests traits provide the
 * authorize() and validate() helpers used throughout the codebase.
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}

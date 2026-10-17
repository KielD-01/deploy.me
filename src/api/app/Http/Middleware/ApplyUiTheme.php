<?php

namespace App\Http\Middleware;

use App\Support\Ui;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the request in the signed-in person's theme: the theme's views come
 * first, and every view gets `$ui` (theme, mode, density, layouts).
 */
class ApplyUiTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        $ui = Ui::preferences($request->user());

        Ui::apply($ui['theme']);
        View::share('ui', $ui);

        return $next($request);
    }
}

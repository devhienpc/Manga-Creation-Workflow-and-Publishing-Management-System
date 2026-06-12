<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     * Trả về null để response JSON 401 thay vì redirect (phù hợp cho API)
     */
    protected function redirectTo(Request $request): ?string
    {
        // Với API request, trả về null để throw AuthenticationException -> 401 JSON
        return $request->expectsJson() ? null : route('login');
    }
}

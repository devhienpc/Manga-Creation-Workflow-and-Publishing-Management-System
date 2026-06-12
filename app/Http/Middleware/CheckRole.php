<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware kiểm tra vai trò người dùng (Role-based Access Control)
 * Sử dụng: ->middleware('role:mangaka') hoặc ->middleware('role:editorial_board')
 * Hoặc nhiều vai trò: ->middleware('role:mangaka,editorial_board')
 */
class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param string $roles Danh sách vai trò được phép, phân cách bằng dấu phẩy
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Kiểm tra người dùng đã đăng nhập chưa
        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please login to continue.',
            ], 401);
        }

        // Kiểm tra vai trò của người dùng có nằm trong danh sách được phép không
        if (! in_array($request->user()->role, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. You do not have permission to perform this action.',
                'required_roles' => $roles,
                'your_role'      => $request->user()->role,
            ], 403);
        }

        return $next($request);
    }
}

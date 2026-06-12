<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
|--------------------------------------------------------------------------
| Hướng dẫn đăng ký Middleware "role" trong Laravel 11
|--------------------------------------------------------------------------
|
| Trong Laravel 11, không còn file app/Http/Kernel.php.
| Thay vào đó, middleware được đăng ký trong bootstrap/app.php.
|
| Trong Laravel 10, bạn cần thêm vào app/Http/Kernel.php:
|   protected $middlewareAliases = [
|       ...
|       'role' => \App\Http\Middleware\CheckRole::class,
|   ];
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // =============================================
        // ĐĂNG KÝ MIDDLEWARE 'role' CHO LARAVEL 11
        // =============================================
        // Sau khi đăng ký, có thể dùng trong routes:
        //   ->middleware('role:mangaka')
        //   ->middleware('role:editorial_board')
        //   ->middleware('role:mangaka,editorial_board')
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Cấu hình middleware cho API (nếu cần)
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

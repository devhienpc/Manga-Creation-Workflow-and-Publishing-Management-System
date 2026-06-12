<?php

use App\Http\Controllers\ChapterController;
use App\Http\Controllers\SeriesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Manga Management System
|--------------------------------------------------------------------------
|
| Tất cả route ở đây được prefix với /api và middleware 'api' tự động.
| Xác thực sử dụng Laravel Sanctum (token-based).
|
| Cách sử dụng Middleware phân quyền:
|   ->middleware('role:mangaka')           -- Chỉ Mangaka
|   ->middleware('role:editorial_board')   -- Chỉ Ban biên tập
|   ->middleware('role:mangaka,editorial_board') -- Cả hai
|
*/

// =============================================
// ROUTE CÔNG KHAI (Không cần đăng nhập)
// =============================================
Route::get('/health', fn () => response()->json([
    'status'  => 'ok',
    'service' => 'Manga Management API',
    'version' => '1.0.0',
]));


// =============================================
// ROUTE YÊU CẦU ĐĂNG NHẬP (auth:sanctum)
// =============================================
Route::middleware('auth:sanctum')->group(function () {

    // Lấy thông tin user hiện tại
    Route::get('/user', fn (Request $request) => $request->user());

    // ------------------------------------------
    // PHÂN HỆ 1: SERIES MANAGEMENT
    // ------------------------------------------

    // Lấy danh sách series (cả mangaka và editorial_board đều xem được)
    Route::get('/series', [SeriesController::class, 'index'])
         ->middleware('role:mangaka,editorial_board')
         ->name('series.index');

    // Xem chi tiết một series
    Route::get('/series/{series}', [SeriesController::class, 'show'])
         ->middleware('role:mangaka,editorial_board')
         ->name('series.show');

    // [MANGAKA] Tạo mới series
    // Chỉ Mangaka mới được tạo series, status mặc định 'pending'
    Route::post('/series', [SeriesController::class, 'store'])
         ->middleware('role:mangaka')
         ->name('series.store');

    // [EDITORIAL BOARD] Duyệt hoặc từ chối series
    // Chỉ Ban biên tập mới được thực hiện review
    Route::patch('/series/{series}/review', [SeriesController::class, 'review'])
         ->middleware('role:editorial_board')
         ->name('series.review');


    // ------------------------------------------
    // PHÂN HỆ 2: CHAPTER & PAGE MANAGEMENT
    // ------------------------------------------

    // Lấy danh sách chapter của một series
    Route::get('/series/{series}/chapters', [ChapterController::class, 'index'])
         ->middleware('role:mangaka,editorial_board')
         ->name('chapters.index');

    // Xem chi tiết một chapter (bao gồm tất cả các trang)
    Route::get('/series/{series}/chapters/{chapter}', [ChapterController::class, 'show'])
         ->middleware('role:mangaka,editorial_board')
         ->name('chapters.show');

    // [MANGAKA] Tạo mới chapter và upload ảnh
    // Sử dụng POST thay vì PUT/PATCH vì cần xử lý multipart/form-data (file upload)
    // Chỉ Mangaka sở hữu series mới được tạo chapter (kiểm tra thêm trong Controller)
    Route::post('/series/{series}/chapters', [ChapterController::class, 'store'])
         ->middleware('role:mangaka')
         ->name('chapters.store');
});

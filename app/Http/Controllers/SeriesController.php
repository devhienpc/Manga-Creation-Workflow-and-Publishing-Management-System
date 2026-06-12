<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewSeriesRequest;
use App\Http\Requests\StoreSeriesRequest;
use App\Models\Series;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeriesController extends Controller
{
    /**
     * Tạo mới một Series.
     *
     * Route: POST /api/series
     * Middleware: auth, role:mangaka
     *
     * Chỉ Mangaka được phép tạo Series mới.
     * Status mặc định luôn là 'pending' (chờ duyệt từ ban biên tập).
     * author_id được lấy từ user đang đăng nhập - không cho client tự truyền lên.
     *
     * @param StoreSeriesRequest $request Dữ liệu đã được validate
     * @return JsonResponse
     */
    public function store(StoreSeriesRequest $request): JsonResponse
    {
        // Lấy dữ liệu đã validate từ Form Request
        $validated = $request->validated();

        // Tạo Series mới - author_id được gán từ user hiện tại (bảo mật hơn)
        // status không cần truyền lên vì đã có default 'pending' ở migration
        $series = Series::create([
            'title'       => $validated['title'],
            'description' => $validated['description'],
            'type'        => $validated['type'],
            'author_id'   => $request->user()->id, // Tự động gán tác giả
            'status'      => 'pending',             // Luôn là pending khi mới tạo
        ]);

        // Load thêm thông tin tác giả để trả về
        $series->load('author:id,name,email');

        return response()->json([
            'success' => true,
            'message' => 'Bộ truyện đã được tạo thành công và đang chờ ban biên tập duyệt.',
            'data'    => $series,
        ], 201); // 201 Created
    }

    /**
     * Ban biên tập duyệt hoặc từ chối một Series.
     *
     * Route: PATCH /api/series/{series}/review
     * Middleware: auth, role:editorial_board
     *
     * Nhận vào:
     *   - series_id: từ route parameter
     *   - action: 'approve' | 'reject'
     *   - rejection_reason: (bắt buộc khi action = 'reject')
     *
     * Logic chuyển trạng thái:
     *   - 'approve' -> status = 'active'
     *   - 'reject'  -> status = 'dropped'
     *
     * @param ReviewSeriesRequest $request Dữ liệu đã được validate
     * @param Series $series Series cần duyệt (Route Model Binding)
     * @return JsonResponse
     */
    public function review(ReviewSeriesRequest $request, Series $series): JsonResponse
    {
        // Chỉ duyệt được các Series đang ở trạng thái 'pending'
        // Tránh trường hợp duyệt lại series đã active hoặc dropped
        if (! $series->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể duyệt các bộ truyện đang ở trạng thái "pending".',
                'current_status' => $series->status,
            ], 422);
        }

        $action = $request->validated()['action'];

        // =============================================
        // XỬ LÝ CHUYỂN TRẠNG THÁI DỰA TRÊN ACTION
        // =============================================
        if ($action === 'approve') {
            // Duyệt series -> chuyển sang 'active' (bắt đầu phát hành)
            $series->update(['status' => 'active']);

            $message = "Bộ truyện \"{$series->title}\" đã được duyệt và bắt đầu phát hành.";

        } elseif ($action === 'reject') {
            // Từ chối series -> chuyển sang 'dropped' (bị dừng)
            $series->update(['status' => 'dropped']);

            $message = "Bộ truyện \"{$series->title}\" đã bị từ chối.";
        }

        // Load lại thông tin tác giả để trả về đầy đủ
        $series->load('author:id,name,email');

        return response()->json([
            'success'  => true,
            'message'  => $message,
            'data'     => $series,
            'action'   => $action,
            'reviewed_by' => $request->user()->name,
        ], 200);
    }

    /**
     * Lấy danh sách tất cả Series (có phân trang).
     *
     * Route: GET /api/series
     * Middleware: auth
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Series::with('author:id,name')
                       ->latest();

        // Lọc theo status nếu có truyền vào query parameter
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Lọc theo type nếu có truyền vào
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Nếu là mangaka, chỉ xem series của chính mình
        if ($request->user()->role === 'mangaka') {
            $query->where('author_id', $request->user()->id);
        }

        $series = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $series,
        ]);
    }

    /**
     * Xem chi tiết một Series.
     *
     * Route: GET /api/series/{series}
     * Middleware: auth
     *
     * @param Series $series
     * @return JsonResponse
     */
    public function show(Series $series): JsonResponse
    {
        $series->load(['author:id,name,email', 'chapters']);

        return response()->json([
            'success' => true,
            'data'    => $series,
        ]);
    }
}

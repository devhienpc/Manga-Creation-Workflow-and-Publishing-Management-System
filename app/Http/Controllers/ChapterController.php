<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChapterRequest;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChapterController extends Controller
{
    /**
     * Tạo mới một Chapter và upload các trang ảnh.
     *
     * Route: POST /api/series/{series}/chapters
     * Middleware: auth, role:mangaka
     *
     * Logic tổng quan:
     * 1. Kiểm tra quyền sở hữu: Chỉ Mangaka sở hữu Series mới được tạo Chapter.
     * 2. Kiểm tra Series đang ở trạng thái 'active' mới được tạo Chapter.
     * 3. Kiểm tra chapter_number không bị trùng trong cùng series.
     * 4. Upload tất cả ảnh vào thư mục: storage/app/public/series_{id}/chapter_{num}/
     * 5. Đặt tên file theo dạng: page_{order_index}_{timestamp}.{ext}
     * 6. Lưu Chapter và các Page vào database trong một Transaction.
     *
     * @param StoreChapterRequest $request Dữ liệu đã được validate
     * @param Series $series Series cần thêm chapter (Route Model Binding)
     * @return JsonResponse
     */
    public function store(StoreChapterRequest $request, Series $series): JsonResponse
    {
        $currentUser = $request->user();

        // =============================================
        // BƯỚC 1: KIỂM TRA QUYỀN SỞ HỮU SERIES
        // =============================================
        // Chỉ Mangaka là tác giả của bộ truyện mới được thêm chapter
        if (! $series->isOwnedBy($currentUser->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thêm chapter cho bộ truyện này.',
                'hint'    => 'Chỉ tác giả của bộ truyện mới được tạo chapter.',
            ], 403);
        }

        // =============================================
        // BƯỚC 2: KIỂM TRA TRẠNG THÁI SERIES
        // =============================================
        // Series phải đang ở trạng thái 'active' mới được thêm chapter
        if (! $series->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ có thể tạo chapter cho bộ truyện đang hoạt động (status: active).',
                'current_status' => $series->status,
            ], 422);
        }

        $validated = $request->validated();

        // =============================================
        // BƯỚC 3: KIỂM TRA SỐ CHƯƠNG KHÔNG BỊ TRÙNG
        // =============================================
        $chapterExists = Chapter::where('series_id', $series->id)
                                ->where('chapter_number', $validated['chapter_number'])
                                ->exists();

        if ($chapterExists) {
            return response()->json([
                'success' => false,
                'message' => "Chapter số {$validated['chapter_number']} đã tồn tại trong bộ truyện này.",
            ], 409); // 409 Conflict
        }

        // =============================================
        // BƯỚC 4 & 5 & 6: XỬ LÝ UPLOAD FILE VÀ LƯU DATABASE
        // =============================================
        // Sử dụng DB Transaction để đảm bảo tính toàn vẹn dữ liệu:
        // Nếu có bất kỳ lỗi nào xảy ra, toàn bộ thao tác sẽ được rollback.
        // Điều này ngăn chặn tình trạng: chapter được tạo nhưng ảnh bị lỗi,
        // hoặc một số ảnh được lưu nhưng record trong DB bị thiếu.
        try {
            $chapter = DB::transaction(function () use ($series, $validated, $request) {

                // --- Tạo bản ghi Chapter trong database ---
                $chapter = Chapter::create([
                    'series_id'      => $series->id,
                    'chapter_number' => $validated['chapter_number'],
                    'deadline'       => $validated['deadline'] ?? null,
                ]);

                // --- Xử lý upload từng file ảnh ---
                $pages = $request->file('pages'); // Lấy mảng UploadedFile

                // Chuẩn bị mảng để insert hàng loạt vào bảng pages (tăng hiệu năng)
                $pageRecords = [];

                foreach ($pages as $index => $pageFile) {
                    /**
                     * XỬ LÝ ĐẶT TÊN FILE VÀ UPLOAD
                     *
                     * Cấu trúc thư mục đích:
                     *   storage/app/public/series_{series_id}/chapter_{chapter_number}/
                     *
                     * Ví dụ với series_id=1, chapter_number=3:
                     *   storage/app/public/series_1/chapter_3/
                     *
                     * Tên file theo định dạng:
                     *   page_{order_index}_{timestamp}.{extension}
                     *
                     * Ví dụ:
                     *   page_1_1700000000.jpg
                     *   page_2_1700000001.png
                     *
                     * Timestamp được tạo bằng microtime(true) * 1000 để có độ chính xác cao
                     * hơn, tránh trùng lặp khi upload nhiều ảnh cùng lúc.
                     */
                    $orderIndex = $index + 1; // Thứ tự trang bắt đầu từ 1

                    // Lấy phần mở rộng file gốc (jpg, png, webp, ...)
                    $extension = $pageFile->getClientOriginalExtension();

                    // Tạo timestamp millisecond để đảm bảo tên file unique
                    $timestamp = (int) round(microtime(true) * 1000);

                    // Tên file theo yêu cầu: page_{order_index}_{timestamp}.{extension}
                    $fileName = "page_{$orderIndex}_{$timestamp}.{$extension}";

                    // Tạo đường dẫn thư mục đích (tương đối từ storage/app/public/)
                    // Ví dụ: "series_1/chapter_3"
                    $directory = "series_{$series->id}/chapter_{$validated['chapter_number']}";

                    /**
                     * Lưu file ảnh vào storage
                     *
                     * Storage::disk('public')->putFileAs():
                     *   - Disk 'public' = storage/app/public/
                     *   - $directory: thư mục con (tự tạo nếu chưa có)
                     *   - $pageFile: file UploadedFile cần lưu
                     *   - $fileName: tên file sau khi lưu
                     *
                     * Trả về: đường dẫn tương đối từ storage/app/public/
                     * Ví dụ: "series_1/chapter_3/page_1_1700000000.jpg"
                     *
                     * Để truy cập qua URL công khai cần chạy: php artisan storage:link
                     * Sau đó truy cập: http://localhost/storage/series_1/chapter_3/page_1_...jpg
                     */
                    $storedPath = Storage::disk('public')->putFileAs(
                        $directory,
                        $pageFile,
                        $fileName
                    );

                    // Thêm vào mảng để insert hàng loạt
                    $pageRecords[] = [
                        'chapter_id'  => $chapter->id,
                        'image_url'   => $storedPath, // Lưu đường dẫn tương đối
                        'order_index' => $orderIndex,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ];
                }

                // Insert tất cả page records trong một câu lệnh SQL duy nhất
                // Hiệu quả hơn nhiều so với tạo từng record riêng lẻ trong vòng lặp
                Page::insert($pageRecords);

                return $chapter;
            });

            // Load thêm quan hệ pages để trả về response đầy đủ
            $chapter->load('pages');

            // Tính toán số lượng trang đã upload thành công
            $pageCount = $chapter->pages->count();

            return response()->json([
                'success'     => true,
                'message'     => "Chapter {$chapter->chapter_number} đã được tạo thành công với {$pageCount} trang.",
                'data'        => [
                    'chapter'    => $chapter,
                    'page_count' => $pageCount,
                    // Trả về URL đầy đủ của từng trang để client có thể hiển thị ngay
                    'pages_urls' => $chapter->pages->map(fn($page) => [
                        'order_index' => $page->order_index,
                        'url'         => asset('storage/' . $page->image_url),
                    ]),
                ],
            ], 201);

        } catch (\Exception $e) {
            /**
             * XỬ LÝ LỖI VÀ DỌN DẸP FILE ĐÃ UPLOAD
             *
             * Nếu Transaction thất bại (lỗi database), các file đã được upload
             * vào storage vẫn còn đó. Cần xóa toàn bộ thư mục chapter đó đi
             * để tránh rác files trên server.
             *
             * Lưu ý: Nếu lỗi xảy ra trước khi upload bất kỳ file nào,
             * Storage::deleteDirectory() sẽ không làm gì cả (thư mục chưa tồn tại).
             */
            $directory = "series_{$series->id}/chapter_{$validated['chapter_number']}";
            Storage::disk('public')->deleteDirectory($directory);

            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra trong quá trình tạo chapter. Vui lòng thử lại.',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }
    }

    /**
     * Lấy danh sách tất cả Chapter của một Series.
     *
     * Route: GET /api/series/{series}/chapters
     * Middleware: auth
     *
     * @param Series $series
     * @return JsonResponse
     */
    public function index(Series $series): JsonResponse
    {
        $chapters = $series->chapters()
                           ->with('pages')
                           ->orderBy('chapter_number')
                           ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $chapters,
        ]);
    }

    /**
     * Xem chi tiết một Chapter với đầy đủ các trang.
     *
     * Route: GET /api/series/{series}/chapters/{chapter}
     * Middleware: auth
     *
     * @param Series $series
     * @param Chapter $chapter
     * @return JsonResponse
     */
    public function show(Series $series, Chapter $chapter): JsonResponse
    {
        // Kiểm tra chapter có thuộc series này không
        if ($chapter->series_id !== $series->id) {
            return response()->json([
                'success' => false,
                'message' => 'Chapter này không thuộc bộ truyện được chỉ định.',
            ], 404);
        }

        $chapter->load('pages');

        return response()->json([
            'success' => true,
            'data'    => [
                'chapter' => $chapter,
                'pages'   => $chapter->pages->map(fn($page) => [
                    'id'          => $page->id,
                    'order_index' => $page->order_index,
                    'url'         => asset('storage/' . $page->image_url),
                ]),
            ],
        ]);
    }
}

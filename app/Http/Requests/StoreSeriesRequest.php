<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request Validation cho việc tạo mới Series
 * Chỉ Mangaka mới được tạo Series (được kiểm soát ở Route/Middleware)
 */
class StoreSeriesRequest extends FormRequest
{
    /**
     * Xác định người dùng có quyền thực hiện request này không.
     * Việc kiểm tra role đã được xử lý bởi Middleware 'role:mangaka'
     * nên ở đây chỉ cần kiểm tra đã đăng nhập.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Các quy tắc validation cho request.
     */
    public function rules(): array
    {
        return [
            // title: Bắt buộc, kiểu chuỗi, tối đa 255 ký tự, phải unique trong bảng series
            'title' => [
                'required',
                'string',
                'max:255',
                'unique:series,title',
            ],

            // description: Bắt buộc, kiểu chuỗi, tối thiểu 10 ký tự
            'description' => [
                'required',
                'string',
                'min:10',
            ],

            // type: Bắt buộc, chỉ chấp nhận 'weekly' hoặc 'monthly'
            'type' => [
                'required',
                'string',
                'in:weekly,monthly',
            ],
        ];
    }

    /**
     * Tùy chỉnh thông báo lỗi bằng tiếng Việt
     */
    public function messages(): array
    {
        return [
            'title.required'     => 'Tiêu đề bộ truyện là bắt buộc.',
            'title.unique'       => 'Tiêu đề bộ truyện này đã tồn tại, vui lòng chọn tiêu đề khác.',
            'title.max'          => 'Tiêu đề không được vượt quá 255 ký tự.',
            'description.required' => 'Mô tả nội dung là bắt buộc.',
            'description.min'    => 'Mô tả phải có ít nhất 10 ký tự.',
            'type.required'      => 'Loại phát hành là bắt buộc.',
            'type.in'            => 'Loại phát hành phải là "weekly" (hàng tuần) hoặc "monthly" (hàng tháng).',
        ];
    }

    /**
     * Xử lý khi validation thất bại - trả về JSON thay vì redirect
     * Hữu ích khi dùng cho cả web lẫn API
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}

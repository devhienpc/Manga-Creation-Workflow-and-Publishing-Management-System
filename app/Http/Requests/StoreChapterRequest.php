<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request Validation cho việc tạo mới Chapter và upload ảnh trang truyện
 */
class StoreChapterRequest extends FormRequest
{
    /**
     * Xác định người dùng có quyền thực hiện request này không.
     * Việc kiểm tra quyền sở hữu Series được thực hiện trong Controller.
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
            // chapter_number: Bắt buộc, số nguyên dương
            'chapter_number' => [
                'required',
                'integer',
                'min:1',
            ],

            // deadline: Không bắt buộc, phải là ngày hợp lệ, không được là ngày trong quá khứ
            'deadline' => [
                'nullable',
                'date',
                'after_or_equal:today',
            ],

            // pages: Bắt buộc, phải là mảng, tối thiểu 1 trang, tối đa 100 trang
            'pages' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],

            // pages.*: Mỗi phần tử trong mảng phải là file ảnh hợp lệ
            // Chấp nhận: jpeg, jpg, png, webp
            // Giới hạn kích thước: tối đa 5MB mỗi file (5120 KB)
            'pages.*' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:5120',
            ],
        ];
    }

    /**
     * Tùy chỉnh thông báo lỗi bằng tiếng Việt
     */
    public function messages(): array
    {
        return [
            'chapter_number.required' => 'Số chương là bắt buộc.',
            'chapter_number.integer' => 'Số chương phải là số nguyên.',
            'chapter_number.min' => 'Số chương phải lớn hơn 0.',
            'deadline.date' => 'Deadline phải là ngày hợp lệ (YYYY-MM-DD).',
            'deadline.after_or_equal' => 'Deadline không được là ngày trong quá khứ.',
            'pages.required' => 'Vui lòng upload ít nhất một trang truyện.',
            'pages.array' => 'Dữ liệu trang truyện không hợp lệ.',
            'pages.min' => 'Vui lòng upload ít nhất 1 trang truyện.',
            'pages.max' => 'Một chương không được có quá 100 trang.',
            'pages.*.required' => 'File ảnh không được để trống.',
            'pages.*.image' => 'Tất cả các file phải là ảnh.',
            'pages.*.mimes' => 'Ảnh chỉ chấp nhận định dạng: JPEG, JPG, PNG, WEBP.',
            'pages.*.max' => 'Kích thước mỗi ảnh không được vượt quá 5MB.',
        ];
    }

    /**
     * Tùy chỉnh tên thuộc tính hiển thị trong thông báo lỗi
     */
    public function attributes(): array
    {
        return [
            'chapter_number' => 'số chương',
            'deadline' => 'hạn nộp bản thảo',
            'pages' => 'danh sách trang truyện',
            'pages.*' => 'file ảnh trang truyện',
        ];
    }

    /**
     * Xử lý khi validation thất bại - trả về JSON
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}

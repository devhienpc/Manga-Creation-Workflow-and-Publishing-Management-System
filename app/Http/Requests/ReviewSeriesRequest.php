<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request Validation cho việc duyệt/từ chối Series
 * Chỉ Editorial Board mới được thực hiện action này
 */
class ReviewSeriesRequest extends FormRequest
{
    /**
     * Xác định người dùng có quyền thực hiện request này không.
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
            // action: Chỉ chấp nhận 'approve' (duyệt) hoặc 'reject' (từ chối)
            'action' => [
                'required',
                'string',
                'in:approve,reject',
            ],

            // Lý do từ chối - bắt buộc khi action là 'reject'
            'rejection_reason' => [
                'required_if:action,reject',
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * Tùy chỉnh thông báo lỗi bằng tiếng Việt
     */
    public function messages(): array
    {
        return [
            'action.required'          => 'Hành động xét duyệt là bắt buộc.',
            'action.in'                => 'Hành động phải là "approve" (duyệt) hoặc "reject" (từ chối).',
            'rejection_reason.required_if' => 'Lý do từ chối là bắt buộc khi bạn chọn hành động "reject".',
            'rejection_reason.max'     => 'Lý do từ chối không được vượt quá 500 ký tự.',
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
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}

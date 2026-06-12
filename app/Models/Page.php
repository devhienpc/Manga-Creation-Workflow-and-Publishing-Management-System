<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Page extends Model
{
    use HasFactory;

    /**
     * Các cột được phép gán hàng loạt
     */
    protected $fillable = [
        'chapter_id',
        'image_url',
        'order_index',
    ];

    /**
     * Khai báo kiểu dữ liệu cho các cột
     */
    protected $casts = [
        'order_index' => 'integer',
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /**
     * Một Page thuộc về một Chapter
     * Page -> Chapter (many-to-one)
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    // =============================================
    // ACCESSORS
    // =============================================

    /**
     * Tự động trả về URL đầy đủ có thể truy cập công khai
     * khi lấy giá trị image_url
     */
    public function getImageFullUrlAttribute(): string
    {
        return asset('storage/' . $this->image_url);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chapter extends Model
{
    use HasFactory;

    /**
     * Các cột được phép gán hàng loạt
     */
    protected $fillable = [
        'series_id',
        'chapter_number',
        'deadline',
    ];

    /**
     * Khai báo kiểu dữ liệu cho các cột
     */
    protected $casts = [
        'chapter_number' => 'integer',
        'deadline'       => 'date',
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /**
     * Một Chapter thuộc về một Series
     * Chapter -> Series (many-to-one)
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    /**
     * Một Chapter có nhiều Page (trang truyện)
     * Chapter -> Page (one-to-many)
     * Mặc định sắp xếp theo order_index tăng dần
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('order_index');
    }
}

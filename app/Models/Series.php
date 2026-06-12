<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    use HasFactory;

    /**
     * Các cột được phép gán hàng loạt (mass assignment)
     */
    protected $fillable = [
        'title',
        'description',
        'author_id',
        'status',
        'type',
    ];

    /**
     * Khai báo kiểu dữ liệu cho các cột
     */
    protected $casts = [
        'status' => 'string',
        'type'   => 'string',
    ];

    // =============================================
    // RELATIONSHIPS
    // =============================================

    /**
     * Một Series thuộc về một User (tác giả - Mangaka)
     * Series -> User (many-to-one)
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Một Series có nhiều Chapter
     * Series -> Chapter (one-to-many)
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class);
    }

    // =============================================
    // HELPER METHODS
    // =============================================

    /**
     * Kiểm tra xem Series có đang ở trạng thái chờ duyệt không
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Kiểm tra xem Series có đang hoạt động không
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Kiểm tra xem một User có phải là tác giả của Series này không
     */
    public function isOwnedBy(int $userId): bool
    {
        return $this->author_id === $userId;
    }
}

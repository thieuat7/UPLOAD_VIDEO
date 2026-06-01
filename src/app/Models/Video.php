<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    // Thêm mảng này để cho phép lưu các trường dữ liệu từ Form vào Database
    protected $fillable = [
    'title',
    'original_path',
    'hls_path',
    'status',

    'processing_started_at',
    'completed_at',
    'processing_seconds',
    ];
    protected $casts = [
    'processing_started_at' => 'datetime',
    'completed_at' => 'datetime',
    'processing_seconds' => 'float',
    ];
}

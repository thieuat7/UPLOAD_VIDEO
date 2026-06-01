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
    ];
}

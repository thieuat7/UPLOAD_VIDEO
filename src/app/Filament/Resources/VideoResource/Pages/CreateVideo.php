<?php

namespace App\Filament\Resources\VideoResource\Pages;

use App\Filament\Resources\VideoResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Jobs\ConvertVideoForStreaming;

class CreateVideo extends CreateRecord
{
    protected static string $resource = VideoResource::class;

    protected function afterCreate(): void
    {
        // Gửi thông tin video vừa tạo vào hàng đợi để FFmpeg xử lý ngầm
        ConvertVideoForStreaming::dispatch($this->record);
    }
}

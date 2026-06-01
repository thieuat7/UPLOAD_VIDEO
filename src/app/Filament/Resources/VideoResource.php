<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VideoResource\Pages;
use App\Models\Video;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Storage;

class VideoResource extends Resource
{
    protected static ?string $model = Video::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->latest();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                FileUpload::make('original_path')
                    ->label('Tải video lên (Định dạng MP4)')
                    ->disk('local')
                    ->directory('temp_videos')
                    ->acceptedFileTypes(['video/mp4'])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('original_path')
                    ->label('Original Path')
                    ->limit(40)
                    ->tooltip(fn($state) => $state)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('hls_path')
                    ->label('HLS Path')
                    ->limit(40)
                    ->tooltip(fn($state) => $state)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('processing_seconds')
                    ->label('Total Time')
                    ->suffix(' sec')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Nút bấm mở Modal xem Video streaming từ MinIO công khai
                Action::make('play_video')
                    ->label('Xem Video')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    // Chỉ hiển thị nút khi video đã convert xong và có file hls_path
                    ->visible(fn(Video $record) => $record->status === 'completed' && !empty($record->hls_path))
                    ->modalHeading(fn(Video $record) => "Đang phát: " . $record->title)
                    ->modalSubmitAction(false) // Ẩn nút hành động không cần thiết
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn(Video $record) => view('video-player', [
                        'videoUrl' => Storage::disk('minio')->url($record->hls_path),
                        'recordId' => $record->id,
                    ])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVideos::route('/'),
            'create' => Pages\CreateVideo::route('/create'),
            'edit' => Pages\EditVideo::route('/{record}/edit'),
        ];
    }
}

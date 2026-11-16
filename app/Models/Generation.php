<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory, HasUuids;

    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_charge_nano' => 'integer',
            'estimated_max_charge_nano' => 'integer',
            'provider_cost_nano' => 'integer',
            'charge_nano' => 'integer',
            'image_url_expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * Fail a generation that never reached Pixelforge (rejected, or we gave up).
     *
     * One guarded UPDATE: it only applies while the generation is still queued
     * and has no provider id. If a webhook has already linked or advanced it,
     * the provider's word wins and this does nothing.
     */
    public static function failUnsubmitted(string $id, string $code, string $message): bool
    {
        return static::query()
            ->whereKey($id)
            ->whereNull('provider_id')
            ->where('status', GenerationStatus::Queued->value)
            ->update([
                'status' => GenerationStatus::Failed->value,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }
}

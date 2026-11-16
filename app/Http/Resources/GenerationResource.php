<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Pricing\Nanodollars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Generation */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $imageExpired = $this->image_url_expires_at?->isPast() ?? false;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'is_final' => $this->status->isFinal(),
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_charge' => Nanodollars::format($this->estimated_charge_nano),
            'estimated_max_charge' => Nanodollars::format($this->estimated_max_charge_nano),
            // Only a settled (succeeded) generation has a real price. A failed one is free.
            'charge' => $this->isSettled() ? Nanodollars::format($this->charge_nano) : null,
            'image_url' => $this->status === GenerationStatus::Succeeded && ! $imageExpired ? $this->image_url : null,
            'image_expired' => $this->status === GenerationStatus::Succeeded && $imageExpired,
            'error_message' => $this->status === GenerationStatus::Failed ? $this->error_message : null,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}

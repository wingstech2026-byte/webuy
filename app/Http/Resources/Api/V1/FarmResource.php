<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Farm;
use App\Models\FarmReward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Farm */
class FarmResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reward = FarmReward::for($this->plant);

        return [
            'code' => $this->code,
            'plant' => $this->plant,
            'plant_label' => Farm::PLANTS[$this->plant] ?? $this->plant,
            'water' => $this->water,
            'target' => $this->target,
            'progress_percent' => $this->progress_percent,
            'stage' => $this->stage,
            'harvests' => $this->harvests,
            'owner' => $this->user->first_name,
            'reward' => $reward ? ['label' => $reward->label, 'type' => $reward->type] : null,
            'friend_url' => route('farm.friend', $this->code),
        ];
    }
}

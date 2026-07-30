<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ApprovalOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SetApprovalOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller applies the manageProduction policy
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approval_order' => ['required', new Enum(ApprovalOrder::class)],
        ];
    }
}

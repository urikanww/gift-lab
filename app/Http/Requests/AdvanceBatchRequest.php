<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk-advance selected production jobs. SHIPPED is intentionally excluded - it
 * needs a per-parcel consignment_ref + carrier, so it stays on the single-job
 * dialog. Only the ref-free bulk transitions are allowed here.
 */
class AdvanceBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'job_ids' => ['required', 'array', 'min:1', 'max:200'],
            'job_ids.*' => ['integer', 'exists:production_jobs,id'],
            'state' => ['required', 'string', 'in:IN_PRODUCTION,CLOSED'],
        ];
    }
}

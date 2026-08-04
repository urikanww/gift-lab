<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Validates + resolves the reporting date range. Defaults to the last 90 days
 * when unset. Authorization is enforced by the route's permission middleware.
 * Bounds are built in the app timezone (ReportingService buckets rows in the
 * same tz as these bounds).
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * Resolved [from, to] as day-bounded Carbon instances; defaults last 90 days.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(): array
    {
        $to = $this->filled('to') ? Carbon::parse($this->string('to')->toString()) : Carbon::now();
        $from = $this->filled('from') ? Carbon::parse($this->string('from')->toString()) : (clone $to)->subDays(90);

        return [$from->startOfDay(), $to->endOfDay()];
    }
}

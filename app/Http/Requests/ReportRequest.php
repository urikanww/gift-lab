<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Validates + resolves the reporting date range. Defaults to the last 90 days
 * when unset. Authorization is enforced by the route's permission middleware.
 * Bounds are built in the app timezone (ReportingService buckets rows in the
 * same tz as these bounds).
 */
class ReportRequest extends FormRequest
{
    /** Sane upper bound on range width - roughly 3 years - to keep the CSV export and aggregates bounded. */
    private const MAX_RANGE_DAYS = 1100;

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

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('from') || ! $this->filled('to')) {
                return;
            }

            $from = Carbon::parse($this->string('from')->toString());
            $to = Carbon::parse($this->string('to')->toString());

            if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('to', 'The date range must not span more than roughly 3 years.');
            }
        });
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

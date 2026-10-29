<?php

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Support\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Filtre de période des écrans admin (?period=7d, ou ?period=custom&from=…&to=…). */
class ReportingPeriodRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(ReportingPeriod::PRESETS)],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('period') !== 'custom' || $validator->errors()->isNotEmpty()) {
                return;
            }
            $days = CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) + 1;
            if ($days > ReportingPeriod::MAX_DAYS) {
                $validator->errors()->add('to', 'La période ne peut pas dépasser '.ReportingPeriod::MAX_DAYS.' jours.');
            }
        }];
    }

    public function period(): ReportingPeriod
    {
        return ReportingPeriod::fromRequest($this->validated('period'), $this->validated('from'), $this->validated('to'));
    }
}

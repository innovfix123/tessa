<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LetterSalaryCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Standalone salary-breakup calculator for the Finance team's "Salary Tool".
 *
 * Reuses the EXACT same engine, slabs and rules as the offer/appointment-letter
 * Annexure-I autofill (App\Services\LetterSalaryCalculator) so the two never
 * drift. Two directions:
 *   - forward  (mode=ctc):   CTC  → full breakup.
 *   - backward (mode=basic): Basic → CTC → full breakup. Basic is fixed at 50%
 *     of monthly CTC, so monthly CTC = 2 × Basic and annual CTC = 24 × Basic;
 *     the calculator then derives HRA / Other / PF / ESI / PT / net from there.
 */
class SalaryToolController extends Controller
{
    /** Who can use the Salary Tool. Shoyab (#32, Finance). Add IDs to extend. */
    private const SALARY_TOOL_USER_IDS = [32];

    public function __construct(private LetterSalaryCalculator $salary) {}

    public function compute(Request $request): JsonResponse
    {
        if (! in_array((int) $request->user()->id, self::SALARY_TOOL_USER_IDS, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'mode' => 'required|in:ctc,basic',
            'amount' => 'required|numeric|min:0',
            'period' => 'nullable|in:annual,monthly',
            'employee_category' => 'required|in:fulltime,intern,freelancer',
        ]);

        $amount = (float) $data['amount'];

        if ($data['mode'] === 'ctc') {
            // Forward: CTC → breakdown. Input may be annual (default) or monthly.
            $annualCtc = ($data['period'] ?? 'annual') === 'monthly' ? $amount * 12 : $amount;
        } else {
            // Backward: monthly Basic → CTC. Basic = 50% of monthly CTC.
            $annualCtc = $amount * 24;
        }

        return response()->json([
            'mode' => $data['mode'],
            'employee_category' => $data['employee_category'],
            'breakup' => $this->salary->breakup($annualCtc, $data['employee_category']),
        ]);
    }
}

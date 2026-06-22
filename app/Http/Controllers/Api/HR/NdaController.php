<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Generates the employee Non-Disclosure Agreement as a PDF, auto-filled from the
 * employee's profile (name, S/O / D/O parent, address, today's date). The employee
 * downloads it from My Profile → Required Documents, signs it by hand, scans, and
 * uploads the signed copy back through the existing doc-upload flow. HR can also
 * generate any employee's NDA from the Team → employee record.
 *
 * Reuses barryvdh/laravel-dompdf — the same engine as the Letters feature
 * (see App\Services\LetterTemplateService::generatePdf).
 */
class NdaController extends Controller
{
    /** Roles allowed to generate an NDA on another employee's behalf (mirrors LetterController). */
    private const ALLOWED_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_COO,
        Role::SLUG_CFO,
        Role::SLUG_HR,
        Role::SLUG_HR_OPERATIONS,
        Role::SLUG_BUSINESS_ANALYST,
    ];

    /** GET /documents/nda — the caller's own auto-filled NDA (inline PDF). */
    public function mine(Request $request): Response
    {
        return $this->streamFor($request->user());
    }

    /** GET /employees/{id}/nda — HR generates a given employee's NDA. */
    public function forEmployee(Request $request, int $id): Response
    {
        $actor = $request->user();
        if (! $actor || ! in_array($actor->role, self::ALLOWED_ROLES, true)) {
            abort(403, 'You do not have access to generate NDAs.');
        }

        return $this->streamFor(User::findOrFail($id));
    }

    private function streamFor(User $u): Response
    {
        $pdf = Pdf::loadView('documents.nda', ['data' => $this->fieldsFor($u)])->setPaper('a4');

        return $pdf->stream('NDA-' . (Str::slug($u->name) ?: 'employee') . '.pdf');
    }

    /**
     * Build the NDA placeholders from the employee's profile. Anything still missing
     * (parent name, gender, address) renders as a blank line to complete by hand —
     * the document is hand-signed anyway.
     */
    private function fieldsFor(User $u): array
    {
        $today = Carbon::now('Asia/Kolkata');

        $prefix = match ($u->gender) {
            'male' => 'S/O',
            'female' => 'D/O',
            default => 'S/O / D/O',
        };
        $parent = trim((string) $u->parent_name);
        $parentLine = $parent !== '' ? ($prefix . ' ' . $parent) : ($prefix . ' ______________________');

        $address = trim((string) ($u->current_address ?: $u->permanent_address));
        if ($address === '') {
            $address = '__________________________________________________';
        }

        return [
            'name' => $u->name,
            'parent_line' => $parentLine,
            'address' => $address,
            'day' => $this->ordinal((int) $today->day),
            'month' => $today->format('F'),
            'year' => $today->format('Y'),
        ];
    }

    /** 1 → "1st", 2 → "2nd", 22 → "22nd", 11 → "11th", etc. */
    private function ordinal(int $n): string
    {
        if (in_array($n % 100, [11, 12, 13], true)) {
            return $n . 'th';
        }

        return $n . match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}

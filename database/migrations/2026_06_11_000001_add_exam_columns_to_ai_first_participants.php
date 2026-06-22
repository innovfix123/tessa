<?php

use App\Models\AiFirstParticipant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The 7 assessors (originally 8; Krishnan/Ranjini moved to assessees on 2026-06-11;
     *  Saran re-promoted later that day to take 5 from Perumal's queue;
     *  Akshara promoted shortly after to take 5 more from Bhoomika + Fida). */
    private const CONDUCTOR_NAMES = [
        'Yuvanesh',
        'Fida',
        'Bhoomika',
        'Perumal',
        'Sneha Prathap',
        'Saran',
        'Akshara',
    ];

    public function up(): void
    {
        Schema::table('ai_first_participants', function (Blueprint $t) {
            $t->boolean('is_exam_conductor')->default(false)->after('gmail_connected_at');
            $t->timestamp('exam_passed_at')->nullable()->after('is_exam_conductor');
            $t->string('exam_marked_by', 100)->nullable()->after('exam_passed_at');
            $t->text('exam_notes')->nullable()->after('exam_marked_by');
        });

        AiFirstParticipant::whereIn('name', self::CONDUCTOR_NAMES)->update(['is_exam_conductor' => true]);
    }

    public function down(): void
    {
        Schema::table('ai_first_participants', function (Blueprint $t) {
            $t->dropColumn(['is_exam_conductor', 'exam_passed_at', 'exam_marked_by', 'exam_notes']);
        });
    }
};

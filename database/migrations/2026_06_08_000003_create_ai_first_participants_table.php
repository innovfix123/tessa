<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_first_participants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('name', 100);
            $t->unsignedTinyInteger('squad_num');
            $t->enum('role_in_squad', ['mentor', 'associate', 'mentee']);
            $t->timestamp('claude_activated_at')->nullable();
            $t->string('claude_plan', 50)->nullable(); // pro / max / team
            $t->text('claude_notes')->nullable();
            $t->timestamps();

            $t->index('squad_num');
            $t->index('user_id');
            $t->unique(['squad_num', 'name']);
        });

        // Names whose Tessa user_id needs explicit override — either because
        // the squad-list spelling differs from users.name (Soundarya, Nehal Y),
        // or because a fuzzy `LIKE %name%` match would otherwise
        // collide with a longer name (Bala matches "Soundarya Balaraddi";
        // Nisha matches "Akshara J S Ponisha"). Always lock those exactly.
        $userIdOverrides = [
            'Soundaraya' => 62, // DB: Soundarya Balaraddi
            'Nehal Y'    => 56, // DB: Y Nehal
            'Bala'       => 2,  // DB: Bala (else fuzzy match → Soundarya Balaraddi)
            'Nisha'      => 47, // DB: Nisha (else fuzzy match → Akshara J S Ponisha)
        ];

        $squads = [
            1 => [
                'mentor'  => 'Fida',
                'assocs'  => ['Bhoomika'],
                'mentees' => ['Ayush', 'Shoyab', 'Irisha', 'Bhuvan Prasad', 'Akshara', 'Soundaraya'],
            ],
            2 => [
                'mentor'  => 'Sneha Prathap',
                'assocs'  => ['Ranjini'],
                'mentees' => ['Rishabh', 'Barkha Agarwal', 'Perumal', 'Laxmi', 'Iksha H S', 'Maari', 'Prajwal', 'Tamil Arasan', 'Sumit'],
            ],
            3 => [
                'mentor'  => 'Yuvanesh',
                'assocs'  => ['Saran', 'Bala'],
                'mentees' => ['Nandha', 'Anirudh', 'Swapna M', 'Anindita', 'Gargi Bisht', 'Sneha Sunoj', 'Deeksha', 'Nisha', 'Gousia', 'Reshma', 'Anjali Bhatt', 'Meghana', 'Dhanush', 'Suwetha S'],
            ],
            4 => [
                'mentor'  => 'Krishnan',
                'assocs'  => ['Kishore Prabakaran'],
                'mentees' => ['Nehal Y', 'Fathima K P', 'Tiyasa', 'Haripriya', 'Disha', 'Sivaranjani N', 'Sooraj', 'Anaz'],
            ],
        ];

        $resolveUserId = function (string $name) use ($userIdOverrides): ?int {
            if (isset($userIdOverrides[$name])) {
                return $userIdOverrides[$name];
            }
            $row = DB::table('users')
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->orWhere('name', 'like', '%' . $name . '%')
                ->orderByDesc('is_active')
                ->first();
            return $row?->id;
        };

        $now = now();
        $rows = [];

        foreach ($squads as $squadNum => $squad) {
            $rows[] = [
                'user_id'       => $resolveUserId($squad['mentor']),
                'name'          => $squad['mentor'],
                'squad_num'     => $squadNum,
                'role_in_squad' => 'mentor',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            foreach ($squad['assocs'] as $assoc) {
                $rows[] = [
                    'user_id'       => $resolveUserId($assoc),
                    'name'          => $assoc,
                    'squad_num'     => $squadNum,
                    'role_in_squad' => 'associate',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
            foreach ($squad['mentees'] as $mentee) {
                $rows[] = [
                    'user_id'       => $resolveUserId($mentee),
                    'name'          => $mentee,
                    'squad_num'     => $squadNum,
                    'role_in_squad' => 'mentee',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        DB::table('ai_first_participants')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_first_participants');
    }
};

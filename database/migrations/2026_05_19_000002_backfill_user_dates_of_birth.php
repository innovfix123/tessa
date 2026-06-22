<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time backfill of employee dates of birth from the HR sheet
     * (DOB.xlsx, 2026-05-19). Keyed by users.id (stable) because the sheet
     * uses formal names while users.name uses nicknames. Six sheet rows had
     * no Tessa account and are intentionally excluded (Anthony Muthu,
     * Hariharan, Bhoomika S, Bhuvan Prasad, Soundarya Balaraddi,
     * Keerti Kilabanoor). Net: 43 of 49 rows.
     */
    private const DOB = [
        1 => '2000-11-01',  // JP (Jaya Prasad)
        2 => '2000-10-24',  // Bala (BALAKRISHNAN)
        3 => '2000-09-24',  // Nandha (NANDHAKUMAR)
        4 => '1996-10-10',  // Ayush (Ayush Agrawal)
        5 => '2001-10-27',  // Sneha Sunoj
        11 => '2003-04-12', // Anirudh
        12 => '2000-03-06', // Tamil Arasan (Tamilarasan)
        13 => '2001-10-28', // Dhanush (Dhanushkumar)
        17 => '1996-02-28', // Anindita (Anindita Hazarika)
        18 => '2000-10-30', // Anaz (Anas Akbar)
        19 => '2004-09-07', // Sooraj
        20 => '2002-06-16', // Krishnan (Elaya Muthu Krishnan)
        21 => '2005-01-24', // Tiyasa (Tiyasa Dutta)
        23 => '2006-03-01', // Laxmi (Laxmi Bokaria)
        25 => '2003-11-16', // Deeksha
        26 => '2001-07-01', // Gousia (GOUSIA BEGUM)
        27 => '2002-04-26', // Ranjini (RANJINI)
        28 => '1996-07-18', // Reshma (RESHMA)
        32 => '1998-05-18', // Shoyab (Momin Shaik Shoyab Basha)
        34 => '2004-04-09', // Yuvanesh (YUVANESH)
        35 => '2003-10-28', // Rishabh (RISHABH KUMAR)
        36 => '1997-11-06', // Raksha (Raksha Agrawal)
        37 => '2004-11-05', // Perumal (Siva Perumal)
        38 => '2004-08-31', // Maari (Marimutthu)
        39 => '1995-01-13', // Barkha Agarwal (Barkha Agrawal)
        40 => '2006-07-16', // Disha (KP Disha Sree)
        41 => '2002-05-08', // Fida (Fida Taneem)
        42 => '2004-04-24', // Sneha Prathap (Sneha P Pratap)
        44 => '2002-07-29', // Saran (Sarankeerthi)
        45 => '1999-05-23', // Meghana (Meghana Chandra)
        46 => '2005-03-30', // Irisha
        47 => '2003-10-27', // Nisha
        48 => '1996-06-16', // Anjali Bhatt
        49 => '1998-12-27', // Haripriya
        50 => '2003-08-19', // Suwetha S
        51 => '2004-11-01', // Kishore Prabakaran
        52 => '2002-03-06', // Fathima K P (Fathima Koorimannil Pattiyil)
        53 => '2004-04-03', // Iksha H S
        54 => '2004-03-02', // Karuna Behal (Karuna behal)
        55 => '2005-04-06', // Swapna M
        56 => '2000-12-17', // Y Nehal (Nehal Y)
        57 => '2006-08-29', // Gargi Bisht (Gargi bisht)
        58 => '2002-09-15', // Sivaranjani N
    ];

    public function up(): void
    {
        // Only set where still null so a later self-edit on My Profile is
        // never clobbered if this migration is ever re-run.
        foreach (self::DOB as $id => $date) {
            DB::table('users')
                ->where('id', $id)
                ->whereNull('date_of_birth')
                ->update(['date_of_birth' => $date]);
        }
    }

    public function down(): void
    {
        // No-op: the schema migration's down() drops the column entirely.
    }
};

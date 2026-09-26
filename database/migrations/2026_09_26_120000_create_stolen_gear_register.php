<?php

use App\Services\Safety\SerialNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stolen gear register, and serial numbers that can be matched against it.
 *
 * Instruments are stolen from cars, vans and rehearsal rooms all the time,
 * and resurface on marketplaces within weeks. A serial number is the one
 * thing that ties a listed guitar to a stolen one, so the register is keyed
 * on it, and so is the listing side: a passport's serial gets a normalised
 * twin that the two are compared on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrument_passports', function (Blueprint $table) {
            // "MX21 012345", "mx21-012345" and "MX21012345" are one serial
            // written three ways. Matching on the raw text would let the
            // seller of a stolen guitar dodge the register with a space.
            $table->string('serial_normalized', 100)->nullable()->after('serial_number')->index();
        });

        Schema::create('stolen_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('serial_number', 100);
            $table->string('serial_normalized', 100)->index();

            // What it is, so a match can be sanity checked by a person: a
            // serial shared by a Fender and a Yamaha is a coincidence, not a
            // stolen guitar.
            $table->string('brand', 100)->nullable();
            $table->string('description', 500);

            $table->date('stolen_on')->nullable();
            $table->string('location', 120)->nullable();

            // Never shown publicly. It is what tells a moderator the theft
            // was reported to the police, and what the police will ask for.
            $table->string('police_reference', 60)->nullable();

            $table->enum('status', ['ACTIVE', 'RECOVERED'])->default('ACTIVE');
            $table->timestamp('recovered_at')->nullable();

            $table->timestamps();
        });

        // Passports that already carry a serial get their twin now, or they
        // would be invisible to the register until someone edited them.
        DB::table('instrument_passports')->whereNotNull('serial_number')->orderBy('id')
            ->each(function ($row) {
                DB::table('instrument_passports')->where('id', $row->id)
                    ->update(['serial_normalized' => SerialNumber::normalize($row->serial_number)]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('stolen_reports');

        Schema::table('instrument_passports', function (Blueprint $table) {
            $table->dropColumn('serial_normalized');
        });
    }
};

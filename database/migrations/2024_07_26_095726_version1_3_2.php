<?php

use App\Models\Feature;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        Feature::updateOrCreate(['id' => 18], ['status' => 1]);

        Schema::table('schools', static function (Blueprint $table) {
            $table->string('senderid')->nullable(true)->after('status');
            // $table->string('domain')->nullable(true)->after('senderid');
        });


        Schema::create('sms_setting', static function (Blueprint $table) {
            $table->id();
            $table->string('mark', 60);
            $table->string('description', 255);
            $table->string('status')->comment('0: disable 1:enable');
            $table->string('sendmark')->comment('admin|schoolaccount|teacher|student|parent');
            $table->string('type', 20)->nullable(true);
            $table->string('msg0', 500)->nullable(true);
            $table->string('msg1', 500)->nullable(true);
            $table->string('msg2', 500)->nullable(true);
            $table->string('msg3', 500)->nullable(true);
            $table->string('msg4', 500)->nullable(true);
            $table->timestamps();
        });

        Cache::flush();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};

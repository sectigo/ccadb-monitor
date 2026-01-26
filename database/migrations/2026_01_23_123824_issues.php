<?php

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
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->integer('ca_certificate_record_id')->index();
            $table->string('url', 500);
            $table->mediumText('error');
            $table->dateTimeTz('first_detected_at');
            $table->dateTimeTz('last_detected_at');
            $table->boolean('is_resolved')->default(false);
            $table->string('issue_type', 50);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

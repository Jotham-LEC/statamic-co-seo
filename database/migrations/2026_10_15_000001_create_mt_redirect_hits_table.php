<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hit counts of redirect rules kept in files (the file driver), by the rule's id. They are kept in the
 * database because every redirected visit changes them, and the files are committed with the site. This
 * only creates the table: `php please mt:export-redirects` copies the counts across.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mt_redirect_hits', function (Blueprint $table) {
            $table->id();
            $table->string('redirect_id', 36)->unique();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mt_redirect_hits');
    }
};

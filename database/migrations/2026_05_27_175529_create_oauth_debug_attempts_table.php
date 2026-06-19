<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('oauth_debug_attempts')) {
            return;
        }

        Schema::create('oauth_debug_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();
            $table->foreignId('owner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('started')->index();
            $table->json('callback_query')->nullable();
            $table->json('token_summary')->nullable();
            $table->json('permissions_response')->nullable();
            $table->json('pages_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_debug_attempts');
    }
};

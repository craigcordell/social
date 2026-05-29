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
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('owner_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('social_post_targets', function (Blueprint $table): void {
            $table->text('provider_post_url')->nullable()->after('provider_media_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table): void {
            $table->dropColumn('provider_post_url');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};

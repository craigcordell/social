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
        Schema::create('meta_ad_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained()->cascadeOnDelete();
            $table->string('ad_account_id');
            $table->string('type')->index();
            $table->string('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('status')->default('pending')->index();
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->string('meta_campaign_id')->nullable()->index();
            $table->string('meta_ad_set_id')->nullable();
            $table->string('meta_creative_id')->nullable();
            $table->string('meta_ad_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['owner_id', 'ad_account_id', 'idempotency_key'],
                'meta_ad_operations_owner_account_key_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_ad_operations');
    }
};

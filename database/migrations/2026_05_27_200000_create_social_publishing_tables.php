<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('internal');
            $table->string('external_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'external_id']);
        });

        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_account_id');
            $table->string('provider_account_type')->default('page');
            $table->string('display_name');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'provider', 'status']);
            $table->unique(['provider', 'provider_account_id']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->string('idempotency_key')->nullable();
            $table->text('caption');
            $table->text('image_url');
            $table->text('link_url')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->string('status')->default('queued')->index();
            $table->timestamps();

            $table->unique(['owner_id', 'idempotency_key']);
        });

        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('publish_status')->default('queued')->index();
            $table->string('delete_status')->nullable()->index();
            $table->string('provider_post_id')->nullable()->index();
            $table->string('provider_media_id')->nullable();
            $table->json('provider_response')->nullable();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->unsignedInteger('delete_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['social_post_id', 'connected_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('connected_accounts');
        Schema::dropIfExists('owners');
    }
};

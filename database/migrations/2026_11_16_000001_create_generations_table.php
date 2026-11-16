<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our own id. It is sent to Pixelforge as the Idempotency-Key and in
            // `metadata`, so a retried request never creates a second generation
            // and a webhook can be matched even before we know the provider id.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Pixelforge's id (gen_...). Null until the create request has returned
            // or a webhook has told us about it.
            $table->string('provider_id')->nullable()->unique();

            // queued | processing | succeeded | failed
            $table->string('status')->default('queued');

            // The request.
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images')->default('[]');

            // What we told the user up front, in nanodollars (user charge).
            $table->unsignedBigInteger('estimated_charge_nano');
            $table->unsignedBigInteger('estimated_max_charge_nano');

            // From Pixelforge. `rates` is fixed when the generation is created and
            // is what the final cost is calculated from.
            $table->jsonb('rates')->nullable();
            $table->jsonb('usage')->nullable();
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Final money, in nanodollars. Written exactly once, when a generation
            // succeeds (see settled_at). Failed generations never get a value.
            $table->unsignedBigInteger('provider_cost_nano')->nullable();
            $table->unsignedBigInteger('charge_nano')->nullable();
            $table->timestampTz('settled_at')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
            // Reports group by the UTC day a generation completed.
            $table->index('completed_at');
        });

        // A generation is either unsettled or fully settled, never half-way.
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_settled_consistent CHECK (
            (settled_at IS NULL AND provider_cost_nano IS NULL AND charge_nano IS NULL)
            OR (settled_at IS NOT NULL AND provider_cost_nano IS NOT NULL AND charge_nano IS NOT NULL)
        )');

        // Only a succeeded generation can carry a price.
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_only_succeeded_settled CHECK (
            settled_at IS NULL OR status = 'succeeded'
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};

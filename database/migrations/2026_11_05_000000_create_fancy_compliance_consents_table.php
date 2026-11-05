<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The consent ledger. Append-only: one row per decision, withdrawal or login
 * link, and the current state is the latest row per subject (highest id).
 *
 * Deliberately absent: IP address, user agent, and any other request
 * fingerprint. A guest's subject_id is the SHA-256 of a random cookie token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('fancy-compliance.table', 'fancy_compliance_consents'), function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 8);
            $table->string('subject_id', 64);
            $table->string('action', 16);
            $table->json('granted');
            $table->json('refused');
            $table->string('policy_version', 64);
            $table->string('source', 16);
            $table->string('linked_from', 64)->nullable();
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id', 'id'], 'fancy_compliance_subject_latest');
            $table->index('created_at', 'fancy_compliance_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('fancy-compliance.table', 'fancy_compliance_consents'));
    }
};

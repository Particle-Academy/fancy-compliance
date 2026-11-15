<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Data rights (CC-fancy-0038 phases 3 and 4).
 *
 * - fancy_compliance_exports: export requests. The subject is a keyed hash;
 *   the subject itself is kept encrypted only until the export is built.
 * - fancy_compliance_erasure_requests: erasure requests and their state. The
 *   subject is kept encrypted only while the request may still run.
 * - fancy_compliance_erasures: the erasure audit trail. Append-only, and it
 *   names the subject only by its HMAC pseudonym (FANCY_COMPLIANCE_ERASURE_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fancy_compliance_exports', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('subject_hash', 64);
            $table->text('subject')->nullable();
            $table->string('format', 8);
            $table->string('status', 16);
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error', 32)->nullable();
            $table->string('failed_registrant', 64)->nullable();
            $table->string('download_token_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->index(['subject_hash', 'created_at'], 'fancy_compliance_exports_subject');
            $table->index(['status', 'expires_at'], 'fancy_compliance_exports_expiry');
        });

        Schema::create('fancy_compliance_erasure_requests', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('pseudonym', 64);
            $table->text('subject')->nullable();
            $table->string('status', 24);
            $table->json('results');
            $table->timestamp('requested_at');
            $table->timestamp('due_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->index(['pseudonym', 'requested_at'], 'fancy_compliance_erasure_requests_subject');
            $table->index(['status', 'due_at'], 'fancy_compliance_erasure_requests_due');
        });

        Schema::create('fancy_compliance_erasures', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 32);
            $table->string('pseudonym', 64);
            $table->string('event', 32);
            $table->string('registrant', 64)->nullable();
            $table->string('registrant_version', 64)->nullable();
            $table->json('counts')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at');

            $table->index('request_id', 'fancy_compliance_erasures_request');
            $table->index('pseudonym', 'fancy_compliance_erasures_pseudonym');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fancy_compliance_erasures');
        Schema::dropIfExists('fancy_compliance_erasure_requests');
        Schema::dropIfExists('fancy_compliance_exports');
    }
};

<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

/**
 * A package's (or the host's) answer for the personal data it holds: what to
 * export, and how to erase it. Registered with `DataRights::register()`.
 *
 * A first-party package registers its own only when fancy-compliance is
 * installed (`class_exists(DataRegistrant::class)`); fancy-compliance never
 * requires it.
 *
 * Read the identifiers from `SubjectKeys`; never derive one from another.
 */
interface DataRegistrant
{
    /** Lowercase, dotted, unique: `<package>.<what>`. The `fancy-compliance.` prefix is this package's. */
    public function key(): string;

    /** The registrant's version, recorded in the erasure audit trail. */
    public function version(): string;

    /**
     * Write the subject's own rows. Never secrets (key material, token or
     * OTP hashes) and never other people's personal data.
     */
    public function export(SubjectKeys $keys, ExportWriter $writer): void;

    /**
     * Delete, anonymise or retain-with-reason, and say which on the plan with
     * the row counts. Runs inside a transaction on the default connection; a
     * throw rolls it back and marks this registrant failed. Must be
     * idempotent: a resumed request may call it again.
     */
    public function erase(SubjectKeys $keys, ErasurePlan $plan): void;

    /** How many rows it holds for the subject, for a dry run. Must not change anything. */
    public function holds(SubjectKeys $keys): int;
}

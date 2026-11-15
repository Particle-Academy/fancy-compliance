<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights\Registrants;

use FancyCompliance\ConsentHistoryEntry;
use FancyCompliance\DataRights\DataRegistrant;
use FancyCompliance\DataRights\DataRightsConfig;
use FancyCompliance\DataRights\ErasurePlan;
use FancyCompliance\DataRights\ExportWriter;
use FancyCompliance\DataRights\SubjectKeys;
use FancyCompliance\Models\ConsentRecord;
use FancyCompliance\Subject;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;

/**
 * The consent ledger, `fancy-compliance.consents`. Reads the subject's `user`
 * rows under {@see SubjectKeys::CONSENT_USER}, plus the guest rows a login
 * linked to them.
 *
 * - **Export:** every row, newest first, in the history entry shape: no
 *   subject key and no guest hash.
 * - **Erasure** (`data_rights.erasure.consents`): by default the rows are
 *   KEPT as evidence of consent and re-keyed to the erasure pseudonym
 *   (`subject_type` `erased`); the guest hashes that linked them are replaced
 *   too, so a guest cookie no longer leads to the person. `delete` removes
 *   them instead. Both go through the query builder, which bypasses the
 *   model's append-only guard on purpose: erasure and retention are the only
 *   sanctioned writes.
 */
final class ConsentLedgerRegistrant implements DataRegistrant
{
    public const KEY = 'fancy-compliance.consents';

    public const ERASED = 'erased';

    public const RETAIN_REASON = 'Evidence of consent: the rows are kept, with the person replaced by a keyed-hash pseudonym.';

    public function __construct(private readonly Container $app) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): string
    {
        return PackageVersion::get();
    }

    public function export(SubjectKeys $keys, ExportWriter $writer): void
    {
        $rows = [];
        $user = $this->user($keys);
        if ($user !== null) {
            $rows = array_map(
                static fn (ConsentRecord $row): array => ConsentHistoryEntry::fromRecord($row)->toArray(),
                ConsentRecord::query()->where('subject_type', Subject::USER)->where('subject_id', $user)->orderByDesc('id')->get()->all(),
            );
        }
        $writer->json('consents', $rows);
    }

    public function erase(SubjectKeys $keys, ErasurePlan $plan): void
    {
        $mode = (new DataRightsConfig($this->app->make('config')))->consentsMode();
        $user = $this->user($keys);
        if ($user === null) {
            return;
        }
        $guests = $this->linkedGuests($user);
        $table = $this->table();

        if ($mode === DataRightsConfig::CONSENTS_DELETE) {
            $rows = $this->userRows($user)->delete() + $this->guestRows($guests)->delete();
            $plan->delete($table, $rows);

            return;
        }

        $pseudonym = $plan->pseudonym();
        $rekey = ['subject_type' => self::ERASED, 'subject_id' => $pseudonym];
        $rows = $this->guestRows($guests)->update($rekey) + $this->userRows($user)->update($rekey);
        // A linked row keeps saying it was linked, but no longer says from which cookie.
        $this->db()->table($table)->where('subject_type', self::ERASED)->where('subject_id', $pseudonym)
            ->whereNotNull('linked_from')->update(['linked_from' => $pseudonym]);
        $plan->retain($table, $rows, self::RETAIN_REASON, rekeyed: true);
    }

    public function holds(SubjectKeys $keys): int
    {
        $user = $this->user($keys);

        return $user === null ? 0 : $this->userRows($user)->count() + $this->guestRows($this->linkedGuests($user))->count();
    }

    private function user(SubjectKeys $keys): ?string
    {
        $id = $keys->get(SubjectKeys::CONSENT_USER);

        return is_string($id) || is_int($id) ? (string) $id : null;
    }

    /** @return list<string> */
    private function linkedGuests(string $user): array
    {
        return $this->userRows($user)->whereNotNull('linked_from')->distinct()->pluck('linked_from')->map(fn ($v) => (string) $v)->all();
    }

    private function userRows(string $user): Builder
    {
        return $this->db()->table($this->table())->where('subject_type', Subject::USER)->where('subject_id', $user);
    }

    /** @param list<string> $hashes */
    private function guestRows(array $hashes): Builder
    {
        return $this->db()->table($this->table())->where('subject_type', Subject::GUEST)->whereIn('subject_id', $hashes === [] ? [''] : $hashes);
    }

    private function table(): string
    {
        return (string) $this->app->make('config')->get('fancy-compliance.table', 'fancy_compliance_consents');
    }

    private function db(): \Illuminate\Database\ConnectionInterface
    {
        return $this->app->make('db')->connection();
    }
}

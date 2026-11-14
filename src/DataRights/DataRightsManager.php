<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use Closure;
use FancyCompliance\ConsentHistoryPage;
use FancyCompliance\ConsentManager;
use FancyCompliance\Subject;
use Illuminate\Http\Request;
use UnexpectedValueException;

/**
 * The host's answers to three questions, behind the `DataRights` facade:
 *
 * - **who is asking** (`subjects`): a request to a {@see DataSubject}, or null;
 * - **may they** (`authorize`): actor, target and right (`history`, and later
 *   `export`, `erase`) to a yes or no. The default allows only a subject acting
 *   for itself, so a staff path exists only when the host writes one;
 * - **where is their data** (`resolveFor`): a subject to its {@see SubjectKeys}.
 *
 * With no subject resolver, data-rights routes answer 404: fail closed.
 */
final class DataRightsManager
{
    public const RIGHT_HISTORY = 'history';

    private ?Closure $subjects = null;

    private ?Closure $authorizer = null;

    private ?Closure $keys = null;

    public function __construct(private readonly ConsentManager $consent) {}

    /** @param callable(Request): ?DataSubject $resolver */
    public function subjects(callable $resolver): void
    {
        $this->subjects = Closure::fromCallable($resolver);
    }

    /** @param callable(DataSubject, DataSubject, string): bool $authorizer */
    public function authorize(callable $authorizer): void
    {
        $this->authorizer = Closure::fromCallable($authorizer);
    }

    /** @param callable(DataSubject): SubjectKeys $resolver */
    public function resolveFor(callable $resolver): void
    {
        $this->keys = Closure::fromCallable($resolver);
    }

    public function configured(): bool
    {
        return $this->subjects !== null;
    }

    public function subjectFor(Request $request): ?DataSubject
    {
        if ($this->subjects === null) {
            return null;
        }
        $subject = ($this->subjects)($request);
        if ($subject !== null && ! $subject instanceof DataSubject) {
            throw new UnexpectedValueException('The DataRights subject resolver must return a DataSubject or null.');
        }

        return $subject;
    }

    public function allows(DataSubject $actor, DataSubject $target, string $right): bool
    {
        if ($this->authorizer === null) {
            return $actor->equals($target);
        }

        return ($this->authorizer)($actor, $target, $right) === true;
    }

    public function keysFor(DataSubject $subject): SubjectKeys
    {
        if ($this->keys === null) {
            return SubjectKeys::defaultFor($subject);
        }
        $keys = ($this->keys)($subject);
        if (! $keys instanceof SubjectKeys) {
            throw new UnexpectedValueException('The DataRights key resolver must return SubjectKeys.');
        }

        return $keys;
    }

    /**
     * The target's consent ledger rows, newest first. Authorisation is the
     * caller's: this reads, it does not decide who may.
     */
    public function consentHistory(DataSubject $target, int $limit = 50, ?int $before = null): ConsentHistoryPage
    {
        $id = $this->keysFor($target)->get(SubjectKeys::CONSENT_USER);
        if (! is_string($id) && ! is_int($id)) {
            return new ConsentHistoryPage([], null);
        }

        return $this->consent->historyFor(Subject::user($id), $limit, $before);
    }
}

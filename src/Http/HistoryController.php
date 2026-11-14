<?php

declare(strict_types=1);

namespace FancyCompliance\Http;

use FancyCompliance\ConsentHistoryPage;
use FancyCompliance\ConsentManager;
use FancyCompliance\DataRights\DataRightsManager;
use FancyCompliance\DataRights\DataSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET {prefix}/history`: a subject's consent history, newest first.
 *
 * - No host subject resolver: 404, so nothing is served until the host says
 *   who is asking (fail closed).
 * - A resolved subject reads its own history, or names a target with
 *   `subject_type` + `subject_id`; either way the host authoriser is asked
 *   with the right `history`. The default allows only actor equals target.
 * - With no resolved subject, a guest reads the rows of its own cookie and
 *   cannot name a target (`data_rights.history.guests`, on by default).
 *
 * Query input is bounded: `limit` and `before` positive integers, a subject
 * type of the {@see DataSubject} shape, an id of at most 191 characters.
 * Responses are `no-store`: they are personal data.
 */
final class HistoryController
{
    public function __construct(private readonly ConsentManager $consent, private readonly DataRightsManager $rights) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->rights->configured()) {
            return $this->error('data_rights_not_configured', 404);
        }

        $limit = $this->positiveInt($request->query('limit'));
        $before = $this->positiveInt($request->query('before'));
        $type = $request->query('subject_type');
        $id = $request->query('subject_id');
        if ($limit === false || $before === false || ! $this->validTarget($type, $id)) {
            return $this->error('invalid_input', 422);
        }
        $named = $type !== null;

        $actor = $this->rights->subjectFor($request);
        if ($actor === null) {
            $guest = $this->config('guests', true) ? $this->consent->guestSubject($request) : null;
            if ($guest === null) {
                return $this->error('unauthenticated', 401);
            }
            if ($named) {
                return $this->error('forbidden', 403);
            }

            return $this->respond($this->consent->historyFor($guest, $limit, $before));
        }

        $target = $named ? DataSubject::of($type, $id) : $actor;
        if (! $this->rights->allows($actor, $target, DataRightsManager::RIGHT_HISTORY)) {
            return $this->error('forbidden', 403);
        }

        return $this->respond($this->rights->consentHistory($target, $limit ?? (int) $this->config('limit', 50), $before));
    }

    /** null when absent, false when present but not a positive integer. */
    private function positiveInt(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || strlen($value) > 18 || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return false;
        }

        return (int) $value;
    }

    private function validTarget(mixed $type, mixed $id): bool
    {
        if ($type === null && $id === null) {
            return true;
        }

        return is_string($type) && is_string($id)
            && preg_match(DataSubject::TYPE_PATTERN, $type) === 1
            && $id !== '' && strlen($id) <= DataSubject::MAX_ID_LENGTH;
    }

    private function respond(ConsentHistoryPage $page): JsonResponse
    {
        return $this->noStore(new JsonResponse($page->toArray()));
    }

    private function error(string $code, int $status): JsonResponse
    {
        return $this->noStore(new JsonResponse(['error' => $code], $status));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function config(string $key, mixed $default): mixed
    {
        return config('fancy-compliance.data_rights.history.'.$key, $default);
    }
}

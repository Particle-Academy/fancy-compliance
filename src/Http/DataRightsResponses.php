<?php

declare(strict_types=1);

namespace FancyCompliance\Http;

use FancyCompliance\DataRights\DataRightsManager;
use FancyCompliance\DataRights\DataRightsRefused;
use FancyCompliance\DataRights\DataSubject;
use FancyCompliance\DataRights\InvalidDataRightsConfiguration;
use FancyCompliance\Support\Reporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What the export and erasure controllers share: who is asking and for whom,
 * in the protocol's order (configured 404, subject 401, input 422, authoriser
 * 403, then re-authentication 423 for a request), and the error bodies.
 */
trait DataRightsResponses
{
    abstract protected function rights(): DataRightsManager;

    /**
     * @param  callable(DataSubject $actor, DataSubject $target): Response  $then
     */
    protected function guarded(Request $request, string $right, bool $needsReauth, callable $then): Response
    {
        try {
            if (! $this->rights()->configured()) {
                return $this->error('data_rights_not_configured', 404);
            }
            $actor = $this->rights()->subjectFor($request);
            if ($actor === null) {
                return $this->error('unauthenticated', 401);
            }
            $source = $request->isMethod('GET') ? $request->query() : $request->input();
            $type = is_array($source) ? ($source['subject_type'] ?? null) : null;
            $id = is_array($source) ? ($source['subject_id'] ?? null) : null;
            if (! $this->validTarget($type, $id)) {
                return $this->error('invalid_input', 422);
            }
            $target = $type === null ? $actor : DataSubject::of($type, $id);
            if (! $this->rights()->allows($actor, $target, $right)) {
                return $this->error('forbidden', 403);
            }
            if ($needsReauth && $this->rights()->config()->reauthRequired() && ! $this->rights()->confirmsRecent($request)) {
                return $this->error('reauth_required', 423);
            }

            return $then($actor, $target);
        } catch (DataRightsRefused $e) {
            return $this->noStore(new JsonResponse($e->body(), $e->status));
        } catch (InvalidDataRightsConfiguration $e) {
            Reporter::report(app(), $e);

            return $this->error('misconfigured', 500);
        }
    }

    /** @param array<string, mixed> $body */
    protected function json(array $body, int $status = 200): JsonResponse
    {
        return $this->noStore(new JsonResponse($body, $status, [], JSON_UNESCAPED_SLASHES));
    }

    protected function error(string $code, int $status): JsonResponse
    {
        return $this->json(['error' => $code], $status);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function validTarget(mixed $type, mixed $id): bool
    {
        if ($type === null && $id === null) {
            return true;
        }

        return is_string($type) && (is_string($id) || is_int($id))
            && preg_match(DataSubject::TYPE_PATTERN, $type) === 1
            && (string) $id !== '' && strlen((string) $id) <= DataSubject::MAX_ID_LENGTH;
    }
}

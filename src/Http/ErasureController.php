<?php

declare(strict_types=1);

namespace FancyCompliance\Http;

use FancyCompliance\DataRights\DataRightsManager;
use FancyCompliance\DataRights\DataSubject;
use FancyCompliance\DataRights\ErasureService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `{prefix}/erasure`: request, read and cancel a subject's staged deletion
 * (docs/data-rights-protocol.md). Requesting needs a recent
 * re-authentication unless the host turned that off; cancelling never does,
 * because it touches no data. An empty POST body is fine.
 */
final class ErasureController
{
    use DataRightsResponses;

    public function __construct(private readonly DataRightsManager $manager, private readonly ErasureService $erasure) {}

    public function show(Request $request): Response
    {
        return $this->guarded($request, DataRightsManager::RIGHT_ERASE, false, function (DataSubject $actor, DataSubject $target): Response {
            $latest = $this->erasure->latest($target);

            return $this->json([
                'erasure' => $latest === null ? null : $this->erasure->view($latest),
                'graceDays' => $this->manager->config()->graceDays(),
            ]);
        });
    }

    public function store(Request $request): Response
    {
        return $this->guarded($request, DataRightsManager::RIGHT_ERASE, true,
            fn (DataSubject $actor, DataSubject $target): Response => $this->json(['erasure' => $this->erasure->view($this->erasure->request($target))], 202));
    }

    public function view(Request $request, string $erasure): Response
    {
        return $this->guarded($request, DataRightsManager::RIGHT_ERASE, false, function (DataSubject $actor, DataSubject $target) use ($erasure): Response {
            $row = $this->erasure->find($target, $erasure);

            return $row === null ? $this->error('not_found', 404) : $this->json(['erasure' => $this->erasure->view($row)]);
        });
    }

    public function cancel(Request $request, string $erasure): Response
    {
        return $this->guarded($request, DataRightsManager::RIGHT_ERASE, false,
            fn (DataSubject $actor, DataSubject $target): Response => $this->json(['erasure' => $this->erasure->view($this->erasure->cancel($target, $erasure))]));
    }

    protected function rights(): DataRightsManager
    {
        return $this->manager;
    }
}

<?php

declare(strict_types=1);

namespace FancyCompliance\Http;

use FancyCompliance\DataRights\DataRightsManager;
use FancyCompliance\DataRights\DataSubject;
use FancyCompliance\DataRights\ExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `{prefix}/exports`: list, request, read and download a subject's exports
 * (docs/data-rights-protocol.md). Every route needs an authenticated subject;
 * requesting one also needs a recent re-authentication unless the host turned
 * that off. A download needs the subject AND the single-use token.
 */
final class ExportController
{
    use DataRightsResponses;

    public function __construct(private readonly DataRightsManager $manager, private readonly ExportService $exports) {}

    public function index(Request $request): Response
    {
        return $this->guarded($request, DataRightsManager::RIGHT_EXPORT, false, fn (DataSubject $actor, DataSubject $target) => $this->json([
            'exports' => array_map(fn ($e) => $this->exports->view($e, true), $this->exports->list($target)),
            'formats' => $this->manager->config()->exportFormats(),
            'limits' => $this->exports->limits($target),
        ]));
    }

    public function store(Request $request): Response
    {
        return $this->guarded($request, DataRightsManager::RIGHT_EXPORT, true, function (DataSubject $actor, DataSubject $target) use ($request): Response {
            $format = $request->input('format', 'json');
            if (! is_string($format)) {
                return $this->error('invalid_input', 422);
            }

            return $this->json(['export' => $this->exports->view($this->exports->request($target, $format), true)], 202);
        });
    }

    public function show(Request $request, string $export): Response
    {
        return $this->guarded($request, DataRightsManager::RIGHT_EXPORT, false, function (DataSubject $actor, DataSubject $target) use ($export): Response {
            $row = $this->exports->find($target, $export);

            return $row === null ? $this->error('not_found', 404) : $this->json(['export' => $this->exports->view($row, true)]);
        });
    }

    public function download(Request $request, string $export): Response
    {
        return $this->guarded($request, DataRightsManager::RIGHT_EXPORT, false,
            fn (DataSubject $actor, DataSubject $target): Response => $this->exports->download($actor, $target, $export, $request->query('token')));
    }

    protected function rights(): DataRightsManager
    {
        return $this->manager;
    }
}

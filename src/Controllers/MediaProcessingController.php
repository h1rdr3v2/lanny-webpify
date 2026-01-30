<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\MediaProcessingService;

class MediaProcessingController
{
    public function __construct(
        private MediaProcessingService $mediaProcessingService
    ) {}

    public function webpToMedia(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!isset($data['url'])) {
            $response->getBody()->write(json_encode(array('error' => 'url is required')));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $data = $this->mediaProcessingService->webpToMedia($data);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
    public function mediaToWebp(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!isset($data['url'])) {
            $response->getBody()->write(json_encode(array('error' => 'url is required')));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $data = $this->mediaProcessingService->mediaToWebp($data);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

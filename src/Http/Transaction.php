<?php

namespace Lemric\BatchRequest\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Transaction
{
    public Request $request;

    public JsonResponse $response;

    public string $content_id;

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function setRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getResponse(): JsonResponse
    {
        return $this->response;
    }

    public function setResponse(JsonResponse $response): self
    {
        $this->response = $response;

        return $this;
    }

    public function getContentId(): string
    {
        return $this->content_id;
    }

    public function setContentId(string $content_id): self
    {
        $this->content_id = $content_id;

        return $this;
    }
}

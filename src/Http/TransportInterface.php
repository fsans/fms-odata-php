<?php

declare(strict_types=1);

namespace FmsOData\Http;

interface TransportInterface
{
    public function send(Request $request): Response;
}

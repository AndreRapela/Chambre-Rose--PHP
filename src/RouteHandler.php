<?php

declare(strict_types=1);

namespace ChambreRose;

interface RouteHandler
{
    public function handle(Request $request): ?Response;
}

<?php

declare(strict_types=1);

namespace App\Protocols\Contracts;

interface ProtocolFormatter
{
    /**
     * Render the subscription for the user and servers supplied to the
     * constructor, in this client's native format.
     *
     * @return mixed  format-specific output (YAML string, JSON response, etc.)
     */
    public function handle();
}

<?php

declare(strict_types=1);

namespace App\Protocols;

use App\Support\SubscriptionRuleService;

class Happ extends General
{
    public $flag = 'happ';

    public function handle()
    {
        // A URI subscription carries nodes only. Happ imports its routing
        // profile from a separate link in the decoded subscription body.
        // Keep it in the body so large domain lists cannot exceed header limits.
        return base64_encode(SubscriptionRuleService::happRoutingLink() . "\n" . base64_decode(parent::handle()));
    }
}

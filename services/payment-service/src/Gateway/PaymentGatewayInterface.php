<?php

declare(strict_types=1);

namespace App\Gateway;

interface PaymentGatewayInterface
{
    public function process(GatewayPaymentRequest $request): GatewayPaymentResult;
}

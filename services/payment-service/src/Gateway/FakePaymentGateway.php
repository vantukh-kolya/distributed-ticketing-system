<?php

declare(strict_types=1);

namespace App\Gateway;

use Symfony\Component\Uid\Uuid;

final readonly class FakePaymentGateway implements PaymentGatewayInterface
{
    public function process(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        if ($request->paymentMethodToken === 'tok_decline') {
            return GatewayPaymentResult::failed('PAYMENT_DECLINED');
        }

        return GatewayPaymentResult::succeeded('pay_fake_' . Uuid::v7()->toRfc4122());
    }
}

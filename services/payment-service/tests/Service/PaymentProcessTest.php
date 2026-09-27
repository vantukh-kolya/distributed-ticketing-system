<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\ProcessPayment;

final class PaymentProcessTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testPaymentSucceeds(): void
    {
        /** @var PaymentService $paymentService */
        $paymentService = static::getContainer()->get(PaymentService::class);
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = static::getContainer()->get(PaymentRepository::class);

        $reservationId = Uuid::v7()->toRfc4122();
        $correlationId = Uuid::v7()->toRfc4122();

        $paymentService->process(new ProcessPayment(
            reservationId: $reservationId,
            showId: 'show-1',
            correlationId: $correlationId,
            amountMinor: 5000,
            currency: 'UAH',
            paymentMethodToken: 'tok_fake_visa',
        ));

        $payment = $paymentRepository->findByReservationId($reservationId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Paid, $payment->getStatus());
    }

    public function testPaymentCanFail(): void
    {
        /** @var PaymentService $paymentService */
        $paymentService = static::getContainer()->get(PaymentService::class);
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = static::getContainer()->get(PaymentRepository::class);

        $command = new ProcessPayment(
            reservationId: Uuid::v7()->toRfc4122(),
            showId: 'show-1',
            correlationId: Uuid::v7()->toRfc4122(),
            amountMinor: 5000,
            currency: 'UAH',
            paymentMethodToken: 'tok_decline',
        );

        $paymentService->process($command);

        $payment = $paymentRepository->findByReservationId($command->reservationId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Failed, $payment->getStatus());
    }

    public function testProcessIsIdempotent(): void
    {
        /** @var PaymentService $paymentService */
        $paymentService = static::getContainer()->get(PaymentService::class);
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = static::getContainer()->get(PaymentRepository::class);

        $command = new ProcessPayment(
            reservationId: Uuid::v7()->toRfc4122(),
            showId: 'show-1',
            correlationId: Uuid::v7()->toRfc4122(),
            amountMinor: 5000,
            currency: 'UAH',
            paymentMethodToken: 'tok_fake_visa',
        );

        $paymentService->process($command);
        $paymentService->process($command);

        $payment = $paymentRepository->findByReservationId($command->reservationId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Paid, $payment->getStatus());
    }
}

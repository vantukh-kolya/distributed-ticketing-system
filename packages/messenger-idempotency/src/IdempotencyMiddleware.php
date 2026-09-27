<?php

declare(strict_types=1);

namespace Ticketing\MessengerIdempotency;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private InboxMessageStore $inboxMessageStore,
        private string $consumerName,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();
        if ($messageId === null || $messageId === '') {
            throw new UnrecoverableMessageHandlingException(
                'Inbound message cannot be processed idempotently without a stable transport message id.',
            );
        }

        $message = $envelope->getMessage();
        if (!$this->inboxMessageStore->claim(
            $this->consumerName,
            (string) $messageId,
            $message::class,
        )) {
            return $envelope;
        }

        return $stack->next()->handle($envelope, $stack);
    }
}

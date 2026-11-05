<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Doctrine\DBAL\Connection;
use Mn\MultiDelivery\Service\MailBlock;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Framework\Adapter\Translation\AbstractTranslator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the delivery block to order confirmation mails of orders with more
 * than one delivery, right before Shopware renders the template. The texts
 * are the snippets mnMultiDelivery.mail.* of the mail's language and
 * sales channel, available to the block as "mnDeliveryTexts".
 *
 * @internal
 */
class OrderMailSubscriber implements EventSubscriberInterface
{
    private const MAIL_TYPE = 'order_confirmation_mail';

    private const TEXTS = [
        'heading' => 'Your order arrives in %count% deliveries:',
        'delivery' => 'Delivery',
        'shipsFrom' => 'Ships from',
        'deliverTo' => 'Deliver to',
        'expected' => 'Expected',
    ];

    /** @var array<string, bool> mail template id => is an order confirmation */
    private array $isConfirmation = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly MailBlock $mailBlock,
        private readonly AbstractTranslator $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [MailBeforeValidateEvent::class => 'onBeforeValidate'];
    }

    public function onBeforeValidate(MailBeforeValidateEvent $event): void
    {
        $templateData = $event->getTemplateData();
        $order = $templateData['order'] ?? null;
        $data = $event->getData();
        $templateId = $data['templateId'] ?? null;
        if (!$order instanceof OrderEntity || ($order->getDeliveries()?->count() ?? 0) < 2
            || !\is_string($templateId) || !Uuid::isValid($templateId) || !$this->isConfirmation($templateId)) {
            return;
        }

        foreach (['contentHtml' => true, 'contentPlain' => false] as $key => $html) {
            if (\is_string($data[$key] ?? null) && $data[$key] !== '') {
                $data[$key] = $this->mailBlock->inject($data[$key], $html);
            }
        }
        $event->setData($data);

        $templateData['mnDeliveryTexts'] = $this->texts($order, $event->getContext()->getLanguageId(), $event->getContext());
        $event->setTemplateData($templateData);
    }

    /**
     * @return array<string, string>
     */
    private function texts(OrderEntity $order, string $languageId, Context $context): array
    {
        $locale = (string) $this->connection->fetchOne(
            'SELECT loc.code FROM language l INNER JOIN locale loc ON loc.id = l.locale_id WHERE l.id = :id',
            ['id' => Uuid::fromHexToBytes($languageId)]
        );

        // Same approach as Shopware's document renderer: translate for the order's sales channel and language.
        $this->translator->injectSettings($order->getSalesChannelId(), $languageId, $locale ?: 'en-GB', $context);
        try {
            $texts = [];
            foreach (self::TEXTS as $key => $fallback) {
                $id = 'mnMultiDelivery.mail.' . $key;
                $text = $this->translator->trans($id);
                $texts[$key] = $text !== $id && $text !== '' ? $text : $fallback;
            }

            return $texts;
        } finally {
            $this->translator->resetInjection();
        }
    }

    private function isConfirmation(string $templateId): bool
    {
        return $this->isConfirmation[$templateId] ??= $this->connection->fetchOne(
            'SELECT mt.technical_name FROM mail_template m
             INNER JOIN mail_template_type mt ON mt.id = m.mail_template_type_id
             WHERE m.id = :id',
            ['id' => Uuid::fromHexToBytes($templateId)]
        ) === self::MAIL_TYPE;
    }
}

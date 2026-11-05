<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Service;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinderInterface;
use Twig\Environment;

/**
 * The "your order arrives in N deliveries" block of the order confirmation
 * mail. It is added to the mail template when the mail is sent (see
 * OrderMailSubscriber), so nothing is stored in the mail templates.
 *
 * The block comes from mn-multi-delivery/mail/order-deliveries.{html,txt}.twig,
 * resolved through Shopware's template inheritance, so plugins and themes
 * can ship their own version.
 *
 * Placement: at the marker {# mn-multi-delivery #} if the template has one,
 * otherwise before Shopware's default address section, otherwise at the end
 * of the mail. {# mn-multi-delivery:off #} in a template disables it.
 *
 * @internal
 */
class MailBlock
{
    public const START = '{# mn-multi-delivery:start #}';
    public const END = '{# mn-multi-delivery:end #}';
    public const MARKER = '{# mn-multi-delivery #}';
    public const OFF = '{# mn-multi-delivery:off #}';

    public const TEMPLATE_HTML = '@MnMultiDelivery/mn-multi-delivery/mail/order-deliveries.html.twig';
    public const TEMPLATE_PLAIN = '@MnMultiDelivery/mn-multi-delivery/mail/order-deliveries.txt.twig';

    private const ANCHOR = '{% set billingAddress = order.addresses.get(order.billingAddressId) %}';

    public function __construct(
        private readonly Environment $twig,
        private readonly TemplateFinderInterface $templateFinder,
    ) {
    }

    public function inject(string $template, bool $html): string
    {
        if (str_contains($template, self::OFF) || str_contains($template, self::START)) {
            return $template;
        }

        $block = $this->block($html);
        if (str_contains($template, self::MARKER)) {
            return str_replace(self::MARKER, $block, $template);
        }
        if (str_contains($template, self::ANCHOR)) {
            $position = (int) strpos($template, self::ANCHOR);

            return substr($template, 0, $position) . $block . "\n" . substr($template, $position);
        }
        if ($html && ($position = strripos($template, '</body>')) !== false) {
            return substr($template, 0, $position) . $block . "\n" . substr($template, $position);
        }

        return $template . "\n" . $block . "\n";
    }

    /** Twig source of the block, wrapped in the START/END markers. */
    public function block(bool $html): string
    {
        $name = $this->templateFinder->find($html ? self::TEMPLATE_HTML : self::TEMPLATE_PLAIN);
        $source = $this->twig->getLoader()->getSourceContext($name)->getCode();

        // The file's leading comment is dropped by Twig; the markers keep the block recognisable.
        return self::START . "\n" . $source . self::END;
    }
}

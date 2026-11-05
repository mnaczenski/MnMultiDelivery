<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Service\MailBlock;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinderInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

#[CoversClass(MailBlock::class)]
class MailBlockTest extends TestCase
{
    private const ANCHOR = '{% set billingAddress = order.addresses.get(order.billingAddressId) %}';

    private const TEXTS = ['heading' => 'Your order arrives in %count% deliveries:', 'delivery' => 'Delivery', 'shipsFrom' => 'Ships from', 'deliverTo' => 'Deliver to', 'expected' => 'Expected'];

    public function testInsertsBeforeTheDefaultAddressSection(): void
    {
        $mail = $this->mailBlock()->inject("Hello\n" . self::ANCHOR . "\nAddresses", true);

        static::assertLessThan(strpos($mail, self::ANCHOR), strpos($mail, MailBlock::START));
    }

    public function testTheMarkerDecidesThePosition(): void
    {
        $mail = $this->mailBlock()->inject('Top ' . MailBlock::MARKER . ' bottom ' . self::ANCHOR, true);

        static::assertStringStartsWith('Top ' . MailBlock::START, $mail);
        static::assertStringNotContainsString(MailBlock::MARKER, $mail);
    }

    public function testTheOffMarkerDisablesTheBlock(): void
    {
        $template = MailBlock::OFF . self::ANCHOR;

        static::assertSame($template, $this->mailBlock()->inject($template, true));
    }

    public function testCustomTemplatesGetTheBlockAtTheEnd(): void
    {
        $html = $this->mailBlock()->inject('<html><body><p>Thanks</p></body></html>', true);
        $plain = $this->mailBlock()->inject('Thanks', false);

        static::assertMatchesRegularExpression('/<p>Thanks<\/p>.*mn-multi-delivery:start.*<\/body>/s', $html);
        static::assertStringStartsWith("Thanks\n" . MailBlock::START, $plain);
    }

    public function testAStoredBlockIsNotAddedTwice(): void
    {
        $template = MailBlock::START . 'old' . MailBlock::END . self::ANCHOR;

        static::assertSame($template, $this->mailBlock()->inject($template, true));
    }

    public function testPlainTextKeepsOneLinePerDetail(): void
    {
        $text = $this->render(false, self::TEXTS);

        static::assertStringContainsString('Your order arrives in 2 deliveries:', $text);
        static::assertStringContainsString("\nDelivery 2 - Ships from Lager Süd\n", $text);
        static::assertSame(2, substr_count($text, "\nDeliver to: "));
        static::assertDoesNotMatchRegularExpression('/\d(Expected|Deliver to)/', $text);
        static::assertStringContainsString('1 x Lamp', $text);
    }

    public function testTextsComeFromTheSnippets(): void
    {
        $html = $this->render(true, ['heading' => 'Ihre Bestellung kommt in %count% Lieferungen:', 'delivery' => 'Lieferung', 'shipsFrom' => 'Versand aus', 'deliverTo' => 'Lieferung an', 'expected' => 'Voraussichtlich']);

        static::assertStringContainsString('Ihre Bestellung kommt in 2 Lieferungen:', $html);
        static::assertStringContainsString('Lieferung an: Ada Tester, Weg 1, 10115 München', $html);
    }

    public function testAProjectTemplateReplacesTheBlock(): void
    {
        $loader = new ChainLoader([
            new ArrayLoader(['@Project/mn-multi-delivery/mail/order-deliveries.html.twig' => 'PROJECT BLOCK']),
            $this->pluginLoader(),
        ]);
        $finder = $this->createMock(TemplateFinderInterface::class);
        $finder->method('find')->willReturnCallback(static fn (string $name) => str_replace('@MnMultiDelivery/', '@Project/', $name));

        $block = (new MailBlock(new Environment($loader), $finder))->block(true);

        static::assertSame(MailBlock::START . "\nPROJECT BLOCK" . MailBlock::END, $block);
    }

    /**
     * @param array<string, string> $texts
     */
    private function render(bool $html, array $texts): string
    {
        $twig = new Environment(new ArrayLoader(['mail' => $this->mailBlock()->block($html)]));
        $twig->addFilter(new TwigFilter('currency', static fn ($value) => number_format((float) $value, 2) . ' EUR'));

        return $twig->render('mail', ['currencyIsoCode' => 'EUR', 'mnDeliveryTexts' => $texts, 'order' => ['deliveries' => [
            $this->delivery('Berlin', null, 'Sofa'),
            $this->delivery('München', 'Lager Süd', 'Lamp'),
        ]]]);
    }

    private function mailBlock(): MailBlock
    {
        $finder = $this->createMock(TemplateFinderInterface::class);
        $finder->method('find')->willReturnArgument(0);

        return new MailBlock(new Environment($this->pluginLoader()), $finder);
    }

    private function pluginLoader(): FilesystemLoader
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3) . '/src/Resources/views', 'MnMultiDelivery');

        return $loader;
    }

    /**
     * @return array<string, mixed>
     */
    private function delivery(string $city, ?string $warehouse, string $item): array
    {
        return [
            'customFields' => ['mn_multi_delivery' => ['warehouseName' => $warehouse]],
            'shippingOrderAddress' => ['firstName' => 'Ada', 'lastName' => 'Tester', 'street' => 'Weg 1', 'zipcode' => '10115', 'city' => $city],
            'shippingDateEarliest' => new \DateTimeImmutable('2026-11-06'),
            'shippingDateLatest' => new \DateTimeImmutable('2026-11-08'),
            'shippingMethod' => ['translated' => ['name' => 'Standard']],
            'shippingCosts' => ['totalPrice' => 4.9],
            'positions' => [['quantity' => 1, 'orderLineItem' => ['label' => $item]]],
        ];
    }
}

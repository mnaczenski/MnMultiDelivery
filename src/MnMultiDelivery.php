<?php declare(strict_types=1);

namespace Mn\MultiDelivery;

use Mn\MultiDelivery\Service\CustomFieldInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class MnMultiDelivery extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->customFields()?->install($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->customFields()?->install($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if (!$uninstallContext->keepUserData()) {
            $this->customFields()?->uninstall($uninstallContext->getContext());
        }
    }

    private function customFields(): ?CustomFieldInstaller
    {
        $repository = $this->container?->get('custom_field_set.repository');

        return $repository instanceof EntityRepository ? new CustomFieldInstaller($repository) : null;
    }
}

import template from './mn-multi-delivery-country-mode-info.html.twig';
import './mn-multi-delivery-country-mode-info.scss';

/**
 * Explanation above the "Deliveries to other countries" setting
 * (config.xml). It only shows text and never changes its config value.
 */
Shopware.Component.register('mn-multi-delivery-country-mode-info', {
    template,

    inheritAttrs: false,
});

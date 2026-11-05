/**
 * Rule Builder condition "Number of deliveries" (Mn\MultiDelivery\Rule\DeliveryCountRule).
 */
Shopware.Application.addServiceProviderDecorator('ruleConditionDataProviderService', (ruleConditionService) => {
    ruleConditionService.addCondition('mnMultiDeliveryCount', {
        component: 'sw-condition-generic',
        label: 'mn-multi-delivery.ruleDeliveryCount',
        scopes: ['cart'],
        group: 'cart',
    });

    return ruleConditionService;
});

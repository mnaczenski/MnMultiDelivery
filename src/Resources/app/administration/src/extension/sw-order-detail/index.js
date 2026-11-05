import template from './sw-order-detail.html.twig';

const ROUTE = 'mn.multi.delivery.order.detail.deliveries';

/**
 * Shows the "Deliveries" tab on orders with more than one delivery,
 * in the classic tab bar and in the v6.8 tab list.
 */
Shopware.Component.override('sw-order-detail', {
    template,

    computed: {
        mnHasSplitDeliveries() {
            return (this.order?.deliveries?.length ?? 0) > 1;
        },

        orderDetailTabs() {
            const tabs = this.$super('orderDetailTabs');
            if (!this.mnHasSplitDeliveries) {
                return tabs;
            }

            const route = { name: ROUTE, params: { id: this.$route.params.id } };

            return [
                ...tabs,
                {
                    label: this.$t('mn-multi-delivery.tab'),
                    name: ROUTE,
                    onClick: () => {
                        void this.$router.push(route);
                    },
                },
            ];
        },
    },
});

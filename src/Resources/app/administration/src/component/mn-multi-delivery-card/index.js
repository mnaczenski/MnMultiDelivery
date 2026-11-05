import template from './mn-multi-delivery-card.html.twig';
import './mn-multi-delivery-card.scss';

const { Criteria } = Shopware.Data;

/**
 * Content of the order's "Deliveries" tab: one card per delivery with
 * address, items, state and tracking codes, and a delivery note per delivery.
 */
Shopware.Component.register('mn-multi-delivery-card', {
    template,

    // Used as a router view: ignore the attributes sw-order-detail passes to every tab.
    inheritAttrs: false,

    inject: ['repositoryFactory', 'stateMachineService', 'orderStateMachineService', 'documentService', 'acl'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        orderId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            deliveries: [],
            transitions: {},
            trackingInput: {},
            busy: {},
            isLoading: false,
        };
    },

    computed: {
        deliveryRepository() {
            return this.repositoryFactory.create('order_delivery');
        },

        canEdit() {
            return this.acl.can('order.editor');
        },
    },

    watch: {
        orderId: {
            immediate: true,
            handler() {
                this.load();
            },
        },
    },

    methods: {
        async load() {
            if (!this.orderId) {
                return;
            }
            this.isLoading = true;

            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('orderId', this.orderId));
            criteria.addAssociation('shippingOrderAddress.country');
            criteria.addAssociation('shippingMethod');
            criteria.addAssociation('stateMachineState');
            criteria.addAssociation('positions.orderLineItem');
            criteria.addSorting(Criteria.sort('shippingDateEarliest', 'ASC'));

            try {
                const result = await this.deliveryRepository.search(criteria, Shopware.Context.api);
                this.deliveries = Array.from(result);
                this.deliveries.forEach((delivery) => {
                    this.trackingInput[delivery.id] = (delivery.trackingCodes || []).join(', ');
                });
                await Promise.all(this.deliveries.map((delivery) => this.loadTransitions(delivery)));
            } finally {
                this.isLoading = false;
            }
        },

        async loadTransitions(delivery) {
            const response = await this.stateMachineService.getState('order_delivery', delivery.id);
            this.transitions[delivery.id] = (response.data.transitions || []).map((transition) => ({
                id: transition.actionName,
                name: transition.name,
            }));
        },

        splitInfo(delivery) {
            return delivery.customFields?.mn_multi_delivery ?? null;
        },

        title(delivery, index) {
            const title = `${this.$t('mn-multi-delivery.delivery')} ${index + 1}`;
            const warehouse = this.splitInfo(delivery)?.warehouseName;

            return warehouse ? `${title} · ${this.$t('mn-multi-delivery.shipsFrom')} ${warehouse}` : title;
        },

        formatDate(value) {
            return value ? Shopware.Utils.format.date(value, { hour: undefined, minute: undefined }) : '';
        },

        formatPrice(delivery) {
            const currency = Shopware.Store.get('swOrderDetail')?.order?.currency?.isoCode ?? 'EUR';
            return Shopware.Utils.format.currency(delivery.shippingCosts?.totalPrice ?? 0, currency);
        },

        async onTransition(delivery, actionName) {
            if (!actionName) {
                return;
            }
            this.busy[delivery.id] = true;
            try {
                await this.orderStateMachineService.transitionOrderDeliveryState(delivery.id, actionName, { sendMail: false });
                this.createNotificationSuccess({ message: this.$t('mn-multi-delivery.stateChanged') });
                await this.load();
            } catch (error) {
                this.createNotificationError({ message: error?.response?.data?.errors?.[0]?.detail ?? String(error) });
            } finally {
                this.busy[delivery.id] = false;
            }
        },

        async onSaveTracking(delivery) {
            this.busy[delivery.id] = true;
            delivery.trackingCodes = (this.trackingInput[delivery.id] || '')
                .split(',')
                .map((code) => code.trim())
                .filter(Boolean);
            try {
                await this.deliveryRepository.save(delivery, Shopware.Context.api);
                this.createNotificationSuccess({ message: this.$t('mn-multi-delivery.trackingSaved') });
                await this.load();
            } catch (error) {
                this.createNotificationError({ message: String(error) });
            } finally {
                this.busy[delivery.id] = false;
            }
        },

        async onCreateDeliveryNote(delivery) {
            this.busy[delivery.id] = true;
            try {
                await this.documentService.createDocument(this.orderId, 'delivery_note', {
                    documentDate: new Date().toISOString(),
                    mnOrderDeliveryId: delivery.id,
                });
                this.createNotificationSuccess({ message: this.$t('mn-multi-delivery.noteCreated') });
            } catch (error) {
                this.createNotificationError({ message: String(error) });
            } finally {
                this.busy[delivery.id] = false;
            }
        },
    },
});

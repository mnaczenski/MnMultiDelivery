/**
 * Adds the "Deliveries" tab route to the order detail page
 * (same approach as SwagCommercial's return management tab).
 */
Shopware.Module.register('mn-multi-delivery', {
    type: 'plugin',
    routes: {},
    name: '',
    title: '',
    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.order.detail') {
            currentRoute.children.push({
                component: 'mn-multi-delivery-card',
                name: 'mn.multi.delivery.order.detail.deliveries',
                isChildren: true,
                path: '/sw/order/detail/:id/deliveries',
                meta: {
                    parentPath: 'sw.order.index',
                    privilege: 'order.viewer',
                },
            });
        }

        next();
    },
});

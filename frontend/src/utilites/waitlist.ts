import {Product, ProductPrice} from "../types.ts";

const isOnSaleOrUpcoming = (item: Product | ProductPrice): boolean => !item.is_after_sale_end_date;

export const isAwaitingSaleStart = (product: Product, price?: ProductPrice): boolean =>
    !!product.is_before_sale_start_date || !!price?.is_before_sale_start_date;

export const canJoinWaitlist = (product: Product, price?: ProductPrice): boolean =>
    !!product.waitlist_enabled && isOnSaleOrUpcoming(product) && (!price || isOnSaleOrUpcoming(price));

const openTiers = (product: Product): ProductPrice[] =>
    (product.prices ?? []).filter(price => !price.is_after_sale_end_date);

export const canJoinTieredWaitlist = (product: Product): boolean => {
    const tiers = openTiers(product);

    return !!product.waitlist_enabled
        && !product.is_after_sale_end_date
        && tiers.length > 0
        && !tiers.some(price => product.is_available && price.is_available);
};

export const isTieredProductAwaitingSaleStart = (product: Product): boolean =>
    !!product.is_before_sale_start_date || (openTiers(product).length > 0 && openTiers(product).every(price => price.is_before_sale_start_date));

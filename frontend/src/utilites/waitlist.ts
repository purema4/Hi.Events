import {Product, ProductPrice} from "../types.ts";

const isOnSaleOrUpcoming = (item: Product | ProductPrice): boolean => !item.is_after_sale_end_date;

export const isAwaitingSaleStart = (product: Product, price?: ProductPrice): boolean =>
    !!product.is_before_sale_start_date || !!price?.is_before_sale_start_date;

export const canJoinWaitlist = (product: Product, price?: ProductPrice): boolean =>
    !!product.waitlist_enabled && isOnSaleOrUpcoming(product) && (!price || isOnSaleOrUpcoming(price));

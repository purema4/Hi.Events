import {Event, EventType, IdParam, Product, ProductPrice} from "../../../types.ts";
import {t} from "@lingui/macro";
import {Tooltip} from "@mantine/core";
import {prettyDate, relativeDate} from "../../../utilites/dates.ts";
import {IconInfoCircle} from "@tabler/icons-react";
import {JoinWaitlistButton} from "../JoinWaitlistButton";
import {canJoinWaitlist} from "../../../utilites/waitlist.ts";

const SalesStartMessage = ({saleStartDate, event}: { saleStartDate?: string | Date, event: Event }) => (
    <span>
        {t`Sales start`}{' '}
        <Tooltip label={prettyDate(String(saleStartDate), event.timezone)}>
            <span>{relativeDate(String(saleStartDate))}{' '}<IconInfoCircle size={12}/></span>
        </Tooltip>
    </span>
);

interface ProductPriceSaleDateMessageProps {
    price: ProductPrice;
    event: Event;
    product: Product;
    eventOccurrenceId?: IdParam;
}

const ProductPriceSaleDateMessage = ({price, event, product, eventOccurrenceId}: ProductPriceSaleDateMessageProps) => {
    if (price.is_sold_out) {
        return t`Sold out`;
    }

    if (price.is_after_sale_end_date) {
        return t`Sales ended`;
    }

    if (price.is_before_sale_start_date) {
        return <SalesStartMessage saleStartDate={price.sale_start_date} event={event}/>;
    }

    if (product.is_sold_out || product.is_after_sale_end_date || product.is_before_sale_start_date) {
        return <ProductAvailabilityMessage product={product} event={event} eventOccurrenceId={eventOccurrenceId}/>;
    }

    if (price.is_locked_behind_earlier_tier) {
        return t`Not yet on sale`;
    }

    return t`Not available`;
}

interface ProductAvailabilityMessageProps {
    product: Product;
    event: Event;
    eventOccurrenceId?: IdParam;
}

export const ProductAvailabilityMessage = ({product, event, eventOccurrenceId}: ProductAvailabilityMessageProps) => {
    if (product.is_sold_out) {
        if (canJoinWaitlist(product, product.prices?.[0]) && (event.type !== EventType.RECURRING || eventOccurrenceId) && product.type !== 'TIERED') {
            return <JoinWaitlistButton product={product} event={event} productPriceId={product.prices?.[0]?.id} eventOccurrenceId={eventOccurrenceId}/>;
        }
        return t`Sold out`;
    }
    if (product.is_after_sale_end_date) {
        return t`Sales ended`;
    }
    if (product.is_before_sale_start_date) {
        return (
            <>
                <SalesStartMessage saleStartDate={product.sale_start_date} event={event}/>
                {canJoinWaitlist(product, product.prices?.[0]) && (event.type !== EventType.RECURRING || eventOccurrenceId) && product.type !== 'TIERED' && (
                    <JoinWaitlistButton product={product} event={event} productPriceId={product.prices?.[0]?.id} eventOccurrenceId={eventOccurrenceId}/>
                )}
            </>
        );
    }

    return t`Not available`;
}

interface ProductAndPriceAvailabilityProps {
    product: Product;
    price: ProductPrice;
    event: Event;
    eventOccurrenceId?: IdParam;
}

export const ProductPriceAvailability = ({product, price, event, eventOccurrenceId}: ProductAndPriceAvailabilityProps) => {

    if (product.type === 'TIERED') {
        return <ProductPriceSaleDateMessage price={price} event={event} product={product} eventOccurrenceId={eventOccurrenceId}/>
    }

    return <ProductAvailabilityMessage product={product} event={event} eventOccurrenceId={eventOccurrenceId}/>
}

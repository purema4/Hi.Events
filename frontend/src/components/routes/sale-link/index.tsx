import {t} from "@lingui/macro";
import {useNavigate, useParams} from "react-router";
import {Button, Text} from "@mantine/core";
import {IconAlertCircle, IconTicket} from "@tabler/icons-react";
import {Card} from "../../common/Card";
import {LoadingMask} from "../../common/LoadingMask";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {EventDateRange} from "../../common/EventDateRange";
import {CheckoutContent} from "../../layouts/Checkout/CheckoutContent";
import {useGetSaleLinkPublic} from "../../../queries/useGetSaleLinkPublic.ts";
import {useGetEventPublic} from "../../../queries/useGetEventPublic.ts";
import {useCreateSaleLinkOrderPublic} from "../../../mutations/useCreateSaleLinkOrderPublic.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {prettyDate} from "../../../utilites/dates.ts";
import {showError} from "../../../utilites/notifications.tsx";
import {setCheckoutSessionIdentifier} from "../../../utilites/checkoutSession.ts";
import {eventCheckoutPath} from "../../../utilites/urlHelper.ts";
import {Event, SaleLinkPublic, SaleLinkUnavailableReason} from "../../../types.ts";
import classes from "./SaleLink.module.scss";

const unavailableMessage = (reason: SaleLinkUnavailableReason | null): {heading: string, message: string} => {
    switch (reason) {
        case 'EXPIRED':
            return {
                heading: t`This link has expired`,
                message: t`Contact the event organizer if you'd still like to buy these tickets.`,
            };
        case 'USED':
            return {
                heading: t`This link has already been used`,
                message: t`Every order allowed by this link has been placed. Contact the event organizer if you need more tickets.`,
            };
        default:
            return {
                heading: t`This link is no longer available`,
                message: t`The event organizer has turned this link off. Contact them if you think this is a mistake.`,
            };
    }
};

const firstErrorMessage = (error: any): string => {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (typeof first === 'string') {
            return first;
        }
    }
    return error?.response?.data?.message || t`Something went wrong. Please try again.`;
};

const Message = ({heading, message, event}: {heading: string, message: string, event?: Event}) => (
    <CheckoutContent>
        <div className={classes.container}>
            <div className={classes.header}>
                <IconAlertCircle size={44} className={classes.headerIcon}/>
                <h1>{heading}</h1>
                <p className={classes.subtitle}>{message}</p>
            </div>
        </div>
        <PoweredByFooter brandingRemoved={!!event?.organizer?.branding_removed}/>
    </CheckoutContent>
);

const SaleLinkSummary = ({saleLink, event}: {saleLink: SaleLinkPublic, event?: Event}) => {
    const navigate = useNavigate();
    const createOrderMutation = useCreateSaleLinkOrderPublic();
    const occurrence = saleLink.event_occurrence_id
        ? event?.occurrences?.find(o => Number(o.id) === saleLink.event_occurrence_id)
        : undefined;
    const total = saleLink.items.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    const timezone = event?.timezone;

    const handleContinue = () => {
        createOrderMutation.mutate({eventId: saleLink.event_id, token: saleLink.token}, {
            onSuccess: ({data: order}) => {
                const sessionId = order.session_identifier;
                if (sessionId) {
                    setCheckoutSessionIdentifier(String(order.short_id), sessionId);
                }
                const path = eventCheckoutPath(saleLink.event_id, order.short_id, 'details');
                navigate(sessionId ? `${path}?session_identifier=${encodeURIComponent(sessionId)}` : path);
            },
            onError: (error) => showError(firstErrorMessage(error)),
        });
    };

    return (
        <CheckoutContent>
            <div className={classes.container}>
                <div className={classes.header}>
                    <IconTicket size={44} className={classes.headerIcon}/>
                    <h1>{event?.title}</h1>
                    {event && (
                        <p className={classes.subtitle}>
                            <EventDateRange event={event} occurrence={occurrence}/>
                        </p>
                    )}
                </div>

                <Card>
                    <div className={classes.lines}>
                        {saleLink.items.map((item) => (
                            <div className={classes.line} key={`${item.product_id}-${item.product_price_id}`}>
                                <div className={classes.lineTitle}>
                                    <Text fw={500}>
                                        {item.price_label ? `${item.product_title} (${item.price_label})` : item.product_title}
                                    </Text>
                                    <Text size="sm" c="dimmed">
                                        {`${item.quantity} × ${formatCurrency(item.unit_price, saleLink.currency)}`}
                                    </Text>
                                </div>
                                <Text className={classes.lineTotal}>
                                    {formatCurrency(item.quantity * item.unit_price, saleLink.currency)}
                                </Text>
                            </div>
                        ))}
                    </div>
                    <div className={classes.total}>
                        <span>{t`Total`}</span>
                        <span>{formatCurrency(total, saleLink.currency)}</span>
                    </div>

                    <div className={classes.notes}>
                        <Text size="sm" c="dimmed">
                            {saleLink.price_includes_tax
                                ? t`Prices include tax. Any booking fees are shown at checkout.`
                                : t`Taxes and fees, if any, are added at checkout.`}
                        </Text>
                        {saleLink.expires_at && timezone && (
                            <Text size="sm" c="dimmed">
                                {t`This offer expires ${prettyDate(saleLink.expires_at, timezone)}.`}
                            </Text>
                        )}
                        {saleLink.email && saleLink.email_locked && (
                            <Text size="sm" c="dimmed">
                                {t`This offer is reserved for ${saleLink.email}.`}
                            </Text>
                        )}
                    </div>

                    <Button
                        className={classes.continue}
                        fullWidth
                        size="md"
                        loading={createOrderMutation.isPending}
                        onClick={handleContinue}
                        data-testid="sale-link-continue-button"
                    >
                        {t`Continue to checkout`}
                    </Button>
                </Card>
            </div>
            <PoweredByFooter brandingRemoved={!!event?.organizer?.branding_removed}/>
        </CheckoutContent>
    );
};

const SaleLinkPage = () => {
    const {eventId, token} = useParams();
    const saleLinkQuery = useGetSaleLinkPublic(eventId, token);
    const saleLink = saleLinkQuery.data;
    const eventQuery = useGetEventPublic(eventId, !!saleLink, false, null, saleLink?.event_occurrence_id ?? null);
    const event = eventQuery.data;

    if (saleLinkQuery.isLoading || (saleLink && eventQuery.isLoading)) {
        return <LoadingMask/>;
    }

    if (saleLinkQuery.isError) {
        return saleLinkQuery.error.response?.status === 404
            ? <Message heading={t`This link doesn't exist`} message={t`Check that you copied the whole link, or ask the event organizer to send it again.`}/>
            : <Message heading={t`Something went wrong`} message={t`We couldn't load this link. Please refresh the page to try again.`}/>;
    }

    if (!saleLink) {
        return null;
    }

    if (!saleLink.is_usable) {
        const {heading, message} = unavailableMessage(saleLink.unavailable_reason);
        return <Message heading={heading} message={message} event={event}/>;
    }

    return <SaleLinkSummary saleLink={saleLink} event={event}/>;
};

export default SaleLinkPage;

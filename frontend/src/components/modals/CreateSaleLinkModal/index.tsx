import {useMemo, useState} from "react";
import {t} from "@lingui/macro";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {useClipboard} from "@mantine/hooks";
import {
    ActionIcon,
    Button,
    Input,
    NumberInput,
    SegmentedControl,
    Select,
    Switch,
    Text,
    Textarea,
    TextInput,
} from "@mantine/core";
import {IconCopy, IconPlus, IconTrash} from "@tabler/icons-react";
import {Modal, ModalFooter} from "../../common/Modal";
import {LoadingMask} from "../../common/LoadingMask";
import {InputGroup} from "../../common/InputGroup";
import {DateTimeField} from "../../common/DateTimeField";
import {OccurrenceSelect} from "../../common/OccurrenceSelect";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetAllEventOccurrences} from "../../../queries/useGetAllEventOccurrences.ts";
import {useGetEventPromoCodes} from "../../../queries/useGetEventPromoCodes.ts";
import {useGetAffiliates} from "../../../queries/useGetAffiliates.ts";
import {useCreateSaleLink} from "../../../mutations/useCreateSaleLink.ts";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {getCurrencySymbol} from "../../../utilites/currency.ts";
import {saleLinkUrl} from "../../../utilites/urlHelper.ts";
import {
    CreateSaleLinkRequest,
    GenericModalProps,
    Product,
    ProductPriceType,
    SaleLink,
    SaleLinkPromoMode,
} from "../../../types.ts";
import classes from "./CreateSaleLinkModal.module.scss";

const MAX_ITEMS = 20;

interface SaleLinkItemFormValues {
    key: string;
    product_id: string | null;
    product_price_id: string | null;
    quantity: number | string;
    unit_price: number | string;
}

interface SaleLinkFormValues {
    event_occurrence_id: string | null;
    items: SaleLinkItemFormValues[];
    price_includes_tax: boolean;
    promo_code_id: string | null;
    promo_mode: SaleLinkPromoMode;
    affiliate_id: string | null;
    bypass_capacity: boolean;
    count_toward_cap: boolean;
    max_uses: number | string;
    expires_at: string | null;
    email: string;
    email_locked: boolean;
    note: string;
}

const newItem = (): SaleLinkItemFormValues => ({
    key: Math.random().toString(36).slice(2),
    product_id: null,
    product_price_id: null,
    quantity: 1,
    unit_price: '',
});

const toNullableNumber = (value: string | null): number | null => value ? Number(value) : null;

export const CreateSaleLinkModal = ({onClose}: GenericModalProps) => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const {data: occurrences = []} = useGetAllEventOccurrences(eventId, true);
    const {data: promoCodesData} = useGetEventPromoCodes(eventId, {pageNumber: 1, perPage: 100});
    const {data: affiliatesData} = useGetAffiliates(eventId, {pageNumber: 1, perPage: 100});
    const createMutation = useCreateSaleLink();
    const errorHandler = useFormErrorResponseHandler();
    const clipboard = useClipboard();
    const [createdSaleLink, setCreatedSaleLink] = useState<SaleLink | null>(null);

    const hasMultipleOccurrences = occurrences.length > 1;

    const form = useForm<SaleLinkFormValues>({
        initialValues: {
            event_occurrence_id: null,
            items: [newItem()],
            price_includes_tax: false,
            promo_code_id: null,
            promo_mode: 'ATTRIBUTION_ONLY',
            affiliate_id: null,
            bypass_capacity: true,
            count_toward_cap: true,
            max_uses: 1,
            expires_at: null,
            email: '',
            email_locked: false,
            note: '',
        },
        validate: {
            event_occurrence_id: (value) => hasMultipleOccurrences && !value ? t`Choose a date` : null,
            items: {
                product_id: (value) => !value ? t`Choose a ticket` : null,
                product_price_id: (value) => !value ? t`Choose a tier` : null,
                quantity: (value) => Number(value) >= 1 ? null : t`At least 1`,
                unit_price: (value) => value === '' || Number(value) < 0 ? t`Enter a price` : null,
            },
            max_uses: (value) => Number(value) >= 1 ? null : t`Must be at least 1`,
            email: (value) => value && !/^\S+@\S+\.\S+$/.test(value) ? t`Invalid email format` : null,
            note: (value) => value.length > 1000 ? t`Note must be 1000 characters or fewer` : null,
        },
    });

    const products = useMemo(() => (event?.product_categories ?? [])
        .flatMap(category => category.products ?? [])
        .filter(product => !!product.id), [event?.product_categories]);

    const productOptions = useMemo(() => (event?.product_categories ?? [])
        .map(category => ({
            group: category.name,
            items: (category.products ?? [])
                .filter(product => !!product.id)
                .map(product => ({value: String(product.id), label: product.title})),
        }))
        .filter(group => group.items.length > 0), [event?.product_categories]);

    const promoCodeOptions = (promoCodesData?.data ?? [])
        .filter(promoCode => !!promoCode.id)
        .map(promoCode => ({value: String(promoCode.id), label: promoCode.code}));

    const affiliateOptions = (affiliatesData?.data ?? [])
        .filter(affiliate => affiliate.status === 'ACTIVE')
        .map(affiliate => ({value: String(affiliate.id), label: `${affiliate.name} (${affiliate.code})`}));

    const findProduct = (productId: string | null): Product | undefined =>
        products.find(product => String(product.id) === productId);

    const handleProductChange = (index: number, productId: string | null) => {
        const product = findProduct(productId);
        const prices = product?.prices ?? [];
        const onlyPrice = product?.type !== ProductPriceType.Tiered && prices.length === 1 ? prices[0] : undefined;

        form.setFieldValue(`items.${index}.product_id`, productId);
        form.setFieldValue(`items.${index}.product_price_id`, onlyPrice?.id ? String(onlyPrice.id) : null);
        form.setFieldValue(`items.${index}.unit_price`, onlyPrice ? onlyPrice.price : '');
    };

    const handleTierChange = (index: number, productId: string | null, priceId: string | null) => {
        const price = findProduct(productId)?.prices?.find(p => String(p.id) === priceId);
        form.setFieldValue(`items.${index}.product_price_id`, priceId);
        if (price) {
            form.setFieldValue(`items.${index}.unit_price`, price.price);
        }
    };

    const handleSubmit = (values: SaleLinkFormValues) => {
        const email = values.email.trim();
        const note = values.note.trim();
        const payload: CreateSaleLinkRequest = {
            event_occurrence_id: hasMultipleOccurrences ? toNullableNumber(values.event_occurrence_id) : null,
            items: values.items.map(item => ({
                product_id: Number(item.product_id),
                product_price_id: Number(item.product_price_id),
                quantity: Number(item.quantity),
                unit_price: Number(item.unit_price),
            })),
            price_includes_tax: values.price_includes_tax,
            promo_code_id: toNullableNumber(values.promo_code_id),
            promo_mode: values.promo_code_id ? values.promo_mode : 'ATTRIBUTION_ONLY',
            affiliate_id: toNullableNumber(values.affiliate_id),
            bypass_capacity: values.bypass_capacity,
            count_toward_cap: values.count_toward_cap,
            max_uses: Number(values.max_uses),
            expires_at: values.expires_at ? values.expires_at.replace('T', ' ').slice(0, 16) : null,
            email: email || null,
            email_locked: !!email && values.email_locked,
            note: note || null,
        };

        createMutation.mutate({eventId, saleLinkData: payload}, {
            onSuccess: ({data}) => {
                showSuccess(t`Sale link created`);
                setCreatedSaleLink(data);
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    const copyCreatedLink = () => {
        if (!createdSaleLink) {
            return;
        }
        clipboard.copy(saleLinkUrl(createdSaleLink.event_id, createdSaleLink.token));
        showSuccess(t`Link copied`);
    };

    if (createdSaleLink) {
        return (
            <Modal opened onClose={onClose} heading={t`Sale link created`}>
                <Text size="sm" mb="md">
                    {t`Send this link to the buyer. They will see the tickets and price you set, then pay through the normal checkout.`}
                </Text>
                <div className={classes.linkRow}>
                    <TextInput
                        label={t`Sale link`}
                        value={saleLinkUrl(createdSaleLink.event_id, createdSaleLink.token)}
                        readOnly
                        onFocus={(e) => e.currentTarget.select()}
                    />
                    <Button
                        leftSection={<IconCopy size={14}/>}
                        onClick={copyCreatedLink}
                        data-testid="sale-link-created-copy-button"
                    >
                        {t`Copy link`}
                    </Button>
                </div>
                <ModalFooter>
                    <Button onClick={onClose} data-testid="sale-link-created-done-button">{t`Done`}</Button>
                </ModalFooter>
            </Modal>
        );
    }

    const currencySymbol = event ? getCurrencySymbol(event.currency) : '';

    return (
        <Modal opened onClose={onClose} heading={t`Create Sale Link`} size="xl">
            {!event ? <LoadingMask/> : (
                <form onSubmit={form.onSubmit(handleSubmit)}>
                    {hasMultipleOccurrences && (
                        <OccurrenceSelect
                            occurrences={occurrences}
                            timezone={event.timezone}
                            value={form.values.event_occurrence_id}
                            onChange={(value) => form.setFieldValue('event_occurrence_id', value || null)}
                            label={t`Date`}
                            description={t`The date the tickets are for.`}
                            placeholder={t`Select a date`}
                            error={form.errors.event_occurrence_id}
                            style={{width: '100%', marginBottom: 'var(--mantine-spacing-md)'}}
                        />
                    )}

                    <Text className={classes.itemsHeading}>{t`Tickets`}</Text>
                    {form.values.items.map((item, index) => {
                        const product = findProduct(item.product_id);
                        const isTiered = product?.type === ProductPriceType.Tiered;
                        const tierOptions = (product?.prices ?? [])
                            .filter(price => !!price.id)
                            .map(price => ({value: String(price.id), label: price.label || t`Tier ${price.id}`}));

                        return (
                            <div className={classes.itemRow} key={item.key}>
                                <Select
                                    className={classes.productField}
                                    label={t`Ticket`}
                                    placeholder={t`Choose a ticket`}
                                    data={productOptions}
                                    searchable
                                    value={item.product_id}
                                    onChange={(value) => handleProductChange(index, value)}
                                    error={form.errors[`items.${index}.product_id`]}
                                    data-testid={`sale-link-item-product-${index}`}
                                />
                                <div className={classes.tierField}>
                                    {isTiered && (
                                        <Select
                                            label={t`Tier`}
                                            placeholder={t`Choose a tier`}
                                            data={tierOptions}
                                            value={item.product_price_id}
                                            onChange={(value) => handleTierChange(index, item.product_id, value)}
                                            error={form.errors[`items.${index}.product_price_id`]}
                                            data-testid={`sale-link-item-tier-${index}`}
                                        />
                                    )}
                                    {!isTiered && form.errors[`items.${index}.product_price_id`] && item.product_id && (
                                        <Input.Error>{form.errors[`items.${index}.product_price_id`]}</Input.Error>
                                    )}
                                </div>
                                <NumberInput
                                    label={t`Quantity`}
                                    min={1}
                                    allowDecimal={false}
                                    {...form.getInputProps(`items.${index}.quantity`)}
                                    data-testid={`sale-link-item-quantity-${index}`}
                                />
                                <NumberInput
                                    label={t`Price each`}
                                    min={0}
                                    decimalScale={2}
                                    placeholder="0.00"
                                    rightSection={currencySymbol}
                                    {...form.getInputProps(`items.${index}.unit_price`)}
                                    data-testid={`sale-link-item-price-${index}`}
                                />
                                <ActionIcon
                                    className={classes.removeButton}
                                    variant="subtle"
                                    color="red"
                                    disabled={form.values.items.length === 1}
                                    onClick={() => form.removeListItem('items', index)}
                                    aria-label={t`Remove ticket`}
                                    data-testid={`sale-link-item-remove-${index}`}
                                >
                                    <IconTrash size={16}/>
                                </ActionIcon>
                            </div>
                        );
                    })}
                    {typeof form.errors.items === 'string' && <Input.Error>{form.errors.items}</Input.Error>}
                    <Button
                        className={classes.addItem}
                        variant="subtle"
                        size="xs"
                        leftSection={<IconPlus size={14}/>}
                        disabled={form.values.items.length >= MAX_ITEMS}
                        onClick={() => form.insertListItem('items', newItem())}
                        data-testid="sale-link-add-item-button"
                    >
                        {t`Add another ticket`}
                    </Button>

                    <Switch
                        label={t`Price includes tax`}
                        description={form.values.price_includes_tax
                            ? t`The price you entered is the final price. Tax is taken out of it, not added on top.`
                            : t`Tax is added on top of the price you entered at checkout.`}
                        {...form.getInputProps('price_includes_tax', {type: 'checkbox'})}
                    />

                    <Switch
                        label={t`Ignore ticket cap`}
                        description={t`Sell these tickets even if the ticket is sold out or its limit has been reached.`}
                        {...form.getInputProps('bypass_capacity', {type: 'checkbox'})}
                    />

                    <Switch
                        label={t`Count toward public cap`}
                        description={form.values.count_toward_cap
                            ? t`Tickets sold through this link reduce the number left for everyone else.`
                            : t`Tickets sold through this link don't reduce the number left for everyone else.`}
                        {...form.getInputProps('count_toward_cap', {type: 'checkbox'})}
                    />

                    <InputGroup>
                        <Select
                            label={t`Promo code`}
                            description={t`Optional. Attach a promo code to this sale.`}
                            placeholder={t`None`}
                            data={promoCodeOptions}
                            clearable
                            searchable
                            {...form.getInputProps('promo_code_id')}
                        />
                        <Select
                            label={t`Affiliate`}
                            description={t`Optional. Credit this sale to an active affiliate.`}
                            placeholder={t`None`}
                            data={affiliateOptions}
                            clearable
                            searchable
                            {...form.getInputProps('affiliate_id')}
                        />
                    </InputGroup>

                    {form.values.promo_code_id && (
                        <Input.Wrapper
                            className={classes.section}
                            mb="md"
                            label={t`How should the promo code be used?`}
                            description={form.values.promo_mode === 'APPLY_DISCOUNT'
                                ? t`The promo code's discount is taken off the price you set.`
                                : t`The sale is recorded against the promo code, but the price you set is not discounted.`}
                        >
                            <SegmentedControl
                                fullWidth
                                mt={4}
                                value={form.values.promo_mode}
                                onChange={(value) => form.setFieldValue('promo_mode', value as SaleLinkPromoMode)}
                                data={[
                                    {value: 'ATTRIBUTION_ONLY', label: t`Track only`},
                                    {value: 'APPLY_DISCOUNT', label: t`Apply discount`},
                                ]}
                                data-testid="sale-link-promo-mode"
                            />
                        </Input.Wrapper>
                    )}

                    <InputGroup>
                        <NumberInput
                            label={t`Max uses`}
                            description={t`How many orders can be placed with this link.`}
                            min={1}
                            allowDecimal={false}
                            {...form.getInputProps('max_uses')}
                        />
                        <DateTimeField
                            {...form.getInputProps('expires_at')}
                            label={t`Expires at`}
                            description={t`Optional. Leave empty for no expiry.`}
                            defaultTime="23:59"
                        />
                    </InputGroup>

                    <TextInput
                        label={t`Buyer email`}
                        description={t`Optional. Pre-fills the email at checkout.`}
                        placeholder="buyer@example.com"
                        type="email"
                        {...form.getInputProps('email')}
                    />

                    {form.values.email.trim() && (
                        <Switch
                            label={t`Lock email`}
                            description={t`The buyer can't change the email at checkout.`}
                            {...form.getInputProps('email_locked', {type: 'checkbox'})}
                        />
                    )}

                    <Textarea
                        label={t`Internal note`}
                        description={t`Only visible to your team.`}
                        autosize
                        minRows={2}
                        maxLength={1000}
                        {...form.getInputProps('note')}
                    />

                    <ModalFooter>
                        <Button variant="default" onClick={onClose}>{t`Cancel`}</Button>
                        <Button
                            type="submit"
                            loading={createMutation.isPending}
                            data-testid="sale-link-submit-button"
                        >
                            {t`Create Sale Link`}
                        </Button>
                    </ModalFooter>
                </form>
            )}
        </Modal>
    );
};
